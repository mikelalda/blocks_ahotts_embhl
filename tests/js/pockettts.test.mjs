/**
 * The Pocket TTS engine: Basque text normalisation, the pieces of the in-worker
 * pipeline that need no model, the page-side engine driven by fake workers and
 * a fake audio clock, and how speech.js puts it ahead of Piper.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {loadSpeech, tick} from './helpers.mjs';
import {cardinal, ordinal, normalizeBasque} from '../../js/pockettts/normalize-eu.js';
import {
    UnigramTokenizer, makeCacheDynamic, parseSafetensors, prepareText, splitSentences,
} from '../../js/pockettts/engine.js';

// ---------------------------------------------------------------------------
// Normalisation.

test('spells out cardinals in the vigesimal system, with a single "eta"', () => {
    const cases = {
        0: 'zero', 11: 'hamaika', 19: 'hemeretzi', 20: 'hogei', 21: 'hogeita bat', 39: 'hogeita hemeretzi',
        40: 'berrogei', 77: 'hirurogeita hamazazpi', 99: 'laurogeita hemeretzi', 100: 'ehun',
        101: 'ehun eta bat', 345: 'hirurehun eta berrogeita bost', 1000: 'mila', 1200: 'mila eta berrehun',
        1234: 'mila berrehun eta hogeita hamalau', 1990: 'mila bederatziehun eta laurogeita hamar',
        2024: 'bi mila eta hogeita lau', 21000: 'hogeita bat mila',
        250300: 'berrehun eta berrogeita hamar mila eta hirurehun', 1000000: 'milioi bat',
        1500000: 'milioi bat eta bostehun mila', 2000001: 'bi milioi eta bat',
    };
    Object.entries(cases).forEach(([n, words]) => assert.equal(cardinal(n), words, n));
});

test('spells out ordinals', () => {
    assert.equal(ordinal(1), 'lehen');
    assert.equal(ordinal(3), 'hirugarren');
    assert.equal(ordinal(5), 'bosgarren');
    assert.equal(ordinal(21), 'hogeita batgarren');
    assert.equal(ordinal(25), 'hogeita bosgarren');
});

test('normalises numbers, dates, times, units and abbreviations in running text', () => {
    const cases = [
        ['2024an hasi zen.', 'bi mila eta hogeita lauan hasi zen.'],
        ['1990eko udan.', 'mila bederatziehun eta laurogeita hamarreko udan.'],
        ['Urriaren 10ean eta 3an.', 'Urriaren hamarrean eta hiruan.'],
        ['2000n jaio zen.', 'bi milan jaio zen.'],
        ['Data: 19/10/2024.', 'Data: bi mila eta hogeita lauko urriaren hemeretzia.'],
        ['2001-03-01', 'bi mila eta bateko martxoaren bata'],
        ['Bilera 10:30ean da.', 'Bilera hamarrak eta erdietan da.'],
        ['Klasea 15:00etan.', 'Klasea hiruretan.'],
        ['13:00etan eta 10:00etatik 12:00etara.', 'ordubatean eta hamarretatik hamabietara.'],
        ['Orain 4:00 dira.', 'Orain laurak dira.'],
        ['3. mailan gaude.', 'hirugarren mailan gaude.'],
        ['XX. mendean.', 'hogeigarren mendean.'],
        ['Ikasleen %25 etorri zen.', 'Ikasleen ehuneko hogeita bost etorri zen.'],
        ['%25eko jaitsiera.', 'ehuneko hogeita bosteko jaitsiera.'],
        ['Prezioa 1€ da.', 'Prezioa euro bat da.'],
        ['1,5 km egin genituen.', 'bat koma bost kilometro egin genituen.'],
        ['25 km-ko ibilbidea.', 'hogeita bost kilometroko ibilbidea.'],
        ['3 mendi igo genituen.', 'hiru mendi igo genituen.'],
        ['Tenperatura -5 ºC da.', 'Tenperatura minus bost gradu da.'],
        ['1.000 ikasle eta 25.000.000 euro.', 'mila ikasle eta hogeita bost milioi euro.'],
        ['3,25 eta 0,05.', 'hiru koma hogeita bost eta zero koma zero bost.'],
        ['2024-2025 ikasturtea', 'bi mila eta hogeita lau, bi mila eta hogeita bost ikasturtea'],
        ['Kodea 007 da.', 'Kodea zero zero zazpi da.'],
        ['Liburuak, koadernoak, etab.', 'Liburuak, koadernoak, eta abar'],
        ['K.a. 200. urtean.', 'Kristo aurretik berrehungarren urtean.'],
        ['Testu honetan ez dago zenbakirik.', 'Testu honetan ez dago zenbakirik.'],
    ];
    cases.forEach(([input, expected]) => assert.equal(normalizeBasque(input), expected, input));
});

// ---------------------------------------------------------------------------
// Pipeline pieces that need no model.

const VOCAB = {'<unk>': 0, '<s>': 1, '</s>': 2, '<0x21>': 3, '▁': 4, '▁ka': 5, 'ixo': 6, 'i': 7,
    'x': 8, 'o': 9, '▁kaixo': 10, '.': 11, '▁da': 12, '▁Kaixo': 13};
const SCORES = {'▁': -5, '▁ka': -3, 'ixo': -3, 'i': -6, 'x': -6, 'o': -6, '▁kaixo': -4,
    '.': -2, '▁da': -3, '▁Kaixo': -4};

test('the tokenizer picks the most probable pieces and falls back to bytes', () => {
    const tokenizer = new UnigramTokenizer(VOCAB, SCORES);
    assert.deepEqual(tokenizer.encode('kaixo'), [10], 'one piece beats two');
    assert.deepEqual(tokenizer.encode('kaixo!'), [10, 3], 'an unknown character becomes its UTF-8 bytes');
    assert.deepEqual(tokenizer.encode('kaixo da.'), [10, 12, 11]);
    assert.equal(tokenizer.decode([10, 3, 12, 11]), 'kaixo! da.');
});

test('the text is prepared as the reference runtime does', () => {
    assert.deepEqual(prepareText('kaixo'), {text: 'Kaixo.', framesAfterEos: 5});
    assert.deepEqual(prepareText('hau esaldi luze samarra da benetan!'),
        {text: 'Hau esaldi luze samarra da benetan!', framesAfterEos: 3});
    assert.throws(() => prepareText('   '));
});

test('long text is cut into chunks at sentence ends', () => {
    const tokenizer = new UnigramTokenizer(VOCAB, SCORES);
    assert.deepEqual(splitSentences(tokenizer, 'kaixo. da. kaixo.', 2), ['Kaixo.', 'da.', 'kaixo.']);
    assert.deepEqual(splitSentences(tokenizer, 'kaixo. da.', 50), ['Kaixo. da.']);
});

test('the KV cache length is made dynamic without changing the model size', () => {
    const declared = [0x0a, 2, 8, 2, 0x0a, 2, 8, 1, 0x0a, 3, 8, 0xe8, 0x07, 0x0a, 2, 8, 16, 0x0a, 2, 8, 64];
    const model = new Uint8Array([9, 9, ...declared, 7, ...declared, 5]);
    const {bytes, hits} = makeCacheDynamic(model.buffer.slice(0));
    assert.equal(hits, 2);
    assert.equal(bytes.length, model.length);
    const dimension = [...bytes.slice(2 + 8, 2 + 13)];
    assert.deepEqual(dimension, [0x0a, 3, 0x1a, 1, 0x63], 'dim_value 1000 became an unnamed dimension');
    assert.equal(makeCacheDynamic(new Uint8Array([1, 2, 3]).buffer).hits, 0);
});

test('voice states are read from safetensors', () => {
    const header = JSON.stringify({
        'layer/cache': {dtype: 'F32', shape: [2], data_offsets: [0, 8]},
        'layer/offset': {dtype: 'I64', shape: [1], data_offsets: [8, 16]},
    });
    const headerBytes = new TextEncoder().encode(header);
    const buffer = new ArrayBuffer(8 + headerBytes.length + 16);
    const view = new DataView(buffer);
    view.setBigUint64(0, BigInt(headerBytes.length), true);
    new Uint8Array(buffer, 8).set(headerBytes);
    const data = 8 + headerBytes.length;
    view.setFloat32(data, 1.5, true);
    view.setFloat32(data + 4, -2, true);
    view.setBigInt64(data + 8, 82n, true);

    const tensors = parseSafetensors(buffer);
    assert.deepEqual([...tensors['layer/cache'].data], [1.5, -2]);
    assert.equal(tensors['layer/offset'].data[0], 82n);
});

// ---------------------------------------------------------------------------
// The page-side engine, with fake workers and a fake audio clock.

/**
 * Workers that answer the way generator.js and decoder.js do, under the test's control.
 *
 * @return {Object}
 */
