/**
 * The in-browser Basque engine: phoneme mapping, WAV packaging, backend choice
 * and what happens when the runtime or the voice cannot be loaded.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {loadSpeech, tick} from './helpers.mjs';

/**
 * Read a jsdom Blob, which has no arrayBuffer().
 *
 * @param {Window} window
 * @param {Blob} blob
 * @return {Promise<DataView>}
 */
const readBlob = (window, blob) => new Promise((resolve, reject) => {
    const reader = new window.FileReader();
    reader.onload = () => resolve(new DataView(reader.result));
    reader.onerror = reject;
    reader.readAsArrayBuffer(blob);
});

const VOICE = {
    audio: {sample_rate: 22050},
    espeak: {voice: 'eu'},
    inference: {noise_scale: 0.667, length_scale: 1, noise_w: 0.8},
    speaker_id_map: {},
    phoneme_id_map: {
        '_': [0], '^': [1], '$': [2], ' ': [3],
        'a': [10], 'b': [11], 'i': [12], 'o': [13], 'ɾ': [14], 'ˈ': [15],
    },
};

const CONFIG = {
    modelurl: 'https://moodle.example.org/voices/eu-antton-medium.onnx',
    orturl: 'https://moodle.example.org/ort/ort.webgpu.min.js',
    wasmpath: 'https://moodle.example.org/ort/',
    phonemizerurl: 'https://moodle.example.org/phonemizer.js',
    loaderurl: 'https://moodle.example.org/blocks/ahotts_embhl/js/esm-bridge.js',
};

/**
 * A stand-in for ONNX Runtime Web that records how it was driven.
 *
 * @param {Object} options {failOn, output}
 * @return {Object}
 */
const fakeOrt = (options = {}) => {
    const calls = {sessions: [], feeds: null, wasmPaths: null, runs: 0, overlapped: false};
    const ort = {
        env: {wasm: {}},
        Tensor: class {
            constructor(type, data, dims) {
                this.type = type;
                this.data = data;
                this.dims = dims;
            }
        },
        InferenceSession: {
            create: async (model, sessionoptions) => {
                calls.sessions.push([...sessionoptions.executionProviders]);
                if (options.failOn && options.failOn.includes(sessionoptions.executionProviders[0])) {
                    throw new Error('provider unavailable');
                }
                return {
                    run: async (feeds) => {
                        calls.runs += 1;
                        if (calls.running) {
                            calls.overlapped = true;
                        }
                        calls.running = true;
                        await new Promise((resolve) => setTimeout(resolve, 5));
                        calls.running = false;
                        calls.feeds = feeds;
                        return {output: {data: options.output || Float32Array.from([0, 0.5, -0.5, 1])}};
                    },
                };
            },
        },
    };
    Object.defineProperty(ort.env.wasm, 'wasmPaths', {
        configurable: true,
        get: () => calls.wasmPaths,
        set: (value) => {
            calls.wasmPaths = value;
        },
    });
    return {ort, calls};
};

/**
 * Build an engine with every external dependency replaced.
 *
 * @param {Object} options
 * @return {Object}
 */
const makeEngine = (options = {}) => {
    const speech = loadSpeech('<!doctype html><html><body></body></html>');
    const {window} = speech;
    window.URL.createObjectURL = (blob) => {
        window.__lastBlob = blob;
        return 'blob:fake';
    };
    window.URL.revokeObjectURL = () => {};

    const {ort, calls} = fakeOrt(options.ort || {});
    const phonemized = [];
    const deps = {
        loadScript: options.scriptFails
            ? () => Promise.reject(new Error('offline'))
            : () => Promise.resolve(),
        loadModule: () => Promise.resolve(options.namespace || {
            phonemize: (text, language) => {
                phonemized.push({text, language});
                if (options.phonemizerFailsOn === language) {
                    return Promise.reject(new Error('eSpeak NG cannot speak "' + language + '"'));
                }
                const phonemes = options.phonemes === undefined ? 'abiˈaɾ' : options.phonemes;
                return Promise.resolve(Array.isArray(phonemes) ? phonemes : [phonemes]);
            },
        }),
        fetchJson: () => Promise.resolve(options.voice || VOICE),
        fetchBinary: () => Promise.resolve(new ArrayBuffer(8)),
        getOrt: () => (options.noOrt ? null : ort),
        hasWebGpu: () => options.webgpu !== false,
    };

    const engine = speech.piper.createPiperEngine(
        Object.assign({}, CONFIG, options.config || {}),
        deps
    );
    return {speech, window, engine, calls, phonemized};
};

