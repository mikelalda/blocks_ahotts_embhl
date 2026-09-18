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
 * Reads the configured page region chunk by chunk for near real-time playback,
 * underlining the chunk currently being spoken. The next chunk is synthesised
 * while the current one plays, which is what makes playback feel immediate.
 *
 * Each language is spoken by the first engine that works for it:
 *   - a Piper voice running locally in the browser (see ./piper), where one is
 *     published for that language;
 *   - the browser's own Web Speech API behind it, except for Basque, which no
 *     browser can speak;
 *   - the aHoTTS API last, when one is configured for that language.
 *
 * Which language is read is decided by the configured language mode: a fixed
 * language, the language of the Moodle interface, the lang attribute of the
 * content, or an explicit choice made by the reader in the block.
 *
 * Content is gathered from the page and from nested same-origin iframes (e.g. a
 * SCORM package served by Moodle). A cross-origin iframe cannot be read: the
 * Same-Origin Policy forbids it and there is no legitimate way around that. Such
 * a frame is only readable when the package itself ships the cooperative
 * postMessage bridge (bridge/ahotts-scorm-bridge.js) and its origin is listed in
 * the plugin settings; otherwise an accessible status message explains that the
 * external content cannot be read.
 *
 * @module     block_ahotts_embhl/speech
 * @copyright  2026 Ahotts
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {createPiperEngine} from './piper';

const STATE_IDLE = 'idle';
const STATE_PLAYING = 'playing';
const STATE_PAUSED = 'paused';

// Target size (characters) for each synthesised chunk. Small chunks start
// playing sooner, which is what makes playback feel real-time.
const CHUNK_TARGET = 160;

// Block-level tags whose text is read as a unit (one "line").
const BLOCK_SELECTOR = 'p,li,h1,h2,h3,h4,h5,h6,td,th,dd,dt,blockquote,figcaption,caption,pre';

// Nothing inside these is read: the reader's own controls, Moodle chrome and
// anything hidden from view.
const SKIP_SELECTOR = [
    '#readspeaker_button1',
    '.rs_skip',
    '.ahotts-status',
    '.ahotts-controls',
    '.block_ahotts_embhl',
    'nav',
    '[role="navigation"]',
    '.navbar',
    '.breadcrumb',
    '#nav-drawer',
    '.drawer',
    '.skiplinks',
    '.accesshide',
    '.sr-only',
    '.visually-hidden',
    '[hidden]',
    '[aria-hidden="true"]',
    'script',
    'style',
    'noscript',
    'template'
].join(',');

// How deep nested same-origin iframes are followed.
const MAX_FRAME_DEPTH = 8;

// Cooperative bridge protocol (see bridge/ahotts-scorm-bridge.js).
const PROTOCOL = 'ahotts-scorm-bridge';
const PROTOCOL_VERSION = 1;

// Chunk ids accepted from a bridge.
const CHUNK_ID_PATTERN = /^c[0-9]{1,9}$/;

/**
 * Whether the browser supports the Web Speech API.
 *
 * @return {Boolean}
 */
const hasWebSpeech = () => 'speechSynthesis' in window
    && typeof window.SpeechSynthesisUtterance !== 'undefined';

/**
 * A short unguessable identifier, used for the session and request ids that tie
 * a bridge answer to the question we asked.
 *
 * @param {String} prefix
 * @return {String}
 */
const randomId = (prefix) => {
    let random = '';
    const crypto = window.crypto || window.msCrypto;
    if (crypto && crypto.getRandomValues) {
        const buffer = new Uint32Array(3);
        crypto.getRandomValues(buffer);
        random = Array.prototype.map.call(buffer, (n) => n.toString(36)).join('');
    } else {
        random = Math.random().toString(36).slice(2) + Date.now().toString(36);
    }
    return prefix + '-' + random;
};

/**
 * The origin of a URL, resolved against the current document.
 *
 * @param {String} url
 * @return {String} Origin, or '' when it has none (about:blank, srcdoc, data:).
 */
const parseOrigin = (url) => {
    if (!url) {
        return '';
    }
    try {
        const parsed = new URL(url, window.location.href);
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
            return '';
        }
        return parsed.origin;
    } catch (e) {
        return '';
    }
};

/**
 * Whether an origin is on the configured allow list.
 *
 * Entries are exact origins ("https://scorm.example.org"); a single leading
 * "*." wildcard in the host is supported ("https://*.example.org"), and matches
 * sub-domains only, never the bare domain or a different scheme or port.
 *
 * @param {String} origin
 * @param {Array<String>} allowed
 * @return {Boolean}
 */
