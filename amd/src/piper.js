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
 * In-browser Basque speech synthesis with the itzune Piper voices.
 *
 * The voices published at https://huggingface.co/itzune (antton and maider) are
 * Piper/VITS models exported to ONNX. They are run locally by ONNX Runtime Web,
 * on WebGPU when the browser offers it and on WebAssembly otherwise, so no text
 * leaves the learner's machine and no request is made to the aHoTTS service.
 *
 * The pipeline is the standard Piper one:
 *
 *   text --(eSpeak NG, in WebAssembly)--> IPA phonemes
 *        --(phoneme_id_map from the voice config)--> int64 ids
 *        --(VITS ONNX model)--> float32 waveform --> WAV blob
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
        phonemize = namespace.phonemize;
        if (typeof phonemize !== 'function') {
            throw new Error('The phonemizer module exports no phonemize()');
        }

        voice = await io.fetchJson(configurl);
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
        const language = config.language || (voice.espeak && voice.espeak.voice) || 'eu';
        const phonemes = await phonemize(text, language);
        const joined = Array.isArray(phonemes) ? phonemes.join(' ') : String(phonemes);
        const ids = phonemesToIds(joined, voice.phoneme_id_map);
        if (ids.length <= 3) {
            throw new Error('Nothing to synthesise');
        }

        const inference = voice.inference || {};
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
        const samplerate = (voice.audio && voice.audio.sample_rate) || 22050;
        return URL.createObjectURL(encodeWav(output.data, samplerate));
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
