import test from 'node:test';
import assert from 'node:assert/strict';
import {loadSpeech, tick} from './helpers.mjs';

const setup = (options = {}) => {
    const ctx = loadSpeech(`<!doctype html><html><head><title>Lesson 1</title></head><body>
        <div class="block_ahotts_embhl"><div id="readspeaker_button1" class="rs_skip rsbtn">
        <a class="rsbtn_play" href="#"><span class="rsbtn_text"><span>Listen</span></span></a>
        </div></div><div id="region-main"><p>First.</p><p>Second.</p></div></body></html>`);
    const {window, document, testing} = ctx;
    const prepared = [];
    const released = [];
    const urls = new Map();
    const clicks = [];
    let serial = 0;
    let closed = 0;
    window.URL.createObjectURL = (blob) => {
        const url = 'blob:' + (++serial);
        urls.set(url, blob);
        return url;
    };
    window.URL.revokeObjectURL = (url) => urls.delete(url);
    window.HTMLAnchorElement.prototype.click = function () { clicks.push(this.download); };
    window.AudioContext = class {
        sampleRate = 1000;
        async decodeAudioData(bytes) {
            const data = new Float32Array(bytes);
            return {length: data.length, numberOfChannels: 1, getChannelData: () => data};
        }
        async close() { closed++; }
    };
    window.fetch = async (url) => ({ok: true, arrayBuffer: async () => {
        const value = url.endsWith('1') ? 0.25 : 0.5;
        return new Float32Array([value, value]).buffer;
    }});
    testing.engineFactories.api = () => ({
        prepare: async (text) => {
            prepared.push(text);
            if (options.fail && prepared.length === 2) { throw new Error('failed'); }
            if (options.pending) { await options.pending; }
            return 'blob:fragment-' + prepared.length;
        },
        release: (url) => released.push(url),
        stop() {},
    });
    window.speechSynthesis = {speak() {}, cancel() {}};
    window.SpeechSynthesisUtterance = function () {};
    ctx.init({
        readid: 'region-main', maxlen: 1,
        languages: [{code: 'es', engines: options.browserOnly ? [{kind: 'webspeech'}] :
            [{kind: 'webspeech'}, {kind: 'api', url: 'https://tts.example.org'}]}],
        langmode: 'fixed', defaultlang: 'es',
        labels: {download: 'Download', saveaudio: 'Save', stop: 'Stop', listen: 'Listen'},
        status: {downloadprogress: '{current}/{total}', downloadready: 'Ready',
            downloaderror: 'Failed', downloadcancelled: 'Cancelled', downloadunavailable: 'Unavailable'},
    });
    return {...ctx, prepared, released, urls, clicks, closed: () => closed,
        download: document.querySelector('.ahotts-download'), save: document.querySelector('.ahotts-save'),
        status: document.querySelector('.ahotts-status')};
};

const bytesOf = (window, blob) => new Promise((resolve, reject) => {
    const reader = new window.FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsArrayBuffer(blob);
});

test('exports all fragments in order as one valid WAV, bypassing Web Speech and playback cap', async () => {
    const ctx = setup();
    ctx.download.click();
    await tick(30);
    assert.deepEqual(ctx.prepared, ['First.', 'Second.']);
    assert.equal(ctx.released.length, 2);
    assert.equal(ctx.status.textContent, 'Ready');
    assert.equal(ctx.save.hidden, false);
    assert.deepEqual(ctx.clicks, ['Lesson-1-es.wav']);
    const blob = ctx.urls.get(ctx.save.href);
    assert.equal(blob.type, 'audio/wav');
    const bytes = await bytesOf(ctx.window, blob);
    const view = new DataView(bytes);
    assert.equal(String.fromCharCode(...new Uint8Array(bytes, 0, 4)), 'RIFF');
    assert.equal(view.getUint32(24, true), 1000);
    assert.equal(view.getUint32(40, true), (2 + 150 + 2) * 2);
    assert.equal(view.getInt16(44, true), 8191);
    assert.equal(view.getInt16(44 + (152 * 2), true), 16383);
    assert.equal(ctx.closed(), 1);
    ctx.download.click();
    await tick(30);
    assert.equal(ctx.urls.size, 1, 'previous export URL is released');
    ctx.window.dispatchEvent(new ctx.window.Event('beforeunload'));
    assert.equal(ctx.urls.size, 0);
});

test('does not save a partial file if synthesis fails', async () => {
    const ctx = setup({fail: true});
    ctx.download.click();
    await tick(30);
    assert.equal(ctx.status.textContent, 'Failed');
    assert.equal(ctx.save.hidden, true);
    assert.equal(ctx.urls.size, 0);
    assert.equal(ctx.released.length, 1);
    assert.equal(ctx.download.disabled, false);
    assert.equal(ctx.closed(), 1);
});

test('stop cancels export and releases the in-flight fragment without saving', async () => {
    let resume;
    const pending = new Promise((resolve) => { resume = resolve; });
    const ctx = setup({pending});
    ctx.download.click();
    await tick(10);
    assert.equal(ctx.download.disabled, true);
    ctx.document.querySelector('.rsbtn_stop').click();
    resume();
    await tick(20);
    assert.equal(ctx.prepared.length, 1);
    assert.equal(ctx.released.length, 1);
    assert.equal(ctx.status.textContent, 'Cancelled');
    assert.equal(ctx.save.hidden, true);
    assert.equal(ctx.download.disabled, false);
    assert.equal(ctx.closed(), 1);
});

test('explains why a language with only browser speech cannot be exported', async () => {
    const ctx = setup({browserOnly: true});
    ctx.download.click();
    await tick(20);
    assert.equal(ctx.status.textContent, 'Unavailable');
    assert.equal(ctx.prepared.length, 0);
    assert.equal(ctx.save.hidden, true);
});
