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
 * In-browser speech synthesis with Pocket TTS (Kyutai), the neural Basque voices
 * of https://huggingface.co/itzune/pocket-tts-basque-onnx.
 *
 * The model is autoregressive: it produces one 80 ms latent frame per step and a
 * streaming decoder turns frames into 24 kHz audio. Both halves run in their own
 * Web Worker (js/pockettts/generator.js and decoder.js), single threaded, so the
 * page stays responsive and two cores are used without the cross-origin
 * isolation that WebAssembly threads would require.
 *
 * Audio is played through Web Audio as it is decoded, so a fragment starts
 * sounding well before it is fully synthesised. When a slow device falls behind
 * and playback stutters, later fragments wait for a little more audio before
 * they start.
 *
 * The engine offers the same interface as the others in speech.js. prepare()
 * resolves with a handle once the first audio of a fragment exists; play() plays
 * that handle as it grows. prepareFile() resolves with a complete WAV instead,
 * for the audio export.
 *
 * @module     block_ahotts_embhl/pockettts
 * @copyright  2026 Ahotts
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {encodeWav} from './piper';

export const SAMPLE_RATE = 24000;

// Model files of the int8 bundle, for the download progress.
const EXPECTED_BYTES = 125.5e6;

// Seconds of audio buffered before a fragment starts, and how far that grows
// after a stutter on a slow device.
const PREBUFFER = 0.4;
const PREBUFFER_STEP = 1;
const PREBUFFER_MAX = 4;

// The sampling temperature the itzune model was tuned for.
const TEMPERATURE = 0.3;

let sharedContext = null;

/**
 * The page's audio context, created on first use.
 *
 * @return {AudioContext|null}
 */
export const audioContext = () => {
    if (!sharedContext) {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) {
            return null;
        }
        sharedContext = new AudioContextClass();
    }
    return sharedContext;
};

/**
 * Let audio start later without a gesture. Call it straight from a click:
 * Safari only lets an audio context run when it is resumed inside one.
 */
export const unlockAudio = () => {
    try {
        const context = audioContext();
        if (context && context.state === 'suspended') {
            context.resume().catch(() => {});
        }
    } catch (e) {
        // No Web Audio: the engine reports itself unusable at warmup.
    }
};

/**
 * Whether this browser can run the engine at all.
 *
 * @return {Boolean}
 */
export const isSupported = () => typeof window !== 'undefined'
    && typeof window.Worker === 'function'
    && typeof window.WebAssembly === 'object'
    && typeof window.MessageChannel === 'function'
    && !!(window.AudioContext || window.webkitAudioContext);

/**
 * Join decoded chunks into one buffer.
 *
 * @param {Float32Array[]} chunks
 * @param {Number} length
 * @return {Float32Array}
 */
const concat = (chunks, length) => {
    const out = new Float32Array(length);
    let offset = 0;
    chunks.forEach((chunk) => {
        out.set(chunk, offset);
        offset += chunk.length;
    });
    return out;
};

/**
 * Engine that speaks with a Pocket TTS voice in the browser.
 *
 * @param {Object} config
 * @param {String} config.baseurl Folder holding bundle.json and the .onnx files, ending in /.
 * @param {String} config.voiceurl The voice's .safetensors; setVoice() changes it.
 * @param {String} config.orturl ONNX Runtime Web as an ES module (ort.wasm.min.mjs).
 * @param {String} [config.wasmpath] Directory holding the runtime's .wasm files.
 * @param {String} config.generatorurl URL of js/pockettts/generator.js.
 * @param {String} config.decoderurl URL of js/pockettts/decoder.js.
 * @param {String} [config.language] Language code, which picks the text normaliser.
 * @param {Object} [deps] Replacements for Worker and the audio context, for testing.
 * @return {Object}
 */
