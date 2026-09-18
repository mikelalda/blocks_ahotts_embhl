/**
 * End to end behaviour of the Listen button: playback, pause, resume, stop,
 * highlighting, and the accessible status shown for unreadable external content.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {loadSpeech, postTo, attachWindow, tick} from './helpers.mjs';

const SCORM = 'https://scorm.example.org';

const LABELS = {
    listen: 'Entzun',
    loading: 'Kargatzen',
    pause: 'Pausatu',
    resume: 'Jarraitu',
    stop: 'Gelditu',
};

const STATUS = {
    externalnobridge: 'Kanpoko edukia ezin da irakurri.',
    nocontent: 'Ez dago testu irakurgarririk.',
};

const page = (body) => `<!doctype html><html><body>
    <div class="block_ahotts_embhl">
        <div id="readspeaker_button1" class="rs_skip rsbtn">
            <a class="rsbtn_play" href="#"><span class="rsbtn_text"><span>Entzun</span></span></a>
        </div>
    </div>
    <div id="region-main">${body}</div>
</body></html>`;

/**
 * A stub of the Web Speech API that lets the test end an utterance on demand.
 *
 * @param {Window} window
 * @return {Object}
 */
const stubSpeech = (window) => {
    const synth = {
        spoken: [],
        cancelled: 0,
        paused: 0,
        resumed: 0,
        pending: null,
        speak(utterance) {
            this.spoken.push(utterance.text);
            this.pending = utterance;
        },
        cancel() {
            this.cancelled += 1;
            const utterance = this.pending;
            this.pending = null;
            if (utterance && utterance.onend) {
                utterance.onend();
            }
        },
        pause() {
            this.paused += 1;
        },
        resume() {
            this.resumed += 1;
        },
        /** End the utterance currently being spoken. */
        finish() {
            const utterance = this.pending;
            this.pending = null;
            if (utterance && utterance.onend) {
                utterance.onend();
            }
        },
    };
    window.speechSynthesis = synth;
    window.SpeechSynthesisUtterance = function (text) {
        this.text = text;
    };
    return synth;
};

const start = (body, config = {}) => {
    const speech = loadSpeech(page(body));
    const synth = stubSpeech(speech.window);
    speech.init({
        prefer: 'webspeech',
        readid: 'region-main',
        maxlen: 3000,
        labels: LABELS,
        webspeech: {lang: 'eu-ES'},
        status: STATUS,
        bridge: {enabled: false, origins: [], timeout: 300},
        ...config,
    });
    const link = speech.document.querySelector('a.rsbtn_play');
    const label = speech.document.querySelector('.rsbtn_text span');
    const stopButton = speech.document.querySelector('.rsbtn_stop');
    const status = speech.document.querySelector('.ahotts-status');
    return {...speech, synth, link, label, stopButton, status};
};

test('plays the page chunk by chunk and underlines what it reads', async () => {
    const ctx = start('<p id="p1">Bat.</p><p id="p2">Bi.</p>');

    ctx.link.click();
    await tick(10);

    assert.deepEqual(ctx.synth.spoken, ['Bat.']);
    assert.equal(ctx.document.getElementById('p1').style.textDecoration, 'underline');
    assert.equal(ctx.label.textContent, LABELS.pause);
    assert.equal(ctx.stopButton.style.display, '');

    ctx.synth.finish();
    await tick(10);

    assert.deepEqual(ctx.synth.spoken, ['Bat.', 'Bi.']);
    assert.equal(ctx.document.getElementById('p1').style.textDecoration, '', 'the first chunk is cleared');
    assert.equal(ctx.document.getElementById('p2').style.textDecoration, 'underline');

    ctx.synth.finish();
    await tick(10);

    assert.equal(ctx.label.textContent, LABELS.listen, 'the button goes back to Listen');
    assert.equal(ctx.stopButton.style.display, 'none');
    assert.equal(ctx.document.getElementById('p2').style.textDecoration, '');
});