test('maps phonemes the way Piper does: BOS, padding between phonemes, EOS', () => {
    const speech = loadSpeech('<!doctype html><html><body></body></html>');
    const ids = speech.piper.phonemesToIds('ab', VOICE.phoneme_id_map);

    assert.deepEqual([...ids], [1, 0, 10, 0, 11, 0, 2]);
});

test('skips phonemes the voice does not know instead of guessing', () => {
    const speech = loadSpeech('<!doctype html><html><body></body></html>');
    const ids = speech.piper.phonemesToIds('aXb', VOICE.phoneme_id_map);

    assert.deepEqual([...ids], [1, 0, 10, 0, 11, 0, 2]);
});

test('packs the waveform into a mono 16 bit WAV', async () => {
    const speech = loadSpeech('<!doctype html><html><body></body></html>');
    const blob = speech.piper.encodeWav(Float32Array.from([0, 1, -1, 0.5]), 22050);
    const view = await readBlob(speech.window, blob);
    const text = (offset) => String.fromCharCode(
        view.getUint8(offset), view.getUint8(offset + 1), view.getUint8(offset + 2), view.getUint8(offset + 3)
    );

    assert.equal(text(0), 'RIFF');
    assert.equal(text(8), 'WAVE');
    assert.equal(view.getUint16(22, true), 1, 'mono');
    assert.equal(view.getUint32(24, true), 22050, 'sample rate');
    assert.equal(view.getUint16(34, true), 16, 'bits per sample');
    assert.equal(text(36), 'data');
    assert.equal(view.getUint32(40, true), 8, 'four samples, two bytes each');
    assert.equal(view.getInt16(44, true), 0);
    assert.equal(view.getInt16(46, true), 32767, 'the loudest sample is not wrapped');
    assert.equal(view.getInt16(48, true), -32768);
});

test('prefers WebGPU and keeps WebAssembly behind it', async () => {
    const ctx = makeEngine();
    assert.equal(await ctx.engine.warmup(), true);

    assert.deepEqual(ctx.calls.sessions, [['webgpu', 'wasm']]);
    assert.equal(ctx.calls.wasmPaths, CONFIG.wasmpath, 'the runtime is told where its wasm lives');
});

test('uses WebAssembly when the browser has no WebGPU', async () => {
    const ctx = makeEngine({webgpu: false});
    await ctx.engine.warmup();

    assert.deepEqual(ctx.calls.sessions, [['wasm']]);
});

test('uses WebAssembly when the administrator asked for it', async () => {
    const ctx = makeEngine({config: {backend: 'wasm'}});
    await ctx.engine.warmup();

    assert.deepEqual(ctx.calls.sessions, [['wasm']]);
});

test('retries on WebAssembly when WebGPU refuses the model', async () => {
    const ctx = makeEngine({ort: {failOn: ['webgpu']}});

    assert.equal(await ctx.engine.warmup(), true);
    assert.deepEqual(ctx.calls.sessions, [['webgpu', 'wasm'], ['wasm']]);
});

test('reports failure instead of throwing when the runtime cannot be loaded', async () => {
    const ctx = makeEngine({scriptFails: true});

    assert.equal(await ctx.engine.warmup(), false);
    assert.equal(await ctx.engine.warmup(), false, 'and it does not retry forever');
});

test('reports failure when the runtime does not register itself', async () => {
    const ctx = makeEngine({noOrt: true});

    assert.equal(await ctx.engine.warmup(), false);
});