const fakeWorkers = () => {
    const made = [];
    class FakeWorker {
        constructor(url, options) {
            this.url = url;
            this.options = options;
            this.sent = [];
            this.listeners = {message: [], error: []};
            this.terminated = false;
            made.push(this);
        }
        addEventListener(type, fn) {
            this.listeners[type].push(fn);
        }
        postMessage(message) {
            this.sent.push(message);
        }
        terminate() {
            this.terminated = true;
        }
        emit(data) {
            this.listeners.message.forEach((fn) => fn({data}));
        }
        fail(message) {
            this.listeners.error.forEach((fn) => fn({message, preventDefault() {}}));
        }
    }
    class FakeChannel {
        constructor() {
            this.port1 = {};
            this.port2 = {};
        }
    }
    return {Worker: FakeWorker, MessageChannel: FakeChannel, made};
};

/**
 * An audio context whose clock the test moves by hand.
 *
 * @return {Object}
 */
const fakeContext = () => {
    const context = {
        currentTime: 0,
        state: 'running',
        sources: [],
        destination: {},
        createBuffer: (channels, length, rate) => {
            const data = new Float32Array(length);
            return {length, sampleRate: rate, getChannelData: () => data};
        },
        createBufferSource: () => {
            const source = {
                connect() {},
                start(when) {
                    source.when = when;
                },
                stop() {
                    source.stopped = true;
                },
                onended: null,
            };
            context.sources.push(source);
            return source;
        },
        suspend: () => {
            context.state = 'suspended';
            return Promise.resolve();
        },
        resume: () => {
            context.state = 'running';
            return Promise.resolve();
        },
    };
    return context;
};

