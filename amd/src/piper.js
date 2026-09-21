// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * In-browser speech synthesis with Piper voices.
 *
 * Basque is spoken by the itzune voices (https://huggingface.co/itzune), the
 * other languages by voices from the Piper project. All of them are Piper/VITS
 * models exported to ONNX, run locally by ONNX Runtime Web, on WebGPU when the
 * browser offers it and on WebAssembly otherwise, so no text leaves the
 * learner's machine and no request is made to the aHoTTS service.
 *
 * The pipeline is the standard Piper one:
 *
 *   text --(eSpeak NG, in WebAssembly)--> IPA phonemes
 *        --(phoneme_id_map from the voice config)--> int64 ids
 *        --(VITS ONNX model)--> float32 waveform --> WAV blob
 *
 * The phonemizer must be an eSpeak NG build carrying the languages in use.
 * Builds exist that only carry English, so the engine phonemizes one word while
 * it starts and refuses to report itself ready when the language is missing.
 *
 * Both the runtime and the voice are fetched once and kept in the Cache Storage,
 * so later pages start speaking without downloading anything again.
 *
 * @module     block_ahotts_embhl/piper
 * @copyright  2026 Ahotts
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Piper reserves these three symbols in every voice's phoneme_id_map.
const SYMBOL_BOS = '^';
const SYMBOL_EOS = '$';
const SYMBOL_PAD = '_';

// Where downloaded runtimes and voices are kept between visits.
const CACHE_NAME = 'block_ahotts_embhl_piper_v1';

// Name eSpeak NG is asked to write its phonemes to, inside its own in-memory
// file system.
const ESPEAK_OUTPUT = 'phonemes';

// A word run through the phonemizer while the engine starts, to prove the build
// in use really carries the voice's language.
const LANGUAGE_PROBE = 'kaixo';

// Piper receives each eSpeak clause separately. Leave a short silence between
// them so punctuation such as a comma is audible instead of being flattened
// into an ordinary space.
const CLAUSE_PAUSE_SECONDS = 0.18;

/**
 * Turn a string of IPA phonemes into the id sequence a Piper model expects.
 *
 * The convention is the one implemented by piper_phonemize: a beginning of
 * sentence symbol, then every phoneme followed by a padding symbol, then an end
 * of sentence symbol. Phonemes the voice does not know are skipped rather than
 * guessed.
 *
 * @param {String} phonemes
 * @param {Object} idmap The voice's phoneme_id_map.
 * @return {Array<Number>}
 */
export const phonemesToIds = (phonemes, idmap) => {
    const ids = [];
    const push = (symbol) => {
        const mapped = idmap && idmap[symbol];
        if (!mapped || !mapped.length) {
            return false;
        }
        mapped.forEach((id) => ids.push(id));
        return true;
    };

    push(SYMBOL_BOS);
    push(SYMBOL_PAD);
    // Iterating a string with for..of walks code points, so characters outside
    // the basic plane and combining marks are not cut in half.
    Array.from(String(phonemes || '')).forEach((symbol) => {
        if (push(symbol)) {
            push(SYMBOL_PAD);
        }
    });
    push(SYMBOL_EOS);

    return ids;
};

/**
 * Wrap a mono float waveform in a 16 bit PCM WAV container.
 *
 * @param {Float32Array|Array<Number>} samples
 * @param {Number} samplerate
 * @return {Blob}
 */
export const encodeWav = (samples, samplerate) => {
    const length = samples.length;
    const buffer = new ArrayBuffer(44 + (length * 2));
    const view = new DataView(buffer);
    const writeText = (offset, text) => {
        for (let i = 0; i < text.length; i++) {
            view.setUint8(offset + i, text.charCodeAt(i));
        }
    };

    writeText(0, 'RIFF');
    view.setUint32(4, 36 + (length * 2), true);
    writeText(8, 'WAVE');
    writeText(12, 'fmt ');
    view.setUint32(16, 16, true);       // Size of the fmt chunk.
    view.setUint16(20, 1, true);        // PCM.
    view.setUint16(22, 1, true);        // Mono.
    view.setUint32(24, samplerate, true);
    view.setUint32(28, samplerate * 2, true); // Byte rate.
    view.setUint16(32, 2, true);        // Block align.
    view.setUint16(34, 16, true);       // Bits per sample.
    writeText(36, 'data');
    view.setUint32(40, length * 2, true);

    let offset = 44;
    for (let i = 0; i < length; i++) {
        const sample = Math.max(-1, Math.min(1, samples[i]));
        view.setInt16(offset, sample < 0 ? sample * 0x8000 : sample * 0x7FFF, true);
        offset += 2;
    }

    return new Blob([view], {type: 'audio/wav'});
};

/**
 * Load a classic script once, resolving when it has run.
 *
 * ONNX Runtime Web ships a UMD build, so a plain script tag is enough and no
 * inline code or eval is needed - which keeps the plugin usable under a strict
 * Content-Security-Policy.
 *
 * @param {String} url
 * @return {Promise}
 */
const loadScript = (url) => new Promise((resolve, reject) => {
    const existing = document.querySelector('script[data-ahotts-script="' + CSS.escape(url) + '"]');
    if (existing) {
        if (existing.dataset.ahottsLoaded === '1') {
            resolve();
            return;
        }
        existing.addEventListener('load', () => resolve());
        existing.addEventListener('error', () => reject(new Error('Cannot load ' + url)));
        return;
    }
    const script = document.createElement('script');
    script.src = url;
    script.async = true;
    script.dataset.ahottsScript = url;
    script.addEventListener('load', () => {
        script.dataset.ahottsLoaded = '1';
        resolve();
    });
    script.addEventListener('error', () => reject(new Error('Cannot load ' + url)));
    document.head.appendChild(script);
});

/**
 * Load an ES module and hand back its namespace.
 *
 * AMD modules cannot use a native dynamic import: the build step rewrites it
 * into a RequireJS call. Instead a tiny module shipped with the plugin
 * (js/esm-bridge.js) is added as <script type="module" src="...">, imports the
 * target and publishes it on window. Everything stays an external script file,
 * so neither eval nor inline scripts are required.
 *
 * @param {String} loaderurl URL of js/esm-bridge.js.
 * @param {String} url Module to import.
 * @param {String} name Global name the loader publishes it under.
 * @return {Promise<Object>}
 */
const loadModule = (loaderurl, url, name) => new Promise((resolve, reject) => {
    if (window[name]) {
        resolve(window[name]);
        return;
    }
    const done = (event) => {
        window.removeEventListener(name + ':ready', done);
        window.removeEventListener(name + ':error', failed);
        resolve(event.detail);
    };
    const failed = (event) => {
        window.removeEventListener(name + ':ready', done);
        window.removeEventListener(name + ':error', failed);
        reject(new Error(String(event.detail)));
    };
    window.addEventListener(name + ':ready', done);
    window.addEventListener(name + ':error', failed);

    const script = document.createElement('script');
    script.type = 'module';
    script.src = loaderurl
        + (loaderurl.indexOf('?') === -1 ? '?' : '&')
        + 'src=' + encodeURIComponent(url)
        + '&name=' + encodeURIComponent(name);
    script.addEventListener('error', () => reject(new Error('Cannot load ' + loaderurl)));
    document.head.appendChild(script);
});

/**
 * Fetch a binary, keeping a copy in the Cache Storage so the voice is only
 * downloaded once per browser.
 *
 * @param {String} url
 * @return {Promise<ArrayBuffer>}
 */
const fetchCached = async (url) => {
    let cache = null;
    try {
        if (window.caches) {
            cache = await window.caches.open(CACHE_NAME);
            const hit = await cache.match(url);
            if (hit) {
                return hit.arrayBuffer();
            }
        }
    } catch (e) {
        cache = null; // No Cache Storage (private mode, insecure context): just fetch.
    }

    const response = await fetch(url, {credentials: 'omit'});
    if (!response.ok) {
        throw new Error('Cannot download ' + url + ' (' + response.status + ')');
    }
    if (cache) {
        try {
            await cache.put(url, response.clone());
        } catch (e) {
            // A voice too large for the quota still plays, it is just not cached.
        }
    }
    return response.arrayBuffer();
};

/**
 * Whether the browser exposes WebGPU.
 *
 * @return {Boolean}
 */
export const hasWebGpu = () => typeof navigator !== 'undefined' && !!navigator.gpu;

/**
 * Adapt a full eSpeak NG WebAssembly build to phonemize(text, language).
 *
 * eSpeak NG is published as an Emscripten command line program: it runs once
 * per instantiation with the arguments it is handed and writes to its own
 * in-memory file system. Piper voices are trained on plain IPA with no
 * separators, which is what --ipa produces, and eSpeak writes one line per
 * clause.
 *
 * No -b is passed on purpose. Any value of it makes eSpeak read the text byte
 * by byte, so the two bytes of an accented character are spelled out instead of
 * spoken: "esta" became "est a-tilde" and "Onatiko" gained a tilde of its own.
 * Left out, eSpeak detects the UTF-8 it is actually given.
 *
 * The WebAssembly binary is large, so it is downloaded once and handed to every
 * later run, which keeps a call well under a fifth of a second.
 *
 * @param {Function} factory The module's default export.
 * @param {Function} fetchBinary Loader for the .wasm, cached between visits.
 * @param {String} wasmurl Where the .wasm lives; empty lets eSpeak find it.
 * @return {Function} phonemize(text, language)
 */
export const espeakPhonemizer = (factory, fetchBinary, wasmurl) => {
    let binary = null;

    return async (text, language) => {
        if (wasmurl && !binary) {
            binary = await fetchBinary(wasmurl);
        }

        const errors = [];
        const options = {
            arguments: [
                '--phonout', ESPEAK_OUTPUT,
                '--sep=',
                '-q',
                '--ipa',
                '-v', language,
                String(text),
            ],
            print: () => {},
            printErr: (line) => errors.push(String(line)),
        };
        if (binary) {
            options.wasmBinary = binary;
        }

        // A language eSpeak does not carry makes it exit before writing
        // anything, so both the run and the read are reported as one failure.
        let written = null;
        try {
            const espeak = await factory(options);
            written = espeak.FS.readFile(ESPEAK_OUTPUT, {encoding: 'utf8'});
        } catch (e) {
            written = null;
        }
        if (written === null) {
            throw new Error('eSpeak NG cannot speak "' + language + '"'
                + (errors.length ? ': ' + errors.join(' ') : ''));
        }

        return String(written)
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== '');
    };
};

