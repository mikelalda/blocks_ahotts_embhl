/**
 * Moodle side of the cooperative bridge: handshake, chunk requests, highlight
 * control and, above all, what it refuses to accept.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {loadSpeech, postTo, fakeWindow, attachWindow, tick} from './helpers.mjs';

const SCORM = 'https://scorm.example.org';
const ORIGINS = [SCORM];

const page = (body = '') => `<!doctype html><html><body>
    <div id="readspeaker_button1" class="rs_skip rsbtn">
        <a class="rsbtn_play" href="#"><span class="rsbtn_text"><span>Listen</span></span></a>
    </div>
    <div id="region-main">${body}</div>
</body></html>`;

/**
 * A page with one cross-origin iframe plus a client wired to it.
 *
 * @param {Object} options {src, origins, timeout}
 * @return {Object}
 */
const setup = (options = {}) => {
    const src = options.src === undefined ? `${SCORM}/scormcontent/index.html` : options.src;
    const attr = src === null ? '' : ` src="${src}"`;
    const speech = loadSpeech(page(`<p>Moodle testua.</p><iframe id="scorm"${attr}></iframe>`));
    const frame = speech.document.getElementById('scorm');
    const win = attachWindow(frame, fakeWindow());
    const client = speech.testing.createBridgeClient({
        win: speech.window,
        allowedOrigins: options.origins || ORIGINS,
        timeout: options.timeout || 600,
    });
    return {speech, frame, win, client};
};

/** @return {Object} The last message posted to the frame. */
const lastSent = (win) => win.sent[win.sent.length - 1].data;

/**
 * Complete a handshake the way a well behaved bridge would.
 *
 * @param {Object} ctx
 * @return {Promise<Object>} The connection.
 */
const handshake = async (ctx) => {
    const promise = ctx.client.connect(ctx.frame);
    await tick(5);
    const hello = lastSent(ctx.win);
    postTo(ctx.speech.window, {
        protocol: hello.protocol,
        v: hello.v,
        type: 'hello-ack',
        session: hello.session,
        nonce: hello.nonce,
        bridge: 1,
        capabilities: ['collect', 'highlight'],
    }, SCORM, ctx.win);
    return promise;
};

test('handshakes with a bridge in an allowed cross-origin frame', async () => {
    const ctx = setup();
    const connection = await handshake(ctx);

    assert.ok(connection, 'the handshake succeeded');
    assert.equal(connection.origin, SCORM);
    const hello = ctx.win.sent[0];
    assert.equal(hello.data.type, 'hello');
    assert.equal(hello.data.protocol, 'ahotts-scorm-bridge');
    assert.equal(hello.data.v, 1);
    assert.ok(hello.data.session, 'the hello carries a session id');
    assert.equal(hello.origin, SCORM, 'the frame declares its origin, so "*" is not used');
});

test('uses "*" only for discovery, when the frame declares no origin', async () => {
    const ctx = setup({src: null});
    const promise = ctx.client.connect(ctx.frame);
    await tick(5);
    assert.equal(ctx.win.sent[0].origin, '*', 'discovery falls back to "*"');
    assert.equal(ctx.win.sent[0].data.type, 'hello', 'and only the discovery message does');

    const hello = lastSent(ctx.win);
    postTo(ctx.speech.window, {
        protocol: hello.protocol, v: 1, type: 'hello-ack', session: hello.session, nonce: hello.nonce,
    }, SCORM, ctx.win);
    const connection = await promise;
    assert.equal(connection.origin, SCORM, 'the connection binds to the origin that answered');

    ctx.client.collect(connection);
    await tick(5);
    const followup = ctx.win.sent[ctx.win.sent.length - 1];
    assert.equal(followup.data.type, 'collect');
    assert.equal(followup.origin, SCORM, 'after the handshake only the validated origin is used');
});

test('returns the chunks a bridge reports, ignoring malformed entries', async () => {
    const ctx = setup();
    const connection = await handshake(ctx);

    const promise = ctx.client.collect(connection);
    await tick(5);
    const request = lastSent(ctx.win);
    assert.equal(request.type, 'collect');

    postTo(ctx.speech.window, {
        protocol: request.protocol,
        v: 1,
        type: 'chunks',
        session: request.session,
        requestId: request.requestId,
        chunks: [
            {id: 'c1', text: 'Lehen zatia.'},
            {id: 'c2', text: '  Bigarren   zatia.  '},
            {id: 'not-an-id', text: 'Baztertua'},
            {id: 'c3', text: '<img src=x onerror=alert(1)>'},
            {id: 'c4', text: 42},
            {id: 'c5'},
            'nonsense',
            null,
        ],
    }, SCORM, ctx.win);

    const chunks = [...await promise].map((chunk) => ({id: chunk.id, text: chunk.text}));
    assert.deepEqual(chunks, [
        {id: 'c1', text: 'Lehen zatia.'},
        {id: 'c2', text: 'Bigarren zatia.'},
        // Markup arrives as plain text and stays plain text; it is never parsed.
        {id: 'c3', text: '<img src=x onerror=alert(1)>'},
    ]);
});

test('asks the bridge to highlight and to clear the highlight', async () => {
    const ctx = setup();
    const connection = await handshake(ctx);

    ctx.client.highlight(connection, 'c7');
    await tick(5);
    let sent = lastSent(ctx.win);
    assert.equal(sent.type, 'highlight');
    assert.equal(sent.chunkId, 'c7');

    ctx.client.unhighlight(connection, 'c7');
    await tick(5);
    sent = lastSent(ctx.win);
    assert.equal(sent.type, 'unhighlight');
    assert.equal(sent.chunkId, 'c7');
});