const CONFIG = {
    baseurl: 'https://models.example.org/eu/',
    voiceurl: 'https://models.example.org/eu/voices/Antton.safetensors',
    orturl: 'https://cdn.example.org/ort.wasm.min.mjs',
    wasmpath: 'https://cdn.example.org/',
    generatorurl: 'https://moodle.example.org/blocks/ahotts_embhl/js/pockettts/generator.js',
    decoderurl: 'https://moodle.example.org/blocks/ahotts_embhl/js/pockettts/decoder.js',
    language: 'eu',
};

/**
 * An engine whose workers have started.
 *
 * @param {Object} [options] {context}
 * @return {Promise<Object>}
 */
const startedEngine = async (options = {}) => {
    const {pockettts} = loadSpeech('<p></p>');
    const workers = fakeWorkers();
    const context = options.context || fakeContext();
    const engine = pockettts.createPocketTtsEngine(CONFIG, {
        Worker: workers.Worker,
        MessageChannel: workers.MessageChannel,
        context: () => context,
        supported: () => true,
    });
    const warm = engine.warmup();
    const [generator, decoder] = workers.made;
    generator.emit({type: 'ready'});
    decoder.emit({type: 'ready'});
    assert.equal(await warm, true);
    return {engine, generator, decoder, context, workers};
};

const speakMessages = (worker) => worker.sent.filter((message) => message.type === 'speak');

test('warmup starts two module workers and reports success once both are ready', async () => {
    const {generator, decoder} = await startedEngine();
    assert.equal(generator.url, CONFIG.generatorurl);
    assert.equal(decoder.url, CONFIG.decoderurl);
    assert.equal(generator.options.type, 'module');
    assert.equal(generator.sent[0].type, 'init');
    assert.equal(generator.sent[0].baseurl, CONFIG.baseurl);
    assert.equal(generator.sent[0].orturl, CONFIG.orturl);
});