test('warms up only once, however many chunks are read', async () => {
    const ctx = makeEngine();
    await Promise.all([ctx.engine.warmup(), ctx.engine.warmup(), ctx.engine.warmup()]);

    assert.equal(ctx.calls.sessions.length, 1);
});

test('feeds the model the phoneme ids, the length and the inference scales', async () => {
    const ctx = makeEngine({phonemes: 'ab'});
    await ctx.engine.warmup();

    const url = await ctx.engine.prepare('Kaixo');
    assert.equal(url, 'blob:fake');

    assert.deepEqual(ctx.phonemized, [
        {text: 'kaixo', language: 'eu'},
        {text: 'Kaixo', language: 'eu'},
    ], 'one word is phonemized while the engine starts, then the chunk');

    const feeds = ctx.calls.feeds;
    assert.equal(feeds.input.type, 'int64');
    assert.deepEqual([...feeds.input.dims], [1, 7]);
    assert.deepEqual([...feeds.input.data].map(Number), [1, 0, 10, 0, 11, 0, 2]);
    assert.deepEqual([...feeds.input_lengths.data].map(Number), [7]);
    // The scales travel as float32, so compare them at that precision.
    assert.deepEqual([...feeds.scales.data], [...Float32Array.from([0.667, 1, 0.8])]);
    assert.equal(feeds.sid, undefined, 'a single speaker voice needs no speaker id');
});

test('keeps a short silence between clauses produced for a comma', async () => {
    const ctx = makeEngine({phonemes: ['ab', 'ba']});
    await ctx.engine.warmup();
    await ctx.engine.prepare('Kaixo, lagun');

    assert.equal(ctx.calls.runs, 2, 'each side of the comma is synthesised as its own clause');
    const view = await readBlob(ctx.window, ctx.window.__lastBlob);
    const pausesamples = Math.round(VOICE.audio.sample_rate * 0.18);
    assert.equal(view.getUint32(40, true), (8 + pausesamples) * 2, 'the WAV contains both clauses and the pause');
    assert.equal(view.getInt16(44 + (4 * 2), true), 0, 'the inserted pause is silent');
    assert.equal(view.getInt16(44 + ((4 + pausesamples + 1) * 2), true), 16383, 'audio resumes after the pause');
});

test('passes a speaker id for a multi speaker voice', async () => {
    const voice = Object.assign({}, VOICE, {speaker_id_map: {default: 0}});
    const ctx = makeEngine({voice, phonemes: 'ab'});
    await ctx.engine.warmup();
    await ctx.engine.prepare('Kaixo');

    assert.deepEqual([...ctx.calls.feeds.sid.data].map(Number), [0]);
});

test('runs one inference at a time, so two chunks never fight over the GPU', async () => {
    const ctx = makeEngine({phonemes: 'ab'});
    await ctx.engine.warmup();

    await Promise.all([
        ctx.engine.prepare('Bat'),
        ctx.engine.prepare('Bi'),
        ctx.engine.prepare('Hiru'),
    ]);

    assert.equal(ctx.calls.runs, 3, 'every chunk went through the model');
    assert.equal(ctx.calls.overlapped, false, 'but never two at the same time');
});

test('a chunk the voice has no phonemes for is rejected, not swallowed', async () => {
    const empty = makeEngine({phonemes: ''});
    await empty.engine.warmup();

    await assert.rejects(empty.engine.prepare('   '), /Nothing to synthesise/);

    // The queue survives it: the engine keeps working for the next chunks.
    const ctx = makeEngine({phonemes: 'ab'});
    await ctx.engine.warmup();
    assert.equal(await ctx.engine.prepare('Kaixo'), 'blob:fake');
    assert.equal(await ctx.engine.prepare('Berriro'), 'blob:fake');
});

test('reports whether the browser exposes WebGPU', () => {
    const speech = loadSpeech('<!doctype html><html><body></body></html>');
    assert.equal(speech.piper.hasWebGpu(), false, 'jsdom has no WebGPU');
});