/**
 * The phonemize(text, language) of whatever module the administrator pointed at.
 *
 * A module exporting phonemize() is used as it is; anything else is taken for
 * an eSpeak NG Emscripten build and wrapped.
 *
 * @param {Object} namespace
 * @param {Function} fetchBinary
 * @param {String} wasmurl
 * @return {Function}
 */
export const resolvePhonemizer = (namespace, fetchBinary, wasmurl) => {
    if (namespace && typeof namespace.phonemize === 'function') {
        return namespace.phonemize;
    }

    const factory = namespace && (namespace.default || namespace);
    if (typeof factory === 'function') {
        return espeakPhonemizer(factory, fetchBinary, wasmurl);
    }

    throw new Error('The phonemizer module exports neither phonemize() nor an eSpeak NG build');
};

/**
 * Default dependency set. Tests replace these to keep the runtime out.
 *
 * @return {Object}
 */
const defaultDeps = () => ({
    loadScript: loadScript,
    loadModule: loadModule,
    fetchBinary: fetchCached,
    fetchJson: (url) => fetch(url, {credentials: 'omit'}).then((response) => {
        if (!response.ok) {
            throw new Error('Cannot download ' + url + ' (' + response.status + ')');
        }
        return response.json();
    }),
    getOrt: () => window.ort,
    hasWebGpu: hasWebGpu,
    resolvePhonemizer: resolvePhonemizer,
});

