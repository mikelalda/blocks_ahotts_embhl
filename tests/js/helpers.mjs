/**
 * Test helpers: load the plugin front-end into a jsdom window.
 *
 * amd/src/speech.js is an ES module and bridge/ahotts-scorm-bridge.js is a plain
 * script; both are evaluated inside a jsdom window so they see a real DOM,
 * without pulling in a bundler.
 */
import fs from 'node:fs';
import {JSDOM} from 'jsdom';

const root = new URL('../../', import.meta.url);

/**
 * jsdom has no layout, so every element would look invisible. Give elements a
 * box unless the test marks them hidden, which is what the real browser does.
 *
 * @param {Window} window
 */
export const stubLayout = (window) => {
    Object.defineProperty(window.Element.prototype, 'getClientRects', {
        configurable: true,
        value() {
            const style = this.getAttribute('style') || '';
            if (/display:\s*none|visibility:\s*hidden/.test(style)) {
                return [];
            }
            return [{width: 100, height: 20}];
        },
    });
    Object.defineProperty(window.Element.prototype, 'scrollIntoView', {
        configurable: true,
        value() {},
    });
};

/**
 * Build a jsdom window.
 *
 * @param {String} html
 * @param {String} url
 * @return {JSDOM}
 */
export const makeDom = (html, url = 'https://moodle.example.org/course/view.php') => {
    const dom = new JSDOM(html, {url, runScripts: 'outside-only', pretendToBeVisual: true});
    stubLayout(dom.window);
    return dom;
};

/**
 * Load amd/src/speech.js into a jsdom window and return its exports.
 *
 * @param {String} html Body markup for the page.
 * @param {String} [url] Page URL, which fixes the document origin.
 * @return {Object} {dom, window, document, init, testing}
 */
export const loadSpeech = (html, url) => {
    const dom = makeDom(html, url);
    // speech.js imports the Piper engine; concatenating the two modules puts
    // createPiperEngine in the same scope, which is what the AMD build does too.
    const piper = fs.readFileSync(new URL('amd/src/piper.js', root), 'utf8')
        .replace(/^export const /gm, 'const ');
    const speech = fs.readFileSync(new URL('amd/src/speech.js', root), 'utf8')
        .replace(/^import \{[^}]*\} from '\.\/piper';\n/m, '')
        .replace(/^export const /gm, 'const ');
    dom.window.eval(piper + '\n' + speech
        + '\n;window.__speech = {init: init, testing: __testing};'
        + '\n;window.__piper = {phonemesToIds, encodeWav, createPiperEngine, hasWebGpu};');
    return {
        dom,
        window: dom.window,
        document: dom.window.document,
        init: dom.window.__speech.init,
        testing: dom.window.__speech.testing,
        piper: dom.window.__piper,
    };
};

/**
 * Load bridge/ahotts-scorm-bridge.js into a real nested browsing context, so the
 * bridge sees window.top !== window exactly as it would inside a SCORM player.
 *
 * Everything the bridge posts to its embedder is recorded on `parent.sent`.
 *
 * @param {String} html Body markup for the SCORM page.
 * @param {Object} options {parentUrl, parents}
 * @return {Object} {dom, window, document, parent}
 */
export const loadBridge = (html, options = {}) => {
    const parentUrl = options.parentUrl || 'https://moodle.example.org/mod/scorm/player.php';
    const dom = makeDom('<!doctype html><html><body><iframe id="ahotts-frame"></iframe></body></html>', parentUrl);
    const parentWindow = dom.window;

    const sent = [];
    Object.defineProperty(parentWindow, 'postMessage', {
        configurable: true,
        writable: true,
        value(data, origin) {
            sent.push({data, origin});
        },
    });
    parentWindow.sent = sent;

    const frame = parentWindow.document.getElementById('ahotts-frame');
    const window = frame.contentWindow;
    stubLayout(window);

    const document = window.document;
    document.body.innerHTML = `${html}<script data-ahotts-bridge></script>`;
    const tag = document.querySelector('script[data-ahotts-bridge]');
    if (options.parents) {
        tag.setAttribute('data-ahotts-parents', options.parents);
    }
    // document.currentScript is null outside a real script load.
    Object.defineProperty(document, 'currentScript', {configurable: true, value: tag});

    const source = fs.readFileSync(new URL('bridge/ahotts-scorm-bridge.js', root), 'utf8');
    window.eval(source);

    return {dom, window, document, parent: parentWindow};
};

/**
 * Deliver a message event to a window, with full control over origin and source
 * so the tests can forge the things an attacker would forge.
 *
 * @param {Window} window Target window.
 * @param {Object} data
 * @param {String} origin
 * @param {Object} source
 */
export const postTo = (window, data, origin, source) => {
    const event = new window.MessageEvent('message', {data});
    Object.defineProperty(event, 'origin', {value: origin});
    Object.defineProperty(event, 'source', {value: source});
    window.dispatchEvent(event);
};

/**
 * A stand-in for an iframe's contentWindow that records what was posted to it.
 *
 * @return {Object}
 */
export const fakeWindow = () => ({
    sent: [],
    postMessage(data, origin) {
        this.sent.push({data, origin});
    },
});

/**
 * Attach a fake contentWindow to an iframe element.
 *
 * @param {Element} frame
 * @param {Object} win
 * @return {Object} The fake window.
 */
export const attachWindow = (frame, win) => {
    Object.defineProperty(frame, 'contentWindow', {configurable: true, value: win});
    Object.defineProperty(frame, 'contentDocument', {configurable: true, get() {
        throw new Error('cross-origin');
    }});
    return win;
};

/**
 * Wait for pending timers and microtasks.
 *
 * @param {Number} ms
 * @return {Promise}
 */
export const tick = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));