const originAllowed = (origin, allowed) => {
    if (!origin || !allowed || !allowed.length) {
        return false;
    }
    return allowed.some((entry) => {
        const candidate = String(entry).trim().replace(/\/+$/, '');
        if (!candidate) {
            return false;
        }
        if (candidate === origin) {
            return true;
        }
        const wildcard = candidate.match(/^(https?:\/\/)\*\.(.+)$/);
        if (!wildcard) {
            return false;
        }
        const suffix = wildcard[1] + '.' + wildcard[2];
        return origin.length > suffix.length - 1
            && origin.indexOf(wildcard[1]) === 0
            && origin.slice(wildcard[1].length).endsWith('.' + wildcard[2]);
    });
};

/**
 * Whether an element is currently visible.
 *
 * @param {Element} el
 * @return {Boolean}
 */
const isVisible = (el) => {
    if (!el || !el.getClientRects) {
        return false;
    }
    if (!(el.offsetWidth || el.offsetHeight || el.getClientRects().length)) {
        return false;
    }
    const view = el.ownerDocument && el.ownerDocument.defaultView;
    if (view && view.getComputedStyle) {
        const style = view.getComputedStyle(el);
        if (style && (style.visibility === 'hidden' || style.display === 'none')) {
            return false;
        }
    }
    return true;
};

/**
 * Split a piece of text into chunks, breaking on line breaks first and then on
 * sentence boundaries so that no chunk is much larger than CHUNK_TARGET.
 *
 * @param {String} raw
 * @return {Array<String>}
 */
