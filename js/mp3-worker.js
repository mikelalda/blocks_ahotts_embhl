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
 * Web Worker that encodes mono 16-bit PCM to MP3 with lamejs, so a long
 * export is compressed as it is synthesised, off the page's thread.
 *
 * Messages in:  {type: 'start', samplerate, kbps}, {type: 'pcm', pcm: Int16Array}, {type: 'end'}
 * Messages out: {type: 'done', blob: Blob} or {type: 'error', error: String}
 *
 * @copyright  2026 Ahotts
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* eslint-env worker */
/* global lamejs */

importScripts('lame/lame.min.js');

// MP3 frames hold 1152 samples; feeding many at once keeps the call overhead low.
const BLOCK = 1152 * 32;

let encoder = null;
let parts = [];

self.onmessage = (event) => {
    const message = event.data || {};
    try {
        if (message.type === 'start') {
            encoder = new lamejs.Mp3Encoder(1, message.samplerate, message.kbps || 48);
            parts = [];
            self.postMessage({type: 'started'});
        } else if (message.type === 'pcm') {
            const pcm = message.pcm;
            for (let offset = 0; offset < pcm.length; offset += BLOCK) {
                const frame = encoder.encodeBuffer(pcm.subarray(offset, offset + BLOCK));
                if (frame.length) {
                    parts.push(new Uint8Array(frame));
                }
            }
        } else if (message.type === 'end') {
            const last = encoder.flush();
            if (last.length) {
                parts.push(new Uint8Array(last));
            }
            const blob = new Blob(parts, {type: 'audio/mpeg'});
            parts = [];
            self.postMessage({type: 'done', blob: blob});
        }
    } catch (error) {
        self.postMessage({type: 'error', error: String((error && error.message) || error)});
    }
};
