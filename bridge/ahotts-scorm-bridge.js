/*
 * aHoTTS SCORM bridge.
 *
 * Cooperative postMessage bridge that lets the Moodle block
 * block_ahotts_embhl read the text of a SCORM package served from a
 * different origin.
 *
 * The Same-Origin Policy prevents the Moodle page from reading the DOM of a
 * cross-origin iframe. This file is the supported way around that: it is
 * installed *inside* the SCORM package, so it runs in the SCORM origin, and it
 * answers a small, versioned, origin-validated message protocol.
 *
 * Install by adding, in every HTML page of the package, before </body>:
 *
 *     <script src="ahotts-scorm-bridge.js"
 *             data-ahotts-parents="https://moodle.example.org"></script>
 *
 * See bridge/README.md for the full instructions.
 *
 * This script never evaluates anything it receives: incoming messages are only
 * ever compared against a fixed set of literals, and outgoing payloads carry
 * plain text extracted with textContent.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function (global) {
    'use strict';

    var PROTOCOL = 'ahotts-scorm-bridge';
    var VERSION = 1;

    // Target size (characters) of a spoken chunk. Kept in sync with the
    // CHUNK_TARGET used by amd/src/speech.js on the Moodle side.
    var CHUNK_TARGET = 160;

    // Block-level tags whose text is read as a unit.
    var BLOCK_SELECTOR = 'p,li,h1,h2,h3,h4,h5,h6,td,th,dd,dt,blockquote,figcaption,caption,pre';

    // Never read anything inside these.
    var SKIP_SELECTOR = [
        'script', 'style', 'noscript', 'template',
        '[hidden]', '[aria-hidden="true"]',
        '.ahotts-skip', '[data-ahotts-skip]'
    ].join(',');

    // How deep we follow same-origin iframes nested inside the package.
    var MAX_DEPTH = 8;

    // Hard cap on the number of chunks returned by one collect request.
    var MAX_CHUNKS = 4000;

    // Chunk ids we accept back from the parent.
    var ID_PATTERN = /^c[0-9]{1,9}$/;

    // Debounce (ms) for the page-changed notification.
    var CHANGE_DEBOUNCE = 400;

    var doc = global.document;

    /**
     * Read the configuration from the script tag or from a global set before load.
     *
     * @return {Object} {parentOrigins: Array<String>, debug: Boolean}
     */
    var readConfig = function () {
        var config = {parentOrigins: [], debug: false};
        var preset = global.AHOTTS_BRIDGE_CONFIG;
        if (preset && typeof preset === 'object') {
            if (Object.prototype.toString.call(preset.parentOrigins) === '[object Array]') {
                config.parentOrigins = preset.parentOrigins.slice();
            }
            config.debug = !!preset.debug;
        }
        var script = doc.currentScript;
        if (!script && doc.querySelectorAll) {
            var tags = doc.querySelectorAll('script[data-ahotts-parents]');
            script = tags.length ? tags[tags.length - 1] : null;
        }
        if (script && script.getAttribute) {
            var parents = script.getAttribute('data-ahotts-parents');
            if (parents) {
                config.parentOrigins = parents.split(/[\s,]+/);
            }
            if (script.getAttribute('data-ahotts-debug') === '1') {
                config.debug = true;
            }
        }
        config.parentOrigins = config.parentOrigins
            .map(function (origin) {
                return String(origin).trim().replace(/\/+$/, '');
            })
            .filter(Boolean);
        return config;
    };

    var config = readConfig();

    var log = function (message) {
        if (config.debug && global.console && global.console.log) {
            global.console.log('[ahotts-bridge] ' + message);
        }
    };

    /**
     * Whether an origin is allowed to drive this bridge.
     *
     * With no configured list every embedder is accepted, which is what makes a
     * package work in any Moodle site; configure data-ahotts-parents to lock the
     * package to the sites that are allowed to read it.
     *
     * @param {String} origin
     * @return {Boolean}
     */
    var isAllowedParent = function (origin) {
        if (!origin || origin === 'null') {
            return false;
        }
        if (!config.parentOrigins.length) {
            return true;
        }
        return config.parentOrigins.indexOf(origin.replace(/\/+$/, '')) !== -1;
    };

    // The single parent this bridge talks to, bound by a successful handshake.
    // {origin: String, win: Window, session: String}
    var peer = null;

    // Chunk id -> element, rebuilt on every collect request.
    var index = {};
    var sequence = 0;

    // Elements whose inline style we changed, so the highlight can be undone.
    var highlighted = [];

    /**
     * Whether an element is rendered and readable.
     *
     * @param {Element} el
     * @return {Boolean}
     */
    var isVisible = function (el) {
        if (!el || !el.getClientRects) {
            return false;
        }
        if (!(el.offsetWidth || el.offsetHeight || el.getClientRects().length)) {
            return false;
        }
        var view = el.ownerDocument && el.ownerDocument.defaultView;
        if (view && view.getComputedStyle) {
            var style = view.getComputedStyle(el);
            if (style && (style.visibility === 'hidden' || style.display === 'none' || style.opacity === '0')) {
                return false;
            }
        }
        return true;
    };

    /**
     * Split a block of text into chunks on line breaks, then sentences.
     *
     * @param {String} raw
     * @return {Array<String>}
     */
    var splitText = function (raw) {
        var out = [];
        var lines = String(raw || '').split(/\n+/).map(function (line) {
            return line.replace(/\s+/g, ' ').trim();
        }).filter(Boolean);

        lines.forEach(function (line) {
            if (line.length <= CHUNK_TARGET) {
                out.push(line);
                return;
            }
            var sentences = line.match(/[^.!?]+[.!?]*/g) || [line];
            var buffer = '';
            sentences.forEach(function (sentence) {
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
     * Collect readable chunks from a document, descending into same-origin
     * iframes nested inside the package.
     *
     * @param {Document} document_ Document to read.
     * @param {Array} out Accumulator of {id, text} entries.
     * @param {Object} seen Map of already emitted texts, to drop duplicates.
     * @param {Number} depth Current iframe depth.
     */
    var collect = function (document_, out, seen, depth) {
        if (!document_ || !document_.body || depth > MAX_DEPTH || out.length >= MAX_CHUNKS) {
            return;
        }

        var elements = Array.prototype.slice.call(document_.body.querySelectorAll(BLOCK_SELECTOR));
        elements = elements.filter(function (el) {
            if (el.closest && el.closest(SKIP_SELECTOR)) {
                return false;
            }
            var text = el.innerText || el.textContent || '';
            return text.trim() !== '' && isVisible(el);
        });
        // Keep only leaf blocks: drop any element that contains another collected one.
        elements = elements.filter(function (el) {
            return !elements.some(function (other) {
                return other !== el && el.contains(other);
            });
        });

        elements.forEach(function (el) {
            splitText(el.innerText || el.textContent || '').forEach(function (text) {
                if (out.length >= MAX_CHUNKS) {
                    return;
                }
                var key = text.toLowerCase();
                if (seen[key]) {
                    return;
                }
                seen[key] = true;
                sequence += 1;
                var id = 'c' + sequence;
                index[id] = el;
                out.push({id: id, text: text});
            });
        });

        var frames = document_.querySelectorAll('iframe,frame');
        Array.prototype.forEach.call(frames, function (frame) {
            var child = null;
            try {
                child = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
            } catch (e) {
                child = null; // Cross-origin inside the package: not readable from here.
            }
            if (child) {
                collect(child, out, seen, depth + 1);
            }
        });
    };

    /**
     * Underline the element of a chunk and scroll it into view.
     *
     * @param {String} id
     * @return {Boolean} Whether the chunk was known.
     */
    var highlight = function (id) {
        var el = Object.prototype.hasOwnProperty.call(index, id) ? index[id] : null;
        if (!el || !el.style) {
            return false;
        }
        if (typeof el.dataset.ahottsTextdecoration === 'undefined') {
            el.dataset.ahottsTextdecoration = el.style.textDecoration || '';
            highlighted.push(el);
        }
        el.style.textDecoration = 'underline';
        el.style.textDecorationThickness = '2px';
        el.style.textUnderlineOffset = '2px';
        try {
            el.scrollIntoView({block: 'nearest', behavior: 'smooth'});
        } catch (e) {
            el.scrollIntoView();
        }
        return true;
    };

    /**
     * Remove the highlight from one chunk, or from every chunk when id is empty.
     *
     * @param {String} [id]
     */
    var unhighlight = function (id) {
        var targets = highlighted;
        if (id && Object.prototype.hasOwnProperty.call(index, id)) {
            targets = [index[id]];
        }
        targets.slice().forEach(function (el) {
            if (!el || !el.style || typeof el.dataset.ahottsTextdecoration === 'undefined') {
                return;
            }
            el.style.textDecoration = el.dataset.ahottsTextdecoration;
            el.style.textDecorationThickness = '';
            el.style.textUnderlineOffset = '';
            delete el.dataset.ahottsTextdecoration;
            var at = highlighted.indexOf(el);
            if (at !== -1) {
                highlighted.splice(at, 1);
            }
        });
    };

    /**
     * Post a message to the bound parent, always to its validated origin.
     *
     * @param {Object} payload
     */
    var reply = function (payload) {
        if (!peer) {
            return;
        }
        payload.protocol = PROTOCOL;
        payload.v = VERSION;
        payload.session = peer.session;
        try {
            peer.win.postMessage(payload, peer.origin);
        } catch (e) {
            log('post failed: ' + e);
        }
    };

    /**
     * Whether a message is a well formed request from the bound parent.
     *
     * @param {MessageEvent} event
     * @param {Object} data
     * @return {Boolean}
     */
    var isFromPeer = function (event, data) {
        return !!peer
            && event.source === peer.win
            && event.origin === peer.origin
            && data.session === peer.session;
    };

    var onMessage = function (event) {
        var data = event.data;
        if (!data || typeof data !== 'object' || data.protocol !== PROTOCOL) {
            return;
        }
        if (data.v !== VERSION || typeof data.type !== 'string') {
            return;
        }
        // Only the window that embeds us may drive the bridge.
        if (event.source !== global.parent) {
            return;
        }
        if (!isAllowedParent(event.origin)) {
            log('rejected origin ' + event.origin);
            return;
        }

        if (data.type === 'hello') {
            if (typeof data.session !== 'string' || !data.session) {
                return;
            }
            peer = {origin: event.origin, win: event.source, session: data.session};
            log('handshake with ' + event.origin);
            reply({
                type: 'hello-ack',
                nonce: typeof data.nonce === 'string' ? data.nonce : '',
                bridge: VERSION,
                capabilities: ['collect', 'highlight', 'unhighlight', 'page-changed']
            });
            return;
        }

        if (!isFromPeer(event, data)) {
            return;
        }

        var requestId = typeof data.requestId === 'string' ? data.requestId : '';

        if (data.type === 'collect') {
            var chunks = [];
            index = {};
            sequence = 0;
            highlighted = [];
            try {
                collect(doc, chunks, {}, 0);
            } catch (e) {
                reply({type: 'error', requestId: requestId, code: 'collect-failed'});
                return;
            }
            reply({
                type: 'chunks',
                requestId: requestId,
                chunks: chunks,
                truncated: chunks.length >= MAX_CHUNKS
            });
            return;
        }

        if (data.type === 'highlight') {
            var id = typeof data.chunkId === 'string' ? data.chunkId : '';
            if (!ID_PATTERN.test(id)) {
                reply({type: 'ack', requestId: requestId, ok: false});
                return;
            }
            unhighlight();
            reply({type: 'ack', requestId: requestId, ok: highlight(id)});
            return;
        }

        if (data.type === 'unhighlight') {
            var target = typeof data.chunkId === 'string' && ID_PATTERN.test(data.chunkId) ? data.chunkId : '';
            unhighlight(target);
            reply({type: 'ack', requestId: requestId, ok: true});
            return;
        }

        if (data.type === 'bye') {
            unhighlight();
            peer = null;
        }
    };

    /**
     * Tell the parent the page changed, so it re-collects before the next play.
     */
    var changeTimer = null;
    var notifyChange = function () {
        if (!peer || changeTimer) {
            return;
        }
        changeTimer = global.setTimeout(function () {
            changeTimer = null;
            reply({type: 'page-changed'});
        }, CHANGE_DEBOUNCE);
    };

    /**
     * Announce the bridge to the embedder, for the case where the package
     * finishes loading after the parent gave up asking.
     *
     * The announcement carries nothing but the protocol name and version. When
     * no parent origin is configured it has to go to '*', because at that point
     * the embedder origin is unknown; everything after the handshake goes to the
     * validated origin only.
     */
    var announce = function () {
        var payload = {protocol: PROTOCOL, v: VERSION, type: 'ready'};
        var targets = config.parentOrigins.length ? config.parentOrigins : ['*'];
        targets.forEach(function (target) {
            try {
                global.parent.postMessage(payload, target);
            } catch (e) {
                log('announce failed: ' + e);
            }
        });
    };

    var start = function () {
        // Only useful inside a frame; a package opened on its own has no parent to talk to.
        if (global.top === global) {
            return;
        }
        global.addEventListener('message', onMessage, false);

        if (global.MutationObserver && doc.body) {
            var observer = new global.MutationObserver(notifyChange);
            observer.observe(doc.body, {childList: true, subtree: true, characterData: true});
        }
        global.addEventListener('hashchange', notifyChange, false);
        global.addEventListener('popstate', notifyChange, false);
        global.addEventListener('pagehide', function () {
            if (peer) {
                reply({type: 'page-changed'});
            }
        }, false);

        announce();
        log('ready');
    };

    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', start, false);
    } else {
        start();
    }
}(window));