const splitText = (raw) => {
    const out = [];
    const lines = String(raw || '').split(/\n+/).map((s) => s.replace(/\s+/g, ' ').trim()).filter(Boolean);
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
 * same-origin iframes (SCORM content) and recording cross-origin ones so the
 * caller can try the cooperative bridge on them.
 *
 * Entries pushed to `out` are either
 *   {kind: 'local', el: Element, text: String}
 * or a placeholder for a frame we cannot read directly
 *   {kind: 'frame', frame: HTMLIFrameElement}
 * which keeps the reading order right once the frame has answered.
 *
 * @param {Element} root
 * @param {Array} out Accumulator.
 * @param {Object} [context] {seen: Object, depth: Number}
 */
const collectFrom = (root, out, context) => {
    const ctx = context || {};
    const seen = ctx.seen || (ctx.seen = Object.create(null));
    const depth = ctx.depth || 0;

    if (!root || !root.querySelectorAll || depth > MAX_FRAME_DEPTH) {
        return;
    }

    let elements = Array.prototype.slice.call(root.querySelectorAll(BLOCK_SELECTOR));
    elements = elements.filter((el) => {
        if (el.closest && el.closest(SKIP_SELECTOR)) {
            return false;
        }
        const text = el.innerText || el.textContent || '';
        return text.trim() !== '' && isVisible(el);
    });
    // Keep only leaf blocks: drop any element that contains another collected one.
    elements = elements.filter((el) => !elements.some((other) => other !== el && el.contains(other)));

    // Walk the tree once so that text and frames come out in reading order.
    const frames = Array.prototype.slice.call(root.querySelectorAll('iframe,frame'))
        .filter((frame) => !(frame.closest && frame.closest(SKIP_SELECTOR)));
    const ordered = elements.concat(frames);
    ordered.sort((a, b) => {
        if (a === b) {
            return 0;
        }
        const position = a.compareDocumentPosition(b);
        /* eslint-disable-next-line no-bitwise */
        return (position & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1;
    });

    ordered.forEach((el) => {
        if (frames.indexOf(el) !== -1) {
            let doc = null;
            try {
                doc = el.contentDocument || (el.contentWindow && el.contentWindow.document);
            } catch (e) {
                doc = null; // Cross-origin: the DOM is not readable from here.
            }
            if (doc && doc.body) {
                collectFrom(doc.body, out, {seen: seen, depth: depth + 1});
            } else if (el.contentWindow) {
                out.push({kind: 'frame', frame: el});
            }
            return;
        }

        splitText(el.innerText || el.textContent || '').forEach((text) => {
            const key = text.toLowerCase();
            if (seen[key]) {
                return;
            }
            seen[key] = true;
            out.push({kind: 'local', el: el, text: text});
        });
    });
};

/**
 * Apply / remove the underline highlight on a chunk's element. Inline styles are
 * used so it also works inside iframe documents.
 *
 * @param {Element} el
 */
const highlight = (el) => {
    if (!el || !el.style) {
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
    if (!el || !el.style || typeof el.dataset.ahottsTextdecoration === 'undefined') {
        return;
    }
    el.style.textDecoration = el.dataset.ahottsTextdecoration;
    el.style.textDecorationThickness = '';
    el.style.textUnderlineOffset = '';
    delete el.dataset.ahottsTextdecoration;
};

/**
 * Client for the cooperative SCORM bridge.
 *
 * Security model:
 *  - a frame is only contacted when the origin of its src is on the configured
 *    allow list, or when it has no readable src (about:blank, srcdoc) in which
 *    case the discovery message - and only that one - goes to '*';
 *  - an answer is accepted only when event.origin is on the allow list, and
 *    event.source is exactly the contentWindow we registered for that frame;
 *  - the session id and the per-request id must match what we sent;
 *  - after the handshake every message is posted to the validated origin;
 *  - nothing that arrives is interpreted as markup or code: chunk ids are
 *    matched against a strict pattern and text is only ever used as text.
 *
 * @param {Object} options {win, allowedOrigins, timeout, onPageChanged}
 * @return {Object}
 */
const createBridgeClient = (options) => {
    const win = options.win || window;
    const allowedOrigins = options.allowedOrigins || [];
    const timeout = options.timeout || 2500;
    const session = randomId('ahotts');

    // Frame element -> connection state.
    const connections = new Map();
    // Request id -> {resolve, type, connection}.
    const pending = new Map();
    // Frames that announced themselves before we asked.
    const announced = new Set();

    const forSource = (source) => {
        let found = null;
        connections.forEach((connection) => {
            if (connection.win === source) {
                found = connection;
            }
        });
        return found;
    };

    const settle = (requestId, value) => {
        const entry = pending.get(requestId);
        if (!entry) {
            return;
        }
        pending.delete(requestId);
        window.clearTimeout(entry.timer);
        entry.resolve(value);
    };

    const onMessage = (event) => {
        const data = event.data;
        if (!data || typeof data !== 'object' || data.protocol !== PROTOCOL) {
            return;
        }
        if (data.v !== PROTOCOL_VERSION || typeof data.type !== 'string') {
            return;
        }
        // The sender has to be a window we registered, from an allowed origin.
        if (!originAllowed(event.origin, allowedOrigins)) {
            return;
        }

        if (data.type === 'ready') {
            announced.add(event.source);
            const waiting = forSource(event.source);
            if (waiting && waiting.onReady) {
                waiting.onReady();
            }
            return;
        }

        const connection = forSource(event.source);
        if (!connection || data.session !== session) {
            return;
        }

        if (data.type === 'hello-ack') {
            if (connection.nonce && data.nonce !== connection.nonce) {
                return;
            }
            // Bind the connection to the origin that answered; everything from
            // here on is posted to this origin only.
            connection.origin = event.origin;
            connection.ready = true;
            settle(connection.handshakeId, connection);
            return;
        }

        // Past the handshake nothing is accepted from another origin.
        if (!connection.ready || event.origin !== connection.origin) {
            return;
        }

        if (data.type === 'page-changed') {
            connection.chunkIds = new Set();
            if (options.onPageChanged) {
                options.onPageChanged(connection);
            }
            return;
        }

        if (typeof data.requestId !== 'string' || !pending.has(data.requestId)) {
            return;
        }
        const entry = pending.get(data.requestId);
        if (entry.connection !== connection) {
            return;
        }

        if (data.type === 'chunks' && Array.isArray(data.chunks)) {
            const chunks = [];
            data.chunks.forEach((chunk) => {
                if (!chunk || typeof chunk !== 'object') {
                    return;
                }
                if (typeof chunk.id !== 'string' || !CHUNK_ID_PATTERN.test(chunk.id)) {
                    return;
                }
                if (typeof chunk.text !== 'string') {
                    return;
                }
                const text = chunk.text.replace(/\s+/g, ' ').trim();
                if (!text) {
                    return;
                }
                connection.chunkIds.add(chunk.id);
                chunks.push({id: chunk.id, text: text});
            });
            settle(data.requestId, chunks);
            return;
        }

        if (data.type === 'ack') {
            settle(data.requestId, !!data.ok);
            return;
        }

        if (data.type === 'error') {
            settle(data.requestId, null);
        }
    };

    win.addEventListener('message', onMessage, false);

    /**
     * Post to a connected bridge, always to its validated origin.
     *
     * @param {Object} connection
     * @param {Object} payload
     */
    const post = (connection, payload) => {
        payload.protocol = PROTOCOL;
        payload.v = PROTOCOL_VERSION;
        payload.session = session;
        try {
            connection.win.postMessage(payload, connection.origin);
        } catch (e) {
            // A frame that went away: nothing to do.
        }
    };

    /**
     * Send a request and wait for its answer.
     *
     * @param {Object} connection
     * @param {Object} payload
     * @param {*} fallback Value resolved on timeout.
     * @return {Promise}
     */
    const request = (connection, payload, fallback) => new Promise((resolve) => {
        const requestId = randomId('req');
        payload.requestId = requestId;
        const timer = window.setTimeout(() => {
            pending.delete(requestId);
            resolve(fallback);
        }, timeout);
        pending.set(requestId, {resolve: resolve, timer: timer, connection: connection});
        post(connection, payload);
    });

    return {
        session: session,

        /**
         * Handshake with the bridge of a cross-origin frame.
         *
         * @param {HTMLIFrameElement} frame
         * @return {Promise<Object|null>} The connection, or null when there is no bridge.
         */
        connect: (frame) => {
            const existing = connections.get(frame);
            if (existing && existing.ready) {
                return Promise.resolve(existing);
            }
            if (existing && existing.promise) {
                return existing.promise;
            }

            const source = frame.contentWindow;
            if (!source) {
                return Promise.resolve(null);
            }

            const declared = parseOrigin(frame.getAttribute && frame.getAttribute('src'));
            if (declared && !originAllowed(declared, allowedOrigins)) {
                // Not an origin the administrator trusts: do not even talk to it.
                return Promise.resolve(null);
            }

            const connection = {
                frame: frame,
                win: source,
                // Until the handshake succeeds we only know the origin when the
                // frame declares one; a frame without a readable src has to be
                // discovered with '*'. Nothing but the protocol name, the
                // version and a random nonce travels in that first message.
                origin: declared || '*',
                declared: declared,
                ready: false,
                chunkIds: new Set(),
                nonce: randomId('n'),
                handshakeId: randomId('hs')
            };
            connections.set(frame, connection);

            connection.promise = new Promise((resolve) => {
                let attempts = 0;
                let timer = null;
                const done = (value) => {
                    window.clearTimeout(timer);
                    connection.promise = null;
                    resolve(value);
                };
                const entryResolve = (value) => done(value || null);
                pending.set(connection.handshakeId, {
                    resolve: entryResolve,
                    timer: window.setTimeout(() => {}, 0),
                    connection: connection
                });

                const hello = () => {
                    attempts += 1;
                    post(connection, {type: 'hello', nonce: connection.nonce});
                    if (connection.ready) {
                        return;
                    }
                    if (attempts * 300 >= timeout) {
                        pending.delete(connection.handshakeId);
                        connections.delete(frame);
                        done(null);
                        return;
                    }
                    timer = window.setTimeout(hello, 300);
                };
                // A bridge that finished loading late tells us so; answer at once.
                connection.onReady = () => {
                    if (!connection.ready) {
                        post(connection, {type: 'hello', nonce: connection.nonce});
                    }
                };
                if (announced.has(source)) {
                    connection.onReady();
                }
                hello();
            });

            return connection.promise;
        },

        /**
         * Ask a connected bridge for its readable chunks.
         *
         * @param {Object} connection
         * @return {Promise<Array>} [{id, text}]
         */
        collect: (connection) => request(connection, {type: 'collect'}, []),

        /**
         * Ask a bridge to underline one of its chunks.
         *
         * @param {Object} connection
         * @param {String} chunkId
         * @return {Promise<Boolean>}
         */
        highlight: (connection, chunkId) => {
            if (!CHUNK_ID_PATTERN.test(chunkId)) {
                return Promise.resolve(false);
            }
            return request(connection, {type: 'highlight', chunkId: chunkId}, false);
        },

        /**
         * Ask a bridge to remove a highlight.
         *
         * @param {Object} connection
         * @param {String} [chunkId]
         * @return {Promise<Boolean>}
         */
        unhighlight: (connection, chunkId) => {
            const payload = {type: 'unhighlight'};
            if (chunkId && CHUNK_ID_PATTERN.test(chunkId)) {
                payload.chunkId = chunkId;
            }
            return request(connection, payload, false);
        },

        /**
         * Drop every connection and stop listening.
         */
        destroy: () => {
            connections.forEach((connection) => {
                if (connection.ready) {
                    post(connection, {type: 'bye'});
                }
            });
            connections.clear();
            pending.forEach((entry) => window.clearTimeout(entry.timer));
            pending.clear();
            win.removeEventListener('message', onMessage, false);
        },

        // Exposed for the unit tests.
        _connections: connections
    };
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
 * The engines the block can speak with, by kind.
 *
 * Keeping them behind one registry is what lets the language configuration name
 * an engine as a plain string, and lets the tests exercise the selection and
 * fallback logic without a speech runtime.
 *
 * @type {Object}
 */
const engineFactories = {
    api: apiEngine,
    webspeech: webSpeechEngine,
    piper: createPiperEngine,
};

/**
 * Reduce a language tag to the base code the configuration uses ("eu_ES",
 * "eu-ES" and "EU" all become "eu").
 *
 * @param {String} value
 * @return {String}
 */
const normaliseLanguage = (value) => String(value || '')
    .toLowerCase()
    .replace(/_/g, '-')
    .split('-')[0]
    .trim();

/**
 * Remember a reader's choice between pages. Storage can be unavailable or full,
 * and neither is a reason to stop reading, so both directions are guarded.
 *
 * @param {String} key
 * @return {String}
 */
const readStored = (key) => {
    try {
        return window.localStorage.getItem(key) || '';
    } catch (e) {
        return '';
    }
};
const writeStored = (key, value) => {
    try {
        window.localStorage.setItem(key, value);
    } catch (e) {
        // Private mode, blocked storage: the choice simply is not remembered.
    }
};

/**
 * Decide which of the configured languages to read the page in.
 *
 * The mode is set by the administrator:
 *   fixed    the language configured for the site or the block;
 *   page     the language of the Moodle interface, resolved server side;
 *   content  the lang attribute the content itself declares;
 *   chooser  whatever the reader picked in the block, kept across pages.
 *
 * Whatever the mode, the answer is always one of the configured languages.
 *
 * @param {Object} config
 * @param {Document} doc
 * @param {String} [stored] The reader's remembered choice.
 * @return {Object} The chosen language entry.
 */
const pickLanguage = (config, doc, stored) => {
    const languages = config.languages || [];
    if (!languages.length) {
        return null;
    }
    const find = (code) => languages.find((entry) => entry.code === normaliseLanguage(code)) || null;

    if (config.langmode === 'chooser') {
        const chosen = find(stored);
        if (chosen) {
            return chosen;
        }
    }

    if (config.langmode === 'content') {
        const root = doc.getElementById(config.readid);
        const declared = (root && root.closest && root.closest('[lang]')) || doc.documentElement;
        const chosen = declared && declared.getAttribute ? find(declared.getAttribute('lang')) : null;
        if (chosen) {
            return chosen;
        }
    }

    // 'fixed' and 'page' are both already resolved into config.lang by the block.
    return find(config.lang) || languages[0];
};

/**
 * Accept the configuration shape used before languages became a list.
 *
 * @param {Object} config
 * @return {Object}
 */
const adaptLegacyConfig = (config) => {
    if (config.languages && config.languages.length) {
        return config;
    }
    const engines = [];
    if (config.prefer === 'api') {
        if (config.api && config.api.url) {
            engines.push(Object.assign({kind: 'api'}, config.api));
        }
    } else {
        engines.push({kind: 'webspeech'});
        if (config.api && config.api.url) {
            engines.push(Object.assign({kind: 'api'}, config.api));
        }
    }
    return Object.assign({}, config, {
        langmode: 'fixed',
        lang: 'default',
        languages: [{
            code: 'default',
            label: '',
            bcp47: config.webspeech ? config.webspeech.lang : '',
            engines: engines,
        }],
    });
};

/**
 * Initialise the Listen button behaviour.
 *
 * @param {Object} config
 * @param {String} config.readid    Id of the element whose text is read.
 * @param {Number} config.maxlen    Overall character cap across all chunks.
 * @param {Object} config.labels    Button labels (listen, loading, pause, resume, stop, language, voice).
 * @param {String} config.langmode  'fixed', 'page', 'content' or 'chooser'.
 * @param {String} config.lang      Base code of the language resolved server side.
 * @param {Array}  config.languages [{code, label, bcp47, engines: [{kind, ...}]}], in preference order.
 * @param {Object} [config.piper]   Piper settings {defaults, chooser, voices, backend, orturl, ...}.
 * @param {Object} [config.bridge]  Cross-origin bridge {enabled, origins, timeout}.
 * @param {Object} [config.status]  Status messages keyed by name.
 * @param {String} [config.prefer]  Legacy single-language shape: 'api' or 'webspeech'.
 * @param {Object} [config.api]     Legacy aHoTTS API parameters {url, language, voice}.
 * @param {Object} [config.webspeech] Legacy Web Speech parameters {lang}.
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

    config = adaptLegacyConfig(config);

    const labelNode = button.querySelector('.rsbtn_text span');
    const messages = config.status || {};
    const bridgeConfig = config.bridge || {};
    const piperConfig = config.piper || {};

    const STORAGE_LANGUAGE = 'block_ahotts_embhl_language';
    const STORAGE_VOICE = 'block_ahotts_embhl_voice';

    let language = pickLanguage(config, document, readStored(STORAGE_LANGUAGE));
    if (!language) {
        return;
    }
    const piperVoices = piperConfig.voices || [];
    const piperDefaults = piperConfig.defaults || {};
    // What the reader picked, per language, for as long as the page lives.
    const chosenVoices = {};

    const voicesFor = (code) => piperVoices.filter((voice) => voice.language === code);

    /**
     * The voice a language is read with: the reader's own choice, then the
     * administrator's default, then whatever is published for it.
     *
     * @param {String} code
     * @return {String}
     */
    const voiceIdFor = (code) => {
        if (!chosenVoices[code]) {
            const offered = voicesFor(code);
            const known = (id) => !!id && offered.some((voice) => voice.id === id);
            const stored = readStored(STORAGE_VOICE + ':' + code);
            chosenVoices[code] = (known(stored) && stored)
                || (known(piperDefaults[code]) && piperDefaults[code])
                || (offered.length ? offered[0].id : '');
        }
        return chosenVoices[code];
    };

    const piperVoiceFor = (code) => voicesFor(code).find((voice) => voice.id === voiceIdFor(code)) || null;
    const usesPiper = (entry) => (entry.engines || []).some((spec) => spec.kind === 'piper');

    /**
     * Whether an engine could be built at all, without building it.
     *
     * @param {Object} spec
     * @param {Object} entry The language it would speak.
     * @return {Boolean}
     */
    const couldRun = (spec, entry) => {
        if (spec.kind === 'webspeech') {
            return hasWebSpeech();
        }
        if (spec.kind === 'api') {
            return !!spec.url;
        }
        if (spec.kind === 'piper') {
            const voice = piperVoiceFor(entry.code);
            return !!(voice && voice.modelurl && piperConfig.orturl
                && piperConfig.phonemizerurl && piperConfig.loaderurl);
        }
        return false;
    };

    // Nothing can speak anything: leave the button alone rather than pretend.
    if (!config.languages.some((entry) => (entry.engines || []).some((spec) => couldRun(spec, entry)))) {
        return;
    }

    /**
     * Build the engine described by one spec.
     *
     * @param {Object} spec
     * @param {Object} entry The language it belongs to.
     * @return {Object|null}
     */
    const buildEngine = (spec, entry) => {
        if (spec.kind === 'webspeech') {
            return engineFactories.webspeech(entry.bcp47 || spec.lang || '');
        }
        if (spec.kind === 'api') {
            return engineFactories.api(spec);
        }
        if (spec.kind === 'piper') {
            const voice = piperVoiceFor(entry.code);
            return engineFactories.piper({
                modelurl: voice.modelurl,
                configurl: voice.configurl || '',
                // No language is forced: each model names the eSpeak voice it
                // was trained with, down to the region, and that one is right.
                backend: piperConfig.backend || 'auto',
                orturl: piperConfig.orturl,
                wasmpath: piperConfig.wasmpath || '',
                phonemizerurl: piperConfig.phonemizerurl,
                loaderurl: piperConfig.loaderurl,
            });
        }
        return null;
    };

    // A Piper voice downloads a model and opens an inference session, so engines
    // are built once and kept, one per language, kind and voice.
    const engines = new Map();
    const engineFor = (spec, entry) => {
        const key = entry.code + '|' + spec.kind + '|' + (spec.kind === 'piper' ? voiceIdFor(entry.code) : '');
        if (!engines.has(key)) {
            engines.set(key, buildEngine(spec, entry));
        }
        return engines.get(key);
    };

    let activeEngine = null;

    let state = STATE_IDLE;
    let stopped = false;
    let paused = false;
    let currentChunk = null;
    // When paused between chunks, the runner waits on this resolver.
    let gateResolve = null;

    const setLabel = (text) => {
        if (labelNode && text) {
            labelNode.textContent = text;
        }
    };

    // Accessible status line: screen readers announce changes politely.
    const status = document.createElement('p');
    status.className = 'ahotts-status small text-muted m-0';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    button.parentNode.insertBefore(status, button.nextSibling);

    const setStatus = (text) => {
        // textContent only: nothing that reaches here is ever treated as markup.
        status.textContent = text || '';
    };

    const bridge = bridgeConfig.enabled && bridgeConfig.origins && bridgeConfig.origins.length
        ? createBridgeClient({
            win: window,
            allowedOrigins: bridgeConfig.origins,
            timeout: bridgeConfig.timeout || 2500
        })
        : null;

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

    /**
     * Pick the first engine that actually works for a language, warming it up
     * when it needs to download a voice, and falling back down the list when it
     * cannot be started.
     *
     * @param {Object} entry
     * @return {Promise<Object|null>}
     */
    let engineNotice = '';
    const resolveEngine = async (entry) => {
        engineNotice = '';
        const specs = (entry.engines || []).filter((spec) => couldRun(spec, entry));
        for (let i = 0; i < specs.length; i++) {
            const engine = engineFor(specs[i], entry);
            if (!engine) {
                continue;
            }
            if (!engine.warmup) {
                return engine;
            }
            setStatus(messages.preparingvoice || '');
            /* eslint-disable-next-line no-await-in-loop */
            const ready = await engine.warmup();
            if (ready) {
                setStatus('');
                return engine;
            }
            // The local voice could not be started: say so and try the next one.
            engineNotice = messages.voicefallback || '';
        }
        return null;
    };

    const stopPlayback = () => {
        stopped = true;
        paused = false;
        if (activeEngine) {
            activeEngine.stop();
        }
        openGate();
    };

    // Optional controls: the language to read in, and the local Basque voice.
    const controls = document.createElement('div');
    controls.className = 'ahotts-controls mt-1';
    button.parentNode.insertBefore(controls, status);

    /**
     * Put a set of options into a select, keeping one of them chosen.
     *
     * @param {Element} select
     * @param {Array} options [{value, label}]
     * @param {String} selected
     */
    const fillSelect = (select, options, selected) => {
        select.textContent = '';
        options.forEach((option) => {
            const node = document.createElement('option');
            node.value = option.value;
            node.textContent = option.label;
            if (option.value === selected) {
                node.selected = true;
            }
            select.appendChild(node);
        });
    };

    /**
     * Add a labelled select to the controls.
     *
     * @param {String} classname
     * @param {String} text Visible label.
     * @param {Array} options [{value, label}]
     * @param {String} selected
     * @param {Function} onchange
     * @return {Object} {label, select}
     */
    const addSelect = (classname, text, options, selected, onchange) => {
        const id = classname + '-' + Math.random().toString(36).slice(2, 10);
        const label = document.createElement('label');
        label.className = 'mr-1 mb-0 small';
        label.setAttribute('for', id);
        label.textContent = text;

        const select = document.createElement('select');
        select.id = id;
        select.className = classname + ' custom-select custom-select-sm mr-2';
        fillSelect(select, options, selected);
        select.addEventListener('change', () => onchange(select.value));

        controls.appendChild(label);
        controls.appendChild(select);
        return {label: label, select: select};
    };

    let voiceControl = null;
    const syncVoiceControl = () => {
        if (!voiceControl) {
            return;
        }
        // Each language has its own voices, so the menu is rebuilt whenever the
        // reading language changes, and hidden when there is nothing to choose.
        const offered = voicesFor(language.code);
        const show = offered.length > 1 && usesPiper(language) && couldRun({kind: 'piper'}, language);
        voiceControl.label.hidden = !show;
        voiceControl.select.hidden = !show;
        if (show) {
            fillSelect(
                voiceControl.select,
                offered.map((voice) => ({value: voice.id, label: voice.label || voice.id})),
                voiceIdFor(language.code)
            );
        }
    };

    if (config.langmode === 'chooser' && config.languages.length > 1) {
        addSelect(
            'ahotts-language',
            config.labels.language || '',
            config.languages.map((entry) => ({value: entry.code, label: entry.label || entry.code})),
            language.code,
            (value) => {
                stopPlayback();
                language = config.languages.find((entry) => entry.code === value) || language;
                writeStored(STORAGE_LANGUAGE, language.code);
                syncVoiceControl();
            }
        );
    }

    if (piperConfig.chooser && piperVoices.length > 1) {
        voiceControl = addSelect(
            'ahotts-voice',
            config.labels.voice || '',
            voicesFor(language.code).map((voice) => ({value: voice.id, label: voice.label || voice.id})),
            voiceIdFor(language.code),
            (value) => {
                stopPlayback();
                chosenVoices[language.code] = value;
                writeStored(STORAGE_VOICE + ':' + language.code, value);
            }
        );
        syncVoiceControl();
    }

    // Explicit Stop control, shown only while playing.
    const stopButton = document.createElement('button');
    stopButton.type = 'button';
    stopButton.className = 'rsbtn_stop btn btn-link p-0 ml-1';
    stopButton.textContent = config.labels.stop;
    stopButton.style.display = 'none';
    link.parentNode.insertBefore(stopButton, link.nextSibling);

    const highlightChunk = (chunk) => {
        if (!chunk) {
            return;
        }
        if (chunk.kind === 'remote') {
            bridge.highlight(chunk.connection, chunk.chunkId);
            return;
        }
        highlight(chunk.el);
    };

    const unhighlightChunk = (chunk) => {
        if (!chunk) {
            return;
        }
        if (chunk.kind === 'remote') {
            bridge.unhighlight(chunk.connection, chunk.chunkId);
            return;
        }
        unhighlight(chunk.el);
    };

    /**
     * Gather everything readable: the page, its nested same-origin frames, and
     * any cross-origin frame that answers the bridge handshake.
     *
     * @return {Promise<Array>}
     */
    const collectChunks = async () => {
        const root = document.getElementById(config.readid) || document.body;
        const items = [];
        collectFrom(root, items, {seen: Object.create(null), depth: 0});

        // Start every handshake at once, then keep the reading order.
        const frames = items.filter((item) => item.kind === 'frame');
        let blocked = 0;
        const connected = new Map();
        if (frames.length) {
            if (!bridge) {
                blocked = frames.length;
            } else {
                await Promise.all(frames.map((item) => bridge.connect(item.frame)
                    .then((connection) => {
                        if (connection) {
                            connected.set(item.frame, connection);
                        } else {
                            blocked += 1;
                        }
                    })));
            }
        }

        const chunks = [];
        const seen = Object.create(null);
        let total = 0;
        const push = (chunk) => {
            if (config.maxlen && total >= config.maxlen) {
                return;
            }
            const key = chunk.text.toLowerCase();
            if (seen[key]) {
                return;
            }
            seen[key] = true;
            total += chunk.text.length;
            chunks.push(chunk);
        };

        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            if (item.kind === 'local') {
                push({kind: 'local', el: item.el, text: item.text});
                continue;
            }
            const connection = connected.get(item.frame);
            if (!connection) {
                continue;
            }
            /* eslint-disable-next-line no-await-in-loop */
            const remote = await bridge.collect(connection);
            remote.forEach((chunk) => {
                push({kind: 'remote', connection: connection, chunkId: chunk.id, text: chunk.text});
            });
        }

        return {chunks: chunks, blocked: blocked};
    };

    const finish = () => {
        unhighlightChunk(currentChunk);
        currentChunk = null;
        state = STATE_IDLE;
        paused = false;
        gateResolve = null;
        stopButton.style.display = 'none';
        setLabel(config.labels.listen);
    };

    const playChunks = async () => {
        stopped = false;
        setLabel(config.labels.loading);
        setStatus('');

        const notices = [];
        activeEngine = await resolveEngine(language);
        if (engineNotice) {
            notices.push(engineNotice);
        }
        if (!activeEngine) {
            notices.push(messages.enginesunavailable || '');
            setStatus(notices.filter(Boolean).join(' '));
            finish();
            return;
        }
        if (stopped) {
            finish();
            return;
        }

        const collected = await collectChunks();
        const chunks = collected.chunks;
        if (collected.blocked) {
            // The page holds an external frame we are not allowed to read: say so
            // rather than silently skipping part of the content.
            notices.push(messages.externalnobridge || '');
        }
        if (!chunks.length && !collected.blocked) {
            notices.push(messages.nocontent || '');
        }
        setStatus(notices.filter(Boolean).join(' '));
        if (!chunks.length) {
            finish();
            return;
        }

        const engine = activeEngine;

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
                currentChunk = chunks[i];
                highlightChunk(currentChunk);
                setLabel(config.labels.pause);
                stopButton.style.display = '';
                await engine.play(token);
                unhighlightChunk(currentChunk);
                currentChunk = null;
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
            if (activeEngine) {
                activeEngine.pause();
            }
            setLabel(config.labels.resume);
        } else if (state === STATE_PAUSED) {
            paused = false;
            state = STATE_PLAYING;
            if (activeEngine) {
                activeEngine.resume();
            }
            // Release the runner if it is waiting between chunks.
            openGate();
            setLabel(config.labels.pause);
        }
    });

    stopButton.addEventListener('click', (e) => {
        e.preventDefault();
        stopPlayback();
    });

    // Stop playback when leaving the page.
    window.addEventListener('beforeunload', () => {
        stopPlayback();
        if (bridge) {
            bridge.destroy();
        }
    });
};

// Internals exported for the unit tests only.
export const __testing = {
    BLOCK_SELECTOR,
    SKIP_SELECTOR,
    PROTOCOL,
    PROTOCOL_VERSION,
    collectFrom,
    createBridgeClient,
    engineFactories,
    highlight,
    adaptLegacyConfig,
    isVisible,
    normaliseLanguage,
    originAllowed,
    pickLanguage,
    parseOrigin,
    splitText,
    unhighlight,
};