test('warmup fails, and stays failed, when a worker cannot load the model', async () => {
    const {pockettts} = loadSpeech('<p></p>');
    const workers = fakeWorkers();
    const engine = pockettts.createPocketTtsEngine(CONFIG, {
        Worker: workers.Worker, MessageChannel: workers.MessageChannel, context: fakeContext, supported: () => true,
    });
    const warm = engine.warmup();
    workers.made[0].emit({type: 'initerror', message: 'out of memory'});
    assert.equal(await warm, false);
    assert.ok(workers.made.every((worker) => worker.terminated));
    assert.equal(await engine.warmup(), false);
});

test('warmup fails at once in a browser that cannot run it', async () => {
    const {pockettts} = loadSpeech('<p></p>');
    const engine = pockettts.createPocketTtsEngine(CONFIG, {supported: () => false});
    assert.equal(await engine.warmup(), false);
});

test('warmup reports how much of the model has been downloaded', async () => {
    const {pockettts} = loadSpeech('<p></p>');
    const workers = fakeWorkers();
    const engine = pockettts.createPocketTtsEngine(CONFIG, {
        Worker: workers.Worker, MessageChannel: workers.MessageChannel, context: fakeContext, supported: () => true,
    });
    const seen = [];
    const warm = engine.warmup((fraction) => seen.push(fraction));
    workers.made[0].emit({type: 'progress', file: 'flow_lm_main_int8.onnx', loaded: 62.75e6, total: 76e6});
    workers.made[1].emit({type: 'progress', file: 'mimi_decoder_int8.onnx', loaded: 62.75e6, total: 23e6});
    workers.made[0].emit({type: 'ready'});
    workers.made[1].emit({type: 'ready'});
    await warm;
    assert.equal(seen.length, 2);
    assert.ok(seen[0] > 0.45 && seen[0] < 0.55, 'half the model');
    assert.equal(seen[1], 1);
});

test('a fragment plays as it is decoded and ends when its last audio has played', async () => {
    const {engine, generator, decoder, context} = await startedEngine();

    let prepared = false;
    const token = engine.prepare('Kaixo 2024an.').then((value) => {
        prepared = true;
        return value;
    });
    const speak = speakMessages(generator)[0];
    assert.equal(speak.text, 'Kaixo 2024an.');
    assert.equal(speak.language, 'eu', 'the worker normalises Basque text');
    assert.equal(speak.voiceurl, CONFIG.voiceurl);
    await tick(5);
    assert.equal(prepared, false, 'nothing to play before the first audio');

    decoder.emit({type: 'audio', id: speak.id, samples: new Float32Array(24000)});
    let ended = false;
    const playing = engine.play(await token).then(() => {
        ended = true;
    });
    assert.equal(context.sources.length, 1, 'the first second is scheduled at once');

    decoder.emit({type: 'audio', id: speak.id, samples: new Float32Array(12000)});
    assert.equal(context.sources.length, 2);
    assert.equal(context.sources[1].when, context.sources[0].when + 1, 'back to back, no gap');

    generator.emit({type: 'gendone', id: speak.id});
    decoder.emit({type: 'decdone', id: speak.id});
    await tick(5);
    assert.equal(ended, false, 'still sounding');
    context.sources[1].onended();
    await playing;
    assert.equal(ended, true);
});

test('fragments are synthesised one at a time, in order', async () => {
    const {engine, generator, decoder} = await startedEngine();

    engine.prepare('Lehena.');
    engine.prepare('Bigarrena.');
    assert.equal(speakMessages(generator).length, 1, 'the second waits for the first');

    const first = speakMessages(generator)[0];
    decoder.emit({type: 'audio', id: first.id, samples: new Float32Array(10)});
    generator.emit({type: 'gendone', id: first.id});
    assert.equal(speakMessages(generator).length, 2);
    assert.equal(speakMessages(generator)[1].text, 'Bigarrena.');
});

test('stop silences the fragment and cancels its synthesis', async () => {
    const {engine, generator, decoder, context} = await startedEngine();

    const token = engine.prepare('Testu luzea.');
    const speak = speakMessages(generator)[0];
    decoder.emit({type: 'audio', id: speak.id, samples: new Float32Array(24000)});
    const playing = engine.play(await token);

    engine.stop();
    await playing;
    assert.equal(context.sources[0].stopped, true);
    const cancels = generator.sent.filter((m) => m.type === 'cancel');
    assert.equal(cancels.length, 1);
    assert.equal(cancels[0].id, speak.id);

    decoder.emit({type: 'audio', id: speak.id, samples: new Float32Array(100)});
    assert.equal(context.sources.length, 1, 'late audio of a cancelled fragment is dropped');
});

