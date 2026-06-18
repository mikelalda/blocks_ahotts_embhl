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
 * Text-to-speech for the ahotts_embhl block.
 *
 * Reads the configured page region chunk by chunk for near real-time playback.
 * Basque is synthesised by the aHoTTS API (the next chunk is pre-fetched while
 * the current one plays); Spanish/English use the browser Web Speech API. The
 * chunk currently being spoken is underlined. Same-origin iframes (e.g. SCORM
 * content) are traversed too.
 *
 * @module     block_ahotts_embhl/speech
 * @copyright  2026 Ahotts
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const STATE_IDLE = 'idle';
const STATE_PLAYING = 'playing';
const STATE_PAUSED = 'paused';

// Target size (characters) for each synthesised chunk. Small chunks start
// playing sooner, which is what makes playback feel real-time.
const CHUNK_TARGET = 160;

// Block-level tags whose text is read as a unit (one "line").
const BLOCK_SELECTOR = 'p,li,h1,h2,h3,h4,h5,h6,td,th,dd,dt,blockquote,figcaption,caption,pre';

/**
 * Whether the browser supports the Web Speech API.
 *
 * @return {Boolean}
 */
const hasWebSpeech = () => 'speechSynthesis' in window
    && typeof window.SpeechSynthesisUtterance !== 'undefined';

/**
 * Whether an element is currently visible.
 *
 * @param {Element} el
 * @return {Boolean}
 */
const isVisible = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);

/**
 * Split a piece of text into chunks, breaking on line breaks first and then on
 * sentence boundaries so that no chunk is much larger than CHUNK_TARGET.
 *
 * @param {String} raw
 * @return {Array<String>}
 */
const splitText = (raw) => {
    const out = [];
    const lines = raw.split(/\n+/).map((s) => s.replace(/\s+/g, ' ').trim()).filter(Boolean);
    lines.forEach((line) => {
        if (line.length <= CHUNK_TARGET) {
            out.push(line);
            return;
        }
        const sentences = line.match(/[^.!?]+[.!?]*/g) || [line];
        let buffer = '';
        sentences.forEach((sentence) => {
            if (buffer && (buffer + sentence).length > CHUNK_TARGET) {
                out.push(buffer.trim());
                buffer = '';
            }
            buffer += sentence;
        });
        if (buffer.trim()) {
            out.push(buffer.trim());
        }
    });
    return out;
};

/**
 * Recursively collect readable chunks from an element, descending into
 * same-origin iframes (SCORM content).
 *
 * @param {Element} root
 * @param {Array} out Accumulator of {el, text} entries.
 */
const collectFrom = (root, out) => {
    let elements = Array.prototype.slice.call(root.querySelectorAll(BLOCK_SELECTOR));
    elements = elements.filter((el) => {
        if (el.closest('#readspeaker_button1')) {
            return false;
        }
        const text = el.innerText || el.textContent || '';
        return text.trim() !== '' && isVisible(el);
    });
    // Keep only leaf blocks: drop any element that contains another collected one.
    elements = elements.filter((el) => !elements.some((other) => other !== el && el.contains(other)));

    elements.forEach((el) => {
        splitText(el.innerText || el.textContent || '').forEach((text) => {
            out.push({el: el, text: text});
        });
    });

    const frames = root.querySelectorAll('iframe,frame');
    Array.prototype.forEach.call(frames, (frame) => {
        let doc = null;
        try {
            doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
        } catch (e) {
            doc = null; // Cross-origin: cannot read.
        }
        if (doc && doc.body) {
            collectFrom(doc.body, out);
        }
    });
};

/**
 * Apply / remove the underline highlight on a chunk's element. Inline styles are
 * used so it also works inside iframe documents.
 *
 * @param {Element} el
 */
