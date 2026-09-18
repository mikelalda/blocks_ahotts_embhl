/**
 * SCORM side of the cooperative bridge: what it extracts, what it highlights and
 * which messages it refuses to answer.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {loadBridge, postTo, fakeWindow, tick} from './helpers.mjs';

const MOODLE = 'https://moodle.example.org';
const PROTOCOL = 'ahotts-scorm-bridge';

/**
 * Load a bridge whose parent is a recording window.
 *
 * @param {String} body
 * @param {Object} options
 * @return {Object}
 */
const setup = (body, options = {}) => loadBridge(body, options);

/**
 * Drive a handshake and return the session id in use.
 *
 * @param {Object} ctx
 * @param {String} [origin]
 * @return {String}
 */
const handshake = (ctx, origin = MOODLE) => {
    const session = 'ahotts-test-session';
    postTo(ctx.window, {protocol: PROTOCOL, v: 1, type: 'hello', session, nonce: 'n-1'}, origin, ctx.parent);
    return session;
};

const answers = (ctx, type) => ctx.parent.sent.filter((m) => m.data.type === type);

test('announces itself to the embedder when it loads', () => {
    const ctx = setup('<p>Edukia</p>');
    const ready = answers(ctx, 'ready');
    assert.equal(ready.length, 1);
    assert.equal(ready[0].data.protocol, PROTOCOL);
    assert.equal(ready[0].data.v, 1);
    assert.equal(ready[0].origin, '*', 'with no configured parent the announcement has to go to "*"');
    assert.equal(ready[0].data.session, undefined, 'the announcement carries no session');
});

test('announces only to the configured parent origins when they are set', () => {
    const ctx = setup('<p>Edukia</p>', {parents: MOODLE});
    const ready = answers(ctx, 'ready');
    assert.equal(ready.length, 1);
    assert.equal(ready[0].origin, MOODLE, '"*" is not used once the parents are known');
});

test('answers the handshake to the origin that asked', () => {
    const ctx = setup('<p>Edukia</p>');
    handshake(ctx);

    const ack = answers(ctx, 'hello-ack');
    assert.equal(ack.length, 1);
    assert.equal(ack[0].origin, MOODLE);
    assert.equal(ack[0].data.nonce, 'n-1');
    assert.equal(ack[0].data.session, 'ahotts-test-session');
    // The bridge lives in another realm, so copy before comparing.
    assert.deepEqual([...ack[0].data.capabilities], ['collect', 'highlight', 'unhighlight', 'page-changed']);
});

test('refuses a handshake from a window that does not embed it', () => {
    const ctx = setup('<p>Edukia</p>');
    postTo(ctx.window, {protocol: PROTOCOL, v: 1, type: 'hello', session: 's', nonce: 'n'}, MOODLE, fakeWindow());
    assert.equal(answers(ctx, 'hello-ack').length, 0);
});

test('refuses a handshake from an origin that is not configured', () => {
    const ctx = setup('<p>Edukia</p>', {parents: MOODLE});
    handshake(ctx, 'https://evil.example.com');
    assert.equal(answers(ctx, 'hello-ack').length, 0);

    handshake(ctx, MOODLE);
    assert.equal(answers(ctx, 'hello-ack').length, 1);
});

test('ignores malformed messages and unknown protocol versions', () => {
    const ctx = setup('<p>Edukia</p>');
    postTo(ctx.window, null, MOODLE, ctx.parent);
    postTo(ctx.window, 'hello', MOODLE, ctx.parent);
    postTo(ctx.window, {protocol: 'other', v: 1, type: 'hello', session: 's'}, MOODLE, ctx.parent);
    postTo(ctx.window, {protocol: PROTOCOL, v: 2, type: 'hello', session: 's'}, MOODLE, ctx.parent);
    postTo(ctx.window, {protocol: PROTOCOL, v: 1, type: 42, session: 's'}, MOODLE, ctx.parent);
    postTo(ctx.window, {protocol: PROTOCOL, v: 1, type: 'hello'}, MOODLE, ctx.parent);
    assert.equal(answers(ctx, 'hello-ack').length, 0);
});

test('extracts the readable text of the package, skipping hidden parts', () => {
    const ctx = setup([
        '<h1>Ikasgaia</h1>',
        '<p>Lehen paragrafoa.</p>',
        '<p style="display: none">Ezkutua</p>',
        '<p aria-hidden="true">Aria</p>',
        '<p class="ahotts-skip">Nabigazioa</p>',
        '<p>Lehen paragrafoa.</p>',
    ].join(''));
    const session = handshake(ctx);

    postTo(ctx.window, {protocol: PROTOCOL, v: 1, type: 'collect', session, requestId: 'req-1'}, MOODLE, ctx.parent);

    const chunks = answers(ctx, 'chunks');
    assert.equal(chunks.length, 1);
    assert.equal(chunks[0].origin, MOODLE);
    assert.equal(chunks[0].data.requestId, 'req-1');
    assert.deepEqual([...chunks[0].data.chunks].map((c) => c.text), ['Ikasgaia', 'Lehen paragrafoa.']);
    [...chunks[0].data.chunks].forEach((c) => assert.match(c.id, /^c[0-9]+$/));
});