test('waits for the engine before speaking', async () => {
    const ctx = makeEngine();
    await tick(1);
    assert.equal(typeof ctx.engine.prepare, 'function');
    assert.equal(typeof ctx.engine.play, 'function');
    assert.equal(typeof ctx.engine.stop, 'function');
});

test('refuses to start when the phonemizer cannot speak the voice\'s language', async () => {
    // An eSpeak build carrying only English used to start cleanly here and then
    // fail on every chunk, so the learner heard nothing and was told nothing.
    const ctx = makeEngine({phonemizerFailsOn: 'eu'});

    assert.equal(await ctx.engine.warmup(), false);
    assert.equal(ctx.calls.sessions.length, 0, 'and the voice is not downloaded for nothing');
});

test('refuses to start when the phonemizer module is of no known shape', async () => {
    const ctx = makeEngine({namespace: {something: 'else'}});

    assert.equal(await ctx.engine.warmup(), false);
});

test('uses a module exporting phonemize() as it is', () => {
    const speech = loadSpeech('<!doctype html><html><body></body></html>');
    const phonemize = () => {};

    assert.equal(speech.piper.resolvePhonemizer({phonemize}, () => {}, ''), phonemize);
});

test('drives a full eSpeak NG build as a command line program', async () => {
    const speech = loadSpeech('<!doctype html><html><body></body></html>');
    const runs = [];
    const factory = (options) => {
        runs.push(options);
        return Promise.resolve({
            FS: {readFile: () => 'kˈaɪʃo\nˈaʊ eʊs̺kˈaɾa'},
        });
    };

    const phonemize = speech.piper.resolvePhonemizer({default: factory}, () => {}, '');
    const phonemes = await phonemize('Kaixo, hau euskara', 'eu');

    assert.deepEqual([...phonemes], ['kˈaɪʃo', 'ˈaʊ eʊs̺kˈaɾa'], 'one entry per clause');
    assert.deepEqual([...runs[0].arguments], [
        '--phonout', 'phonemes',
        '--sep=',
        '-q',
        '--ipa',
        '-v', 'eu',
        'Kaixo, hau euskara',
    ], 'plain IPA with no separators is what Piper voices are trained on');
    assert.ok(
        !runs[0].arguments.some((argument) => String(argument).startsWith('-b')),
        'no input encoding is forced: any -b makes eSpeak spell out accented characters'
    );
});

test('downloads the eSpeak WebAssembly once and reuses it for every sentence', async () => {
    const speech = loadSpeech('<!doctype html><html><body></body></html>');
    const binary = new ArrayBuffer(8);
    const fetched = [];
    const runs = [];
    const factory = (options) => {
        runs.push(options);
        return Promise.resolve({FS: {readFile: () => 'a'}});
    };
    const fetchBinary = (url) => {
        fetched.push(url);
        return Promise.resolve(binary);
    };

    const phonemize = speech.piper.resolvePhonemizer(
        {default: factory},
        fetchBinary,
        'https://moodle.example.org/espeak-ng.wasm'
    );
    await phonemize('Bat', 'eu');
    await phonemize('Bi', 'eu');

    assert.deepEqual(fetched, ['https://moodle.example.org/espeak-ng.wasm']);
    assert.equal(runs.length, 2);
    assert.equal(runs[0].wasmBinary, binary);
    assert.equal(runs[1].wasmBinary, binary);
});

test('reports what eSpeak complained about when a language is missing', async () => {
    const speech = loadSpeech('<!doctype html><html><body></body></html>');
    const factory = (options) => {
        options.printErr('Invalid language identifier: "eu"');
        return Promise.resolve({
            FS: {
                readFile: () => {
                    throw new Error('ENOENT');
                },
            },
        });
    };

    const phonemize = speech.piper.espeakPhonemizer(factory, () => {}, '');

    await assert.rejects(
        () => phonemize('Kaixo', 'eu'),
        /eSpeak NG cannot speak "eu".*Invalid language identifier/
    );
});