/**
 * Engine that synthesises Basque locally with an itzune Piper voice.
 *
 * It offers the same interface as the other engines in speech.js, plus
 * warmup(), which reports whether the voice could actually be started so the
 * caller can fall back to the aHoTTS API.
 *
 * @param {Object} config
 * @param {String} config.modelurl URL of the .onnx voice.
 * @param {String} [config.configurl] URL of its .json config; defaults to modelurl + '.json'.
 * @param {String} [config.backend] 'auto', 'webgpu' or 'wasm'.
 * @param {String} config.orturl URL of the ONNX Runtime Web UMD build.
 * @param {String} [config.wasmpath] Directory holding the runtime's .wasm files.
 * @param {String} config.phonemizerurl URL of the eSpeak NG phonemizer ES module.
 * @param {String} [config.phonemizerwasm] URL of its .wasm, when it ships one.
 * @param {String} config.loaderurl URL of js/esm-bridge.js.
 * @param {String} [config.language] eSpeak voice name; defaults to the one in the voice config.
 * @param {Object} [deps] Injected loaders, for testing.
 * @return {Object}
 */
export const createPiperEngine = (config, deps) => {
    const io = Object.assign(defaultDeps(), deps || {});
    const configurl = config.configurl || (config.modelurl + '.json');

    let session = null;
    let voice = null;
    let phonemize = null;
    let ort = null;
    let starting = null;
    let failed = false;

    /**
     * The eSpeak voice this model was trained with.
     *
     * @return {String}
     */
    const voiceLanguage = () => config.language || (voice && voice.espeak && voice.espeak.voice) || 'eu';

    // One inference at a time: the next chunk is prepared while the current one
    // plays, and two simultaneous runs would fight over the same GPU context.
    let queue = Promise.resolve();

    let audio = null;
    let resolveEnd = null;

    /**
     * Download the runtime, the voice and the phonemizer, once.
     *
     * @return {Promise<Boolean>} Whether the engine is usable.
     */
    const start = async () => {
        await io.loadScript(config.orturl);
        ort = io.getOrt();
        if (!ort) {
            throw new Error('ONNX Runtime did not register itself');
        }
        if (config.wasmpath && ort.env && ort.env.wasm) {
            ort.env.wasm.wasmPaths = config.wasmpath;
        }

        const namespace = await io.loadModule(config.loaderurl, config.phonemizerurl, 'ahottsPhonemizer');
        phonemize = io.resolvePhonemizer(namespace, io.fetchBinary, config.phonemizerwasm || '');

        voice = await io.fetchJson(configurl);

        // Phonemize one word before going any further. An eSpeak build without
        // this language would otherwise start cleanly and then fail on every
        // chunk, leaving the learner with silence and no explanation.
        await phonemize(LANGUAGE_PROBE, voiceLanguage());

        const model = await io.fetchBinary(config.modelurl);

        const wanted = config.backend || 'auto';
        const providers = (wanted === 'wasm' || (wanted === 'auto' && !io.hasWebGpu()))
            ? ['wasm']
            : ['webgpu', 'wasm'];

        try {
            session = await ort.InferenceSession.create(model, {executionProviders: providers});
        } catch (e) {
            if (providers[0] !== 'wasm') {
                // WebGPU refused the model: WebAssembly always can.
                session = await ort.InferenceSession.create(model, {executionProviders: ['wasm']});
            } else {
                throw e;
            }
        }
        return true;
    };

    /**
     * Run the model over one chunk of text.
     *
     * @param {String} text
     * @return {Promise<String>} Object URL of the resulting WAV.
     */
    const synthesise = async (text) => {
        const phonemes = await phonemize(text, voiceLanguage());
        const clauses = (Array.isArray(phonemes) ? phonemes : [phonemes])
            .map((clause) => String(clause || '').trim())
            .filter(Boolean);
        if (!clauses.length) {
            throw new Error('Nothing to synthesise');
        }

        const inference = voice.inference || {};
        const samplerate = (voice.audio && voice.audio.sample_rate) || 22050;
        const pause = new Float32Array(Math.round(samplerate * CLAUSE_PAUSE_SECONDS));
        const waveforms = [];

        for (let i = 0; i < clauses.length; i++) {
            const ids = phonemesToIds(clauses[i], voice.phoneme_id_map);
            if (ids.length <= 3) {
                continue;
            }
            const feeds = {
                input: new ort.Tensor('int64', BigInt64Array.from(ids, (id) => BigInt(id)), [1, ids.length]),
                input_lengths: new ort.Tensor('int64', BigInt64Array.from([BigInt(ids.length)])),
                scales: new ort.Tensor('float32', Float32Array.from([
                    typeof inference.noise_scale === 'number' ? inference.noise_scale : 0.667,
                    typeof inference.length_scale === 'number' ? inference.length_scale : 1,
                    typeof inference.noise_w === 'number' ? inference.noise_w : 0.8,
                ])),
            };
            if (voice.speaker_id_map && Object.keys(voice.speaker_id_map).length) {
                feeds.sid = new ort.Tensor('int64', BigInt64Array.from([BigInt(config.speaker || 0)]));
            }

            const results = await session.run(feeds);
            const output = results.output || results[Object.keys(results)[0]];
            if (waveforms.length) {
                waveforms.push(pause);
            }
            waveforms.push(output.data);
        }

        if (!waveforms.length) {
            throw new Error('Nothing to synthesise');
        }
        const length = waveforms.reduce((total, samples) => total + samples.length, 0);
        const combined = new Float32Array(length);
        let offset = 0;
        waveforms.forEach((samples) => {
            combined.set(samples, offset);
            offset += samples.length;
        });
        return URL.createObjectURL(encodeWav(combined, samplerate));
    };

    return {
        /**
         * Download everything and report whether the voice can be used.
         *
         * @return {Promise<Boolean>}
         */
        warmup: () => {
            if (failed) {
                return Promise.resolve(false);
            }
            if (session) {
                return Promise.resolve(true);
            }
            if (!starting) {
                starting = start().catch(() => {
                    failed = true;
                    return false;
                });
            }
            return starting.then((ok) => ok !== false);
        },

        prepare: (text) => {
            const run = queue.then(() => synthesise(text));
            // Keep the queue alive even when one chunk fails.
            queue = run.catch(() => null);
            return run;
        },

        play: (url) => new Promise((resolve) => {
            audio = new Audio(url);
            resolveEnd = resolve;
            const finishPlay = () => {
                audio = null;
                if (resolveEnd) {
                    const done = resolveEnd;
                    resolveEnd = null;
                    done();
                }
            };
            audio.addEventListener('ended', finishPlay);
            audio.addEventListener('error', finishPlay);
            audio.play().catch(finishPlay);
        }),

        pause: () => {
            if (audio) {
                audio.pause();
            }
        },

        resume: () => {
            if (audio) {
                audio.play().catch(() => {});
            }
        },

        stop: () => {
            if (audio) {
                audio.pause();
                audio = null;
            }
            if (resolveEnd) {
                const done = resolveEnd;
                resolveEnd = null;
                done();
            }
        },

        release: (url) => URL.revokeObjectURL(url),
    };
};