export const createPocketTtsEngine = (config, deps) => {
    const io = Object.assign({
        Worker: typeof window !== 'undefined' ? window.Worker : null,
        MessageChannel: typeof window !== 'undefined' ? window.MessageChannel : null,
        context: audioContext,
        supported: isSupported,
    }, deps || {});

    let generator = null;
    let decoder = null;
    let starting = null;
    let ready = false;
    let failed = false;
    let progress = null;
    const files = {};

    const tokens = new Map();
    const pending = [];
    let generating = null;
    let nextId = 1;
    let prebuffer = PREBUFFER;
    let playing = null;
    let voiceurl = config.voiceurl;

    /**
     * A fragment being synthesised.
     *
     * @param {String} text
     * @return {Object}
     */
    const makeToken = (text) => {
        const token = {
            id: nextId++,
            text: String(text || ''),
            voiceurl,
            chunks: [],
            length: 0,
            done: false,
            cancelled: false,
            error: '',
            listener: null,
        };
        token.firstAudio = new Promise((resolve) => {
            token.resolveFirst = resolve;
        });
        token.finished = new Promise((resolve) => {
            token.resolveFinished = resolve;
        });
        tokens.set(token.id, token);
        return token;
    };

    const notify = (token) => {
        if (token.listener) {
            token.listener();
        }
    };

    /** Send the next waiting fragment to the generator, one at a time. */
    const pump = () => {
        while (!generating && pending.length) {
            const token = pending.shift();
            if (token.cancelled) {
                continue;
            }
            generating = token;
            generator.postMessage({
                type: 'speak',
                id: token.id,
                text: token.text,
                language: config.language || '',
                voiceurl: token.voiceurl,
                temperature: TEMPERATURE,
            });
        }
    };

    const finishToken = (token) => {
        token.done = true;
        token.resolveFirst();
        token.resolveFinished();
        notify(token);
    };

    const reportProgress = (message) => {
        files[message.file] = message;
        if (!progress) {
            return;
        }
        const list = Object.keys(files).map((key) => files[key]);
        const loaded = list.reduce((sum, file) => sum + (file.loaded || 0), 0);
        const total = Math.max(EXPECTED_BYTES, list.reduce((sum, file) => sum + (file.total || 0), 0));
        progress(Math.min(1, loaded / total));
    };

    const onMessage = (event) => {
        const message = event.data || {};
        const token = tokens.get(message.id);
        if (message.type === 'progress') {
            reportProgress(message);
        } else if (message.type === 'audio') {
            if (token && !token.cancelled) {
                token.chunks.push(message.samples);
                token.length += message.samples.length;
                token.resolveFirst();
                notify(token);
            }
        } else if (message.type === 'gendone') {
            if (token && message.error) {
                token.error = message.error;
            }
            if (generating && generating.id === message.id) {
                generating = null;
            }
            pump();
        } else if (message.type === 'decerror') {
            if (token) {
                token.error = message.message;
            }
        } else if (message.type === 'decdone') {
            if (token) {
                finishToken(token);
                if (token.cancelled) {
                    tokens.delete(token.id);
                }
            }
        }
    };

    /**
     * A worker died after starting: end every fragment so nobody waits forever,
     * and let the next reading fall back to another engine.
     *
     * @param {String} reason
     */
    const crash = (reason) => {
        ready = false;
        failed = true;
        generating = null;
        pending.length = 0;
        [generator, decoder].forEach((worker) => {
            if (worker) {
                worker.terminate();
            }
        });
        generator = null;
        decoder = null;
        tokens.forEach((token) => {
            if (!token.done) {
                token.error = reason;
                finishToken(token);
            }
        });
    };

    /**
     * Start both workers and wait until each has its models loaded.
     *
     * @return {Promise<Boolean>}
     */
    const start = () => new Promise((resolve, reject) => {
        if (!io.supported() || !io.Worker || !io.MessageChannel) {
            reject(new Error('This browser cannot run Pocket TTS'));
            return;
        }
        let waiting = 2;
        const fail = (error) => {
            [generator, decoder].forEach((worker) => {
                if (worker) {
                    worker.terminate();
                }
            });
            generator = null;
            decoder = null;
            reject(error instanceof Error ? error : new Error(String(error)));
        };
        const listen = (worker) => {
            worker.addEventListener('message', (event) => {
                const message = event.data || {};
                if (message.type === 'ready') {
                    waiting -= 1;
                    if (waiting === 0) {
                        resolve(true);
                    }
                } else if (message.type === 'initerror') {
                    fail(new Error(message.message));
                } else {
                    onMessage(event);
                }
            });
            // A module worker that cannot load at all only raises an error event.
            worker.addEventListener('error', (event) => {
                if (event && event.preventDefault) {
                    event.preventDefault();
                }
                if (ready) {
                    crash((event && event.message) || 'Worker error');
                } else {
                    fail(new Error((event && event.message) || 'Worker error'));
                }
            });
        };
        try {
            const channel = new io.MessageChannel();
            generator = new io.Worker(config.generatorurl, {type: 'module'});
            decoder = new io.Worker(config.decoderurl, {type: 'module'});
            listen(generator);
            listen(decoder);
            const init = {
                type: 'init',
                baseurl: config.baseurl,
                orturl: config.orturl,
                wasmpaths: config.wasmpath || '',
            };
            generator.postMessage(Object.assign({port: channel.port1}, init), [channel.port1]);
            decoder.postMessage(Object.assign({port: channel.port2}, init), [channel.port2]);
        } catch (e) {
            fail(e);
        }
    });

    /**
     * Queue a fragment for synthesis.
     *
     * @param {String} text
     * @return {Object} The token.
     */
    const enqueue = (text) => {
        const token = makeToken(text);
        if (!ready) {
            token.error = 'Pocket TTS is not ready';
            finishToken(token);
            return token;
        }
        pending.push(token);
        pump();
        return token;
    };

    /**
     * Stop synthesising a fragment and forget its audio.
     *
     * @param {Object} token
     */
    const cancel = (token) => {
        if (!token || token.cancelled) {
            return;
        }
        token.cancelled = true;
        token.chunks = [];
        token.listener = null;
        if (generating === token && generator) {
            generator.postMessage({type: 'cancel', id: token.id});
        }
        if (token.done) {
            tokens.delete(token.id);
        }
    };

    /**
     * Schedule whatever audio of the playing fragment has not been scheduled yet.
     *
     * @param {Object} state The playback in progress.
     */
    const schedule = (state) => {
        if (playing !== state || state.ended) {
            return;
        }
        const token = state.token;
        const context = state.context;
        if (!state.started) {
            if (token.length / SAMPLE_RATE < state.prebuffer && !token.done) {
                return;
            }
            state.started = true;
            state.nextTime = context.currentTime + 0.05;
        }
        while (state.index < token.chunks.length) {
            const samples = token.chunks[state.index];
            state.index += 1;
            if (state.nextTime < context.currentTime) {
                // The decoder fell behind the speaker: remember it for later fragments.
                if (state.scheduled > 0) {
                    state.stuttered = true;
                }
                state.nextTime = context.currentTime + 0.02;
            }
            const buffer = context.createBuffer(1, samples.length, SAMPLE_RATE);
            buffer.getChannelData(0).set(samples);
            const source = context.createBufferSource();
            source.buffer = buffer;
            source.connect(context.destination);
            source.start(state.nextTime);
            state.nextTime += samples.length / SAMPLE_RATE;
            state.sources.push(source);
            state.scheduled += 1;
        }
        if (token.done) {
            const last = state.sources[state.sources.length - 1];
            if (last) {
                last.onended = () => endPlayback(state);
            } else {
                endPlayback(state);
            }
        }
    };

    const endPlayback = (state) => {
        if (state.ended) {
            return;
        }
        state.ended = true;
        if (state.stuttered) {
            prebuffer = Math.min(PREBUFFER_MAX, prebuffer + PREBUFFER_STEP);
        }
        state.token.listener = null;
        if (playing === state) {
            playing = null;
        }
        state.resolve();
    };

    return {
        /**
         * Read the next fragments with another voice of the same model.
         *
         * @param {String} url The voice's .safetensors.
         */
        setVoice: (url) => {
            if (url) {
                voiceurl = url;
            }
        },

        /**
         * Download the models and report whether the voice can be used.
         *
         * @param {Function} [onProgress] Called with the fraction downloaded, 0 to 1.
         * @return {Promise<Boolean>}
         */
        warmup: (onProgress) => {
            progress = typeof onProgress === 'function' ? onProgress : null;
            if (failed) {
                return Promise.resolve(false);
            }
            if (ready) {
                return Promise.resolve(true);
            }
            if (!starting) {
                starting = start().then(() => {
                    ready = true;
                    return true;
                }).catch(() => {
                    failed = true;
                    return false;
                });
            }
            return starting;
        },

        /**
         * Start synthesising a fragment; resolves once its first audio exists.
         *
         * @param {String} text
         * @return {Promise<Object>}
         */
        prepare: (text) => {
            const token = enqueue(text);
            return token.firstAudio.then(() => {
                if (!token.length && token.error) {
                    throw new Error(token.error);
                }
                if (!token.length && token.done) {
                    throw new Error('Nothing to synthesise');
                }
                return token;
            });
        },

        /**
         * Synthesise a fragment completely, as a WAV file.
         *
         * @param {String} text
         * @return {Promise<String>} An object URL.
         */
        prepareFile: (text) => {
            const token = enqueue(text);
            return token.finished.then(() => {
                tokens.delete(token.id);
                if (!token.length) {
                    throw new Error(token.error || 'Nothing to synthesise');
                }
                return URL.createObjectURL(encodeWav(concat(token.chunks, token.length), SAMPLE_RATE));
            });
        },

        play: (token) => new Promise((resolve) => {
            const context = io.context();
            if (!context || !token || token.cancelled) {
                resolve();
                return;
            }
            if (context.state === 'suspended') {
                context.resume().catch(() => {});
            }
            const state = {
                token,
                context,
                resolve,
                prebuffer,
                started: false,
                ended: false,
                stuttered: false,
                nextTime: 0,
                index: 0,
                scheduled: 0,
                sources: [],
            };
            playing = state;
            token.listener = () => schedule(state);
            schedule(state);
        }),

        pause: () => {
            const context = playing && playing.context;
            if (context && context.state === 'running') {
                context.suspend().catch(() => {});
            }
        },

        resume: () => {
            const context = playing && playing.context;
            if (context && context.state === 'suspended') {
                context.resume().catch(() => {});
            }
        },

        stop: () => {
            const state = playing;
            if (!state) {
                return;
            }
            state.sources.forEach((source) => {
                try {
                    source.onended = null;
                    source.stop();
                } catch (e) {
                    // Not started yet, or already over.
                }
            });
            cancel(state.token);
            endPlayback(state);
            // A pause left the context suspended; the next reading needs it running.
            if (state.context.state === 'suspended') {
                state.context.resume().catch(() => {});
            }
        },

        release: (token) => {
            if (typeof token === 'string') {
                URL.revokeObjectURL(token);
                return;
            }
            if (token && token.done && !token.cancelled) {
                tokens.delete(token.id);
                token.chunks = [];
                return;
            }
            cancel(token);
        },
    };
};