test('releasing a fragment that was prepared but never played cancels it', async () => {
    const {engine, generator, decoder} = await startedEngine();

    const token = engine.prepare('Ez da irakurriko.');
    const speak = speakMessages(generator)[0];
    decoder.emit({type: 'audio', id: speak.id, samples: new Float32Array(10)});
    engine.release(await token);
    assert.equal(generator.sent.filter((m) => m.type === 'cancel').length, 1);
});

test('pause and resume suspend and resume the audio clock', async () => {
    const {engine, generator, decoder, context} = await startedEngine();

    const token = engine.prepare('Kaixo.');
    const speak = speakMessages(generator)[0];
    decoder.emit({type: 'audio', id: speak.id, samples: new Float32Array(100)});
    engine.play(await token);

    engine.pause();
    assert.equal(context.state, 'suspended');
    engine.resume();
    assert.equal(context.state, 'running');
});

test('a later fragment waits for more audio after a stutter', async () => {
    const {engine, generator, decoder, context} = await startedEngine();

    const play = async (text, chunks) => {
        const token = engine.prepare(text);
        const speak = speakMessages(generator).pop();
        decoder.emit({type: 'audio', id: speak.id, samples: new Float32Array(2400)});
        const playing = engine.play(await token);
        chunks();
        generator.emit({type: 'gendone', id: speak.id});
        decoder.emit({type: 'decdone', id: speak.id});
        context.sources[context.sources.length - 1].onended();
        await playing;
        return speak;
    };

    await play('Lehena.', () => {
        // The clock runs past the end of the audio before the next chunk arrives.
        context.currentTime += 5;
        const speak = speakMessages(generator)[0];
        decoder.emit({type: 'audio', id: speak.id, samples: new Float32Array(2400)});
    });

    const before = context.sources.length;
    const token = engine.prepare('Bigarrena.');
    const speak = speakMessages(generator).pop();
    decoder.emit({type: 'audio', id: speak.id, samples: new Float32Array(2400)});
    engine.play(await token);
    assert.equal(context.sources.length, before, 'a tenth of a second is no longer enough to start');
    decoder.emit({type: 'audio', id: speak.id, samples: new Float32Array(24000 * 2)});
    assert.equal(context.sources.length, before + 2, 'it starts once more audio is buffered');
});

test('prepareFile returns the whole fragment as a 24 kHz WAV', async () => {
    const speech = loadSpeech('<p></p>');
    const blobs = [];
    speech.window.URL.createObjectURL = (blob) => {
        blobs.push(blob);
        return 'blob:wav';
    };
    const workers = fakeWorkers();
    const engine = speech.pockettts.createPocketTtsEngine(CONFIG, {
        Worker: workers.Worker, MessageChannel: workers.MessageChannel, context: fakeContext, supported: () => true,
    });
    const warm = engine.warmup();
    workers.made.forEach((worker) => worker.emit({type: 'ready'}));
    await warm;

    const file = engine.prepareFile('Kaixo.');
    const speak = speakMessages(workers.made[0])[0];
    workers.made[1].emit({type: 'audio', id: speak.id, samples: new Float32Array(100)});
    workers.made[1].emit({type: 'audio', id: speak.id, samples: new Float32Array(50)});
    workers.made[0].emit({type: 'gendone', id: speak.id});
    workers.made[1].emit({type: 'decdone', id: speak.id});

    assert.equal(await file, 'blob:wav');
    assert.equal(blobs[0].size, 44 + 150 * 2);
    const bytes = await new Promise((resolve) => {
        const reader = new speech.window.FileReader();
        reader.onload = () => resolve(reader.result);
        reader.readAsArrayBuffer(blobs[0]);
    });
    const header = new DataView(bytes);
    assert.equal(header.getUint32(24, true), 24000);
});