test('refuses a collect request that does not carry the agreed session', () => {
    const ctx = setup('<p>Edukia</p>');
    handshake(ctx);

    postTo(ctx.window, {
        protocol: PROTOCOL, v: 1, type: 'collect', session: 'ahotts-forged', requestId: 'req-1',
    }, MOODLE, ctx.parent);

    assert.equal(answers(ctx, 'chunks').length, 0);
});

test('refuses a collect request relayed from another origin after the handshake', () => {
    const ctx = setup('<p>Edukia</p>');
    const session = handshake(ctx);

    postTo(ctx.window, {
        protocol: PROTOCOL, v: 1, type: 'collect', session, requestId: 'req-1',
    }, 'https://evil.example.com', ctx.parent);

    assert.equal(answers(ctx, 'chunks').length, 0);
});

test('highlights and clears a chunk by id', () => {
    const ctx = setup('<p id="p1">Lehen paragrafoa.</p><p id="p2">Bigarrena.</p>');
    const session = handshake(ctx);
    postTo(ctx.window, {protocol: PROTOCOL, v: 1, type: 'collect', session, requestId: 'r1'}, MOODLE, ctx.parent);
    const chunks = answers(ctx, 'chunks')[0].data.chunks;

    postTo(ctx.window, {
        protocol: PROTOCOL, v: 1, type: 'highlight', session, requestId: 'r2', chunkId: chunks[1].id,
    }, MOODLE, ctx.parent);

    const p2 = ctx.document.getElementById('p2');
    assert.equal(p2.style.textDecoration, 'underline');
    const ack = answers(ctx, 'ack');
    assert.equal(ack[ack.length - 1].data.ok, true);

    postTo(ctx.window, {
        protocol: PROTOCOL, v: 1, type: 'unhighlight', session, requestId: 'r3', chunkId: chunks[1].id,
    }, MOODLE, ctx.parent);
    assert.equal(p2.style.textDecoration, '');
});

test('moves the highlight instead of accumulating underlines', () => {
    const ctx = setup('<p id="p1">Bat.</p><p id="p2">Bi.</p>');
    const session = handshake(ctx);
    postTo(ctx.window, {protocol: PROTOCOL, v: 1, type: 'collect', session, requestId: 'r1'}, MOODLE, ctx.parent);
    const chunks = answers(ctx, 'chunks')[0].data.chunks;

    postTo(ctx.window, {
        protocol: PROTOCOL, v: 1, type: 'highlight', session, requestId: 'r2', chunkId: chunks[0].id,
    }, MOODLE, ctx.parent);
    postTo(ctx.window, {
        protocol: PROTOCOL, v: 1, type: 'highlight', session, requestId: 'r3', chunkId: chunks[1].id,
    }, MOODLE, ctx.parent);

    assert.equal(ctx.document.getElementById('p1').style.textDecoration, '');
    assert.equal(ctx.document.getElementById('p2').style.textDecoration, 'underline');
});

test('rejects a highlight request with an id it never issued', () => {
    const ctx = setup('<p id="p1">Bat.</p>');
    const session = handshake(ctx);

    postTo(ctx.window, {
        protocol: PROTOCOL, v: 1, type: 'highlight', session, requestId: 'r2', chunkId: 'c999',
    }, MOODLE, ctx.parent);
    let ack = answers(ctx, 'ack');
    assert.equal(ack[ack.length - 1].data.ok, false, 'unknown id');

    postTo(ctx.window, {
        protocol: PROTOCOL, v: 1, type: 'highlight', session, requestId: 'r3',
        chunkId: '<img src=x onerror=alert(1)>',
    }, MOODLE, ctx.parent);
    ack = answers(ctx, 'ack');
    assert.equal(ack[ack.length - 1].data.ok, false, 'malformed id');
    assert.equal(ctx.document.body.querySelector('img'), null, 'nothing received is ever parsed as markup');
});

test('reads nested same-origin iframes inside the package', async () => {
    const ctx = setup('<p>Kanpoan.</p><iframe id="inner" src="about:blank"></iframe>');
    await tick(10);
    const inner = ctx.document.getElementById('inner');
    const {stubLayout} = await import('./helpers.mjs');
    stubLayout(inner.contentWindow);
    inner.contentDocument.body.innerHTML = '<p>Barruan.</p>';

    const session = handshake(ctx);
    postTo(ctx.window, {protocol: PROTOCOL, v: 1, type: 'collect', session, requestId: 'r1'}, MOODLE, ctx.parent);

    const chunks = answers(ctx, 'chunks')[0].data.chunks;
    assert.deepEqual([...chunks].map((c) => c.text), ['Kanpoan.', 'Barruan.']);
});

test('tells the parent when the page changed', async () => {
    const ctx = setup('<div id="host"><p>Lehen orria.</p></div>');
    handshake(ctx);

    ctx.document.getElementById('host').innerHTML = '<p>Bigarren orria.</p>';
    await tick(600);

    assert.ok(answers(ctx, 'page-changed').length >= 1, 'a page change was announced');
    assert.equal(answers(ctx, 'page-changed')[0].origin, MOODLE);
});

test('stops answering after a bye', () => {
    const ctx = setup('<p>Edukia</p>');
    const session = handshake(ctx);

    postTo(ctx.window, {protocol: PROTOCOL, v: 1, type: 'bye', session}, MOODLE, ctx.parent);
    postTo(ctx.window, {protocol: PROTOCOL, v: 1, type: 'collect', session, requestId: 'r1'}, MOODLE, ctx.parent);

    assert.equal(answers(ctx, 'chunks').length, 0);
});