const highlight = (el) => {
    if (!el) {
        return;
    }
    el.dataset.ahottsTextdecoration = el.style.textDecoration || '';
    el.style.textDecoration = 'underline';
    el.style.textDecorationThickness = '2px';
    el.style.textUnderlineOffset = '2px';
    try {
        el.scrollIntoView({block: 'nearest', behavior: 'smooth'});
    } catch (e) {
        el.scrollIntoView();
    }
};
const unhighlight = (el) => {
    if (!el || typeof el.dataset.ahottsTextdecoration === 'undefined') {
        return;
    }
    el.style.textDecoration = el.dataset.ahottsTextdecoration;
    el.style.textDecorationThickness = '';
    el.style.textUnderlineOffset = '';
    delete el.dataset.ahottsTextdecoration;
};

/**
 * Engine that synthesises text through the aHoTTS API. prepare() fetches the WAV
 * (so it can be pre-fetched ahead of time) and play() plays the returned blob.
 *
 * @param {Object} api {url, language, voice}
 * @return {Object}
 */
const apiEngine = (api) => {
    const endpoint = api.url.replace(/\/+$/, '') + '/synthesize';
    let audio = null;
    let resolveEnd = null;

    return {
        prepare: (text) => fetch(endpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({text: text, language: api.language, voice: api.voice}),
        }).then((response) => {
            if (!response.ok) {
                throw new Error('aHoTTS API responded ' + response.status);
            }
            return response.blob();
        }).then((blob) => URL.createObjectURL(blob)),
        play: (url) => new Promise((resolve) => {
            audio = new Audio(url);
            resolveEnd = resolve;
            const finishPlay = () => {
                // Drop the reference so a later resume() can't replay this chunk.
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

/**
 * Engine backed by the browser Web Speech API.
 *
 * @param {String} lang BCP-47 language tag.
 * @return {Object}
 */
const webSpeechEngine = (lang) => {
    const synth = window.speechSynthesis;
    let resolveEnd = null;

    return {
        prepare: (text) => Promise.resolve(text),
        play: (text) => new Promise((resolve) => {
            const utterance = new window.SpeechSynthesisUtterance(text);
            if (lang) {
                utterance.lang = lang;
            }
            resolveEnd = resolve;
            utterance.onend = resolve;
            utterance.onerror = resolve;
            synth.cancel();
            synth.speak(utterance);
        }),
        pause: () => synth.pause(),
        resume: () => synth.resume(),
        stop: () => {
            synth.cancel();
            if (resolveEnd) {
                resolveEnd();
                resolveEnd = null;
            }
        },
        release: () => {},
    };
};

/**
 * Initialise the Listen button behaviour.
 *
 * @param {Object} config
 * @param {String} config.prefer    Preferred engine: 'api' or 'webspeech'.
 * @param {String} config.readid    Id of the element whose text is read.
 * @param {Number} config.maxlen    Overall character cap across all chunks.
 * @param {Object} config.labels    Button labels (listen, loading, pause, resume, stop).
 * @param {Object} [config.api]     aHoTTS API parameters {url, language, voice}.
 * @param {Object} [config.webspeech] Web Speech parameters {lang}.
 */
export const init = (config) => {
    const button = document.getElementById('readspeaker_button1');
    if (!button) {
        return;
    }
    const link = button.querySelector('a.rsbtn_play');
    if (!link) {
        return;
    }

    let engineKind = config.prefer;
    if (engineKind === 'webspeech' && !hasWebSpeech()) {
        engineKind = config.api ? 'api' : 'none';
    }
    if (engineKind === 'api' && (!config.api || !config.api.url)) {
        engineKind = 'none';
    }
    if (engineKind === 'none') {
        return;
    }

    const labelNode = button.querySelector('.rsbtn_text span');
    const engine = engineKind === 'api'
        ? apiEngine(config.api)
        : webSpeechEngine(config.webspeech ? config.webspeech.lang : '');

    let state = STATE_IDLE;
    let stopped = false;
    let paused = false;
    let currentEl = null;
    // When paused between chunks, the runner waits on this resolver.
    let gateResolve = null;

    const setLabel = (text) => {
        if (labelNode && text) {
            labelNode.textContent = text;
        }
    };

    // Resolves immediately unless paused, in which case it waits for resume/stop.
    const waitWhilePaused = () => {
        if (!paused) {
            return Promise.resolve();
        }
        return new Promise((resolve) => {
            gateResolve = resolve;
        });
    };

    const openGate = () => {
        if (gateResolve) {
            const release = gateResolve;
            gateResolve = null;
            release();
        }
    };

    // Explicit Stop control, shown only while playing.
    const stopButton = document.createElement('button');
    stopButton.type = 'button';
    stopButton.className = 'rsbtn_stop btn btn-link p-0 ml-1';
    stopButton.textContent = config.labels.stop;
    stopButton.style.display = 'none';
    link.parentNode.insertBefore(stopButton, link.nextSibling);

    const collectChunks = () => {
        const root = document.getElementById(config.readid) || document.body;
        const all = [];
        collectFrom(root, all);
        const capped = [];
        let total = 0;
        for (let i = 0; i < all.length; i++) {
            if (config.maxlen && total >= config.maxlen) {
                break;
            }
            capped.push(all[i]);
            total += all[i].text.length;
        }
        return capped;
    };

    const finish = () => {
        unhighlight(currentEl);
        currentEl = null;
        state = STATE_IDLE;
        paused = false;
        gateResolve = null;
        stopButton.style.display = 'none';
        setLabel(config.labels.listen);
    };

    const playChunks = async () => {
        stopped = false;
        setLabel(config.labels.loading);

        const chunks = collectChunks();
        if (!chunks.length) {
            finish();
            return;
        }

        // Pre-fetch the first chunk, then keep one chunk ahead while playing.
        let prepared = engine.prepare(chunks[0].text).catch(() => null);
        for (let i = 0; i < chunks.length; i++) {
            if (stopped) {
                break;
            }
            let token = null;
            try {
                token = await prepared;
            } catch (e) {
                token = null;
            }
            const next = (i + 1 < chunks.length && !stopped)
                ? engine.prepare(chunks[i + 1].text).catch(() => null)
                : Promise.resolve(null);

            // Honour a pause requested while this chunk was being synthesised.
            if (token !== null) {
                await waitWhilePaused();
            }
            if (stopped) {
                if (token !== null) {
                    engine.release(token);
                }
                prepared = next;
                break;
            }
            if (token !== null) {
                currentEl = chunks[i].el;
                highlight(currentEl);
                setLabel(config.labels.pause);
                stopButton.style.display = '';
                await engine.play(token);
                unhighlight(currentEl);
                currentEl = null;
                engine.release(token);
            }
            prepared = next;
        }

        // Release a chunk that was pre-fetched but never played (e.g. on stop).
        try {
            const leftover = await prepared;
            if (leftover) {
                engine.release(leftover);
            }
        } catch (e) {
            // Ignore.
        }
        finish();
    };

    link.addEventListener('click', (e) => {
        e.preventDefault();
        if (state === STATE_IDLE) {
            state = STATE_PLAYING;
            playChunks();
        } else if (state === STATE_PLAYING) {
            paused = true;
            state = STATE_PAUSED;
            engine.pause();
            setLabel(config.labels.resume);
        } else if (state === STATE_PAUSED) {
            paused = false;
            state = STATE_PLAYING;
            engine.resume();
            // Release the runner if it is waiting between chunks.
            openGate();
            setLabel(config.labels.pause);
        }
    });

    stopButton.addEventListener('click', (e) => {
        e.preventDefault();
        stopped = true;
        paused = false;
        engine.stop();
        openGate();
    });

    // Stop playback when leaving the page.
    window.addEventListener('beforeunload', () => {
        stopped = true;
        paused = false;
        engine.stop();
        openGate();
    });
};