test('a fragment that produced no audio is reported as an error', async () => {
    const {engine, generator, decoder} = await startedEngine();

    const token = engine.prepare('???');
    const speak = speakMessages(generator)[0];
    generator.emit({type: 'gendone', id: speak.id, error: 'Text cannot be empty'});
    decoder.emit({type: 'decdone', id: speak.id});
    await assert.rejects(token, /Text cannot be empty/);
});

test('setVoice switches the voice of the next fragments without reloading the model', async () => {
    const {engine, generator, workers} = await startedEngine();

    engine.setVoice('https://models.example.org/eu/voices/Maider.safetensors');
    engine.prepare('Kaixo.');
    assert.equal(speakMessages(generator)[0].voiceurl, 'https://models.example.org/eu/voices/Maider.safetensors');
    assert.equal(workers.made.length, 2, 'no new workers');
});

test('a worker that dies after starting ends the fragments still waiting', async () => {
    const {engine, generator} = await startedEngine();

    const token = engine.prepare('Kaixo.');
    generator.fail('crashed');
    await assert.rejects(token, /crashed/);
    assert.equal(await engine.warmup(), false, 'the next reading falls back to another engine');
});

// ---------------------------------------------------------------------------
// speech.js: Pocket TTS ahead of Piper.

const page = `<!doctype html><html><body>
    <div class="block_ahotts_embhl">
        <div id="readspeaker_button1" class="rs_skip rsbtn">
            <a class="rsbtn_play" href="#"><span class="rsbtn_text"><span>Entzun</span></span></a>
        </div>
    </div>
    <div id="region-main"><p>Testua 2024an.</p></div>
</body></html>`;

const POCKET = {
    defaults: {eu: 'pocket-antton'},
    chooser: true,
    orturl: 'https://cdn.example.org/ort.wasm.min.mjs',
    wasmpath: 'https://cdn.example.org/',
    generatorurl: 'https://moodle.example.org/blocks/ahotts_embhl/js/pockettts/generator.js',
    decoderurl: 'https://moodle.example.org/blocks/ahotts_embhl/js/pockettts/decoder.js',
    voices: [
        {id: 'pocket-maider', label: 'Maider (naturala)', language: 'eu',
            baseurl: 'https://models.example.org/eu/', voiceurl: 'https://models.example.org/eu/voices/Maider.safetensors'},
        {id: 'pocket-antton', label: 'Antton (naturala)', language: 'eu',
            baseurl: 'https://models.example.org/eu/', voiceurl: 'https://models.example.org/eu/voices/Antton.safetensors'},
    ],
};

const PIPER = {
    defaults: {eu: 'antton'},
    chooser: true,
    orturl: 'https://moodle.example.org/ort/ort.webgpu.min.js',
    phonemizerurl: 'https://moodle.example.org/phonemizer.js',
    loaderurl: 'https://moodle.example.org/blocks/ahotts_embhl/js/esm-bridge.js',
    voices: [
        {id: 'antton', label: 'Antton', modelurl: 'https://models.example.org/antton.onnx', language: 'eu'},
        {id: 'maider', label: 'Maider', modelurl: 'https://models.example.org/maider.onnx', language: 'eu'},
    ],
};

/**
 * The block reading Basque, with every engine replaced by a recorder.
 *
 * @param {Object} options {pocketWarmup, stored}
 * @return {Object}
 */
const startBlock = (options = {}) => {
    const speech = loadSpeech(page);
    const log = [];
    const built = [];
    const voices = [];
    const recorder = (kind, warmup) => ({
        warmup: () => Promise.resolve(warmup),
        setVoice: (url) => voices.push(url),
        prepare: (text) => {
            log.push({kind, call: 'prepare', text});
            return Promise.resolve(text);
        },
        prepareFile: (text) => {
            log.push({kind, call: 'prepareFile', text});
            return Promise.resolve('blob:' + kind);
        },
        play: () => Promise.resolve(),
        pause() {},
        resume() {},
        stop() {},
        release() {},
    });
    const factories = speech.testing.engineFactories;
    factories.pockettts = (config) => {
        built.push({kind: 'pockettts', config});
        return recorder('pockettts', options.pocketWarmup !== false);
    };
    factories.piper = (config) => {
        built.push({kind: 'piper', config});
        return recorder('piper', true);
    };
    Object.entries(options.stored || {}).forEach(([key, value]) => {
        speech.window.localStorage.setItem(key, value);
    });
    speech.init({
        readid: 'region-main',
        maxlen: 3000,
        labels: {listen: 'Entzun', loading: 'Kargatzen', pause: 'Pausatu', resume: 'Jarraitu', stop: 'Gelditu',
            voice: 'Ahotsa'},
        status: {preparingvoice: 'Prestatzen.', preparingvoiceprogress: 'Deskargatzen ({percent}).'},
        langmode: 'fixed',
        lang: 'eu',
        languages: [{code: 'eu', label: 'Euskara', bcp47: 'eu-ES', engines: [{kind: 'pockettts'}, {kind: 'piper'}]}],
        piper: PIPER,
        pockettts: POCKET,
        bridge: {enabled: false, origins: [], timeout: 200},
    });
    return {
        ...speech,
        log,
        built,
        voices,
        link: speech.document.querySelector('a.rsbtn_play'),
        select: speech.document.querySelector('select.ahotts-voice'),
    };
};