test('refuses to forward a chunk id that does not match the expected shape', async () => {
    const ctx = setup();
    const connection = await handshake(ctx);
    const before = ctx.win.sent.length;

    assert.equal(await ctx.client.highlight(connection, '../../etc/passwd'), false);
    assert.equal(await ctx.client.highlight(connection, '<script>'), false);
    assert.equal(ctx.win.sent.length, before, 'nothing was posted');
});

test('fails in a controlled way when the frame has no bridge', async () => {
    const ctx = setup();
    const connection = await ctx.client.connect(ctx.frame);

    assert.equal(connection, null, 'no bridge, no connection');
    assert.ok(ctx.win.sent.length >= 1, 'discovery was attempted');
    ctx.win.sent.forEach((message) => assert.equal(message.data.type, 'hello'));
});

test('never contacts a frame whose origin is not on the allow list', async () => {
    const ctx = setup({src: 'https://evil.example.com/index.html'});
    const connection = await ctx.client.connect(ctx.frame);

    assert.equal(connection, null);
    assert.equal(ctx.win.sent.length, 0, 'not a single message was posted');
});

test('ignores an answer coming from an origin that is not allowed', async () => {
    const ctx = setup();
    const promise = ctx.client.connect(ctx.frame);
    await tick(5);
    const hello = lastSent(ctx.win);

    postTo(ctx.speech.window, {
        protocol: hello.protocol, v: 1, type: 'hello-ack', session: hello.session, nonce: hello.nonce,
    }, 'https://evil.example.com', ctx.win);

    assert.equal(await promise, null);
});

test('ignores an answer coming from a window that was never registered', async () => {
    const ctx = setup();
    const promise = ctx.client.connect(ctx.frame);
    await tick(5);
    const hello = lastSent(ctx.win);

    postTo(ctx.speech.window, {
        protocol: hello.protocol, v: 1, type: 'hello-ack', session: hello.session, nonce: hello.nonce,
    }, SCORM, fakeWindow());

    assert.equal(await promise, null);
});

test('ignores an answer that carries the wrong session or protocol version', async () => {
    const ctx = setup();
    const promise = ctx.client.connect(ctx.frame);
    await tick(5);
    const hello = lastSent(ctx.win);

    postTo(ctx.speech.window, {
        protocol: hello.protocol, v: 1, type: 'hello-ack', session: 'ahotts-forged', nonce: hello.nonce,
    }, SCORM, ctx.win);
    postTo(ctx.speech.window, {
        protocol: hello.protocol, v: 99, type: 'hello-ack', session: hello.session, nonce: hello.nonce,
    }, SCORM, ctx.win);
    postTo(ctx.speech.window, {
        protocol: 'some-other-protocol', v: 1, type: 'hello-ack', session: hello.session, nonce: hello.nonce,
    }, SCORM, ctx.win);
    postTo(ctx.speech.window, 'a string, not an object', SCORM, ctx.win);

    assert.equal(await promise, null);
});

test('ignores a chunk answer whose request id was never issued', async () => {
    const ctx = setup();
    const connection = await handshake(ctx);

    const promise = ctx.client.collect(connection);
    await tick(5);
    const request = lastSent(ctx.win);

    postTo(ctx.speech.window, {
        protocol: request.protocol,
        v: 1,
        type: 'chunks',
        session: request.session,
        requestId: 'req-forged',
        chunks: [{id: 'c1', text: 'Injektatua'}],
    }, SCORM, ctx.win);

    // The genuine request still times out with an empty result.
    assert.equal((await promise).length, 0);
});

test('origin matching accepts exact origins and sub-domain wildcards only', () => {
    const speech = loadSpeech(page());
    const {originAllowed} = speech.testing;

    assert.equal(originAllowed(SCORM, [SCORM]), true);
    assert.equal(originAllowed('https://scorm.example.org', ['https://scorm.example.org/']), true);
    assert.equal(originAllowed('http://scorm.example.org', [SCORM]), false, 'scheme must match');
    assert.equal(originAllowed('https://scorm.example.org:8443', [SCORM]), false, 'port must match');
    assert.equal(originAllowed('https://a.example.org', ['https://*.example.org']), true);
    assert.equal(originAllowed('https://deep.a.example.org', ['https://*.example.org']), true);
    assert.equal(originAllowed('https://example.org', ['https://*.example.org']), false, 'bare domain');
    assert.equal(originAllowed('https://evilexample.org', ['https://*.example.org']), false);
    assert.equal(originAllowed('https://example.org.evil.com', ['https://*.example.org']), false);
    assert.equal(originAllowed(SCORM, []), false, 'an empty list allows nothing');
    assert.equal(originAllowed('', [SCORM]), false);
});

test('parseOrigin only accepts http(s) URLs', () => {
    const speech = loadSpeech(page());
    const {parseOrigin} = speech.testing;

    assert.equal(parseOrigin('https://scorm.example.org/a/b.html'), SCORM);
    assert.equal(parseOrigin('/local/path.html'), 'https://moodle.example.org');
    assert.equal(parseOrigin('about:blank'), '');
    assert.equal(parseOrigin('javascript:alert(1)'), '');
    assert.equal(parseOrigin('data:text/html,<p>x</p>'), '');
    assert.equal(parseOrigin(''), '');
});
