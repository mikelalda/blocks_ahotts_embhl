/**
 * Where the audio export is written: MP3 encoded in a worker, or WAV.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {loadSpeech, tick} from './helpers.mjs';

const piperOf = () => {
    const ctx = loadSpeech('<!doctype html><html><body></body></html>');
    return {window: ctx.window, ...ctx.piper};
};

test('the sink encodes MP3 in a worker as fragments arrive', async () => {
    const {window, createAudioSink} = piperOf();
    const received = [];
    class Mp3Worker {
        postMessage(message) {
            received.push(message.type === 'pcm' ? message.pcm.length : message.type);
            if (message.type === 'start') {
                setTimeout(() => this.onmessage({data: {type: 'started'}}), 1);
            }
            if (message.type === 'end') {
                setTimeout(() => this.onmessage({data: {type: 'done',
                    blob: new window.Blob(['ID3'], {type: 'audio/mpeg'})}}), 1);
            }
        }
        terminate() {}
    }
    const sink = createAudioSink('/mp3-worker.js', Mp3Worker);
    await sink.open(22050);
    assert.equal(sink.format, 'mp3');
    sink.write(new Int16Array(10));
    sink.write(new Int16Array(5));
    const file = await sink.finish();
    assert.equal(file.type, 'audio/mpeg');
    assert.deepEqual(received, ['start', 10, 5, 'end']);
});

test('the sink falls back to WAV when the encoder does not start', async () => {
    const {createAudioSink} = piperOf();
    class Broken {
        postMessage() {
            setTimeout(() => this.onerror({preventDefault() {}}), 1);
        }
        terminate() {}
    }
    const sink = createAudioSink('/mp3-worker.js', Broken);
    await sink.open(8000);
    assert.equal(sink.format, 'wav');
    sink.write(Int16Array.from([1, 2, 3]));
    const file = await sink.finish();
    assert.equal(file.type, 'audio/wav');
    assert.equal(file.size, 44 + 6);
});