test('Basque is read by the Pocket TTS voice when it is configured', async () => {
    const ctx = startBlock();
    ctx.link.click();
    await tick(20);

    assert.deepEqual(ctx.built.map((entry) => entry.kind), ['pockettts']);
    assert.equal(ctx.built[0].config.baseurl, 'https://models.example.org/eu/');
    assert.equal(ctx.built[0].config.generatorurl, POCKET.generatorurl);
    assert.equal(ctx.built[0].config.language, 'eu');
    assert.deepEqual(ctx.voices, ['https://models.example.org/eu/voices/Antton.safetensors']);
    assert.deepEqual(ctx.log.map((entry) => entry.kind), ['pockettts']);
});

test('the voice menu lists the natural voices first, then the Piper ones', () => {
    const ctx = startBlock();
    assert.deepEqual([...ctx.select.options].map((option) => option.value),
        ['pocket-maider', 'pocket-antton', 'antton', 'maider']);
    assert.equal(ctx.select.value, 'pocket-antton', 'the Pocket TTS default is chosen');
});

test('another natural voice is read with the same engine', async () => {
    const ctx = startBlock();
    ctx.select.value = 'pocket-maider';
    ctx.select.dispatchEvent(new ctx.window.Event('change'));
    ctx.link.click();
    await tick(20);

    assert.equal(ctx.built.length, 1);
    assert.deepEqual(ctx.voices, ['https://models.example.org/eu/voices/Maider.safetensors']);
});

test('a reader who picks a Piper voice gets Piper', async () => {
    const ctx = startBlock();
    ctx.select.value = 'maider';
    ctx.select.dispatchEvent(new ctx.window.Event('change'));
    ctx.link.click();
    await tick(20);

    assert.deepEqual(ctx.built.map((entry) => entry.kind), ['piper']);
    assert.equal(ctx.built[0].config.modelurl, 'https://models.example.org/maider.onnx');
});

test('Basque falls back to Piper when Pocket TTS cannot start', async () => {
    const ctx = startBlock({pocketWarmup: false});
    ctx.link.click();
    await tick(20);

    assert.deepEqual(ctx.built.map((entry) => entry.kind), ['pockettts', 'piper']);
    assert.equal(ctx.built[1].config.modelurl, 'https://models.example.org/antton.onnx',
        'Piper reads with its own default voice');
    assert.deepEqual(ctx.log.map((entry) => entry.kind), ['piper']);
});

test('the audio export asks Pocket TTS for whole files', async () => {
    const ctx = startBlock();
    ctx.window.AudioContext = function () {
        return {
            sampleRate: 22050,
            decodeAudioData: () => Promise.resolve({length: 10, numberOfChannels: 1, getChannelData: () => new Float32Array(10)}),
            close: () => Promise.resolve(),
        };
    };
    ctx.window.fetch = () => Promise.resolve({ok: true, arrayBuffer: () => Promise.resolve(new ArrayBuffer(8))});
    ctx.window.URL.createObjectURL = () => 'blob:file';
    ctx.window.URL.revokeObjectURL = () => {};
    ctx.document.querySelector('.ahotts-download').click();
    await tick(40);

    assert.deepEqual(ctx.log.map((entry) => entry.call), ['prepareFile']);
});