test('pauses and resumes', async () => {
    const ctx = start('<p id="p1">Bat.</p><p id="p2">Bi.</p>');

    ctx.link.click();
    await tick(10);

    ctx.link.click();
    assert.equal(ctx.synth.paused, 1);
    assert.equal(ctx.label.textContent, LABELS.resume);

    ctx.link.click();
    assert.equal(ctx.synth.resumed, 1);
    assert.equal(ctx.label.textContent, LABELS.pause);

    ctx.synth.finish();
    await tick(10);
    assert.deepEqual(ctx.synth.spoken, ['Bat.', 'Bi.']);
});

test('stops, clears the highlight and returns to the idle state', async () => {
    const ctx = start('<p id="p1">Bat.</p><p id="p2">Bi.</p><p id="p3">Hiru.</p>');

    ctx.link.click();
    await tick(10);
    ctx.stopButton.click();
    await tick(10);

    assert.equal(ctx.synth.cancelled >= 1, true);
    assert.deepEqual(ctx.synth.spoken, ['Bat.'], 'no further chunk is spoken');
    assert.equal(ctx.document.getElementById('p1').style.textDecoration, '');
    assert.equal(ctx.label.textContent, LABELS.listen);
    assert.equal(ctx.stopButton.style.display, 'none');
});

test('explains, accessibly, that an external frame cannot be read', async () => {
    const ctx = start('<p>Moodle testua.</p><iframe id="scorm" src="https://external.example.com/x.html"></iframe>');
    attachWindow(ctx.document.getElementById('scorm'), {postMessage() {}});

    ctx.link.click();
    await tick(50);

    assert.equal(ctx.status.getAttribute('role'), 'status');
    assert.equal(ctx.status.getAttribute('aria-live'), 'polite');
    assert.equal(ctx.status.textContent, STATUS.externalnobridge);
    assert.deepEqual(ctx.synth.spoken, ['Moodle testua.'], 'the rest of the page is still read');
});

test('says so when there is nothing to read', async () => {
    const ctx = start('');

    ctx.link.click();
    await tick(10);

    assert.equal(ctx.status.textContent, STATUS.nocontent);
    assert.equal(ctx.label.textContent, LABELS.listen);
});

test('reads a cross-origin package that ships the bridge, and drives its highlight', async () => {
    const ctx = start(
        '<p>Moodle testua.</p><iframe id="scorm" src="' + SCORM + '/index.html"></iframe>',
        {bridge: {enabled: true, origins: [SCORM], timeout: 500}}
    );

    // A cooperative bridge living in the frame: it answers on the real protocol.
    const frame = ctx.document.getElementById('scorm');
    const posted = [];
    const win = {
        postMessage(data) {
            posted.push(data);
            setTimeout(() => {
                if (data.type === 'hello') {
                    postTo(ctx.window, {
                        protocol: data.protocol, v: data.v, type: 'hello-ack',
                        session: data.session, nonce: data.nonce,
                    }, SCORM, win);
                } else if (data.type === 'collect') {
                    postTo(ctx.window, {
                        protocol: data.protocol, v: data.v, type: 'chunks',
                        session: data.session, requestId: data.requestId,
                        chunks: [{id: 'c1', text: 'SCORM lehen zatia.'}, {id: 'c2', text: 'SCORM bigarrena.'}],
                    }, SCORM, win);
                } else {
                    postTo(ctx.window, {
                        protocol: data.protocol, v: data.v, type: 'ack',
                        session: data.session, requestId: data.requestId, ok: true,
                    }, SCORM, win);
                }
            }, 0);
        },
    };
    attachWindow(frame, win);

    ctx.link.click();
    await tick(60);

    assert.deepEqual(ctx.synth.spoken, ['Moodle testua.']);
    assert.equal(ctx.status.textContent, '', 'a package with a bridge raises no warning');

    ctx.synth.finish();
    await tick(30);
    assert.deepEqual(ctx.synth.spoken, ['Moodle testua.', 'SCORM lehen zatia.']);
    const highlights = posted.filter((m) => m.type === 'highlight');
    assert.equal(highlights.length, 1);
    assert.equal(highlights[0].chunkId, 'c1');

    ctx.synth.finish();
    await tick(30);
    assert.deepEqual(
        ctx.synth.spoken,
        ['Moodle testua.', 'SCORM lehen zatia.', 'SCORM bigarrena.']
    );
    assert.equal(posted.filter((m) => m.type === 'unhighlight').length >= 1, true);
});
