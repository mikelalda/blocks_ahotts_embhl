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
 * Pocket TTS decoder worker: latent frames -> 24 kHz audio.
 *
 * Frames arrive from the generator worker over a MessagePort and are decoded in
 * small batches by the streaming Mimi decoder; each batch goes to the page as
 * soon as it is ready. The page applies the reading speed as it plays, so a
 * change is heard at once.
 *
 * Page -> worker:  {type: 'init', port, baseurl, orturl, wasmpaths}
 * Worker -> page:  {type: 'progress', ...} | {type: 'ready'} | {type: 'initerror', message}
 *                  {type: 'audio', id, samples} | {type: 'decdone', id} | {type: 'decerror', id, message}
 */

import {createDecoder} from './engine.js';
import {fetchCached, fetchJson} from './fetch-cached.js';

// The int8 decoder quantises each call as a whole, so its output depends a
// little on how frames are grouped; past 16 frames it drifts from the reference.
const MAX_BATCH = 16;
const MIN_BATCH = 2;

let decoder = null;
const queue = [];
let busy = false;

const post = (msg, transfer) => self.postMessage(msg, transfer || []);

const pump = async () => {
    if (busy || !decoder) {
        return;
    }
    busy = true;
    while (queue.length) {
        const item = queue[0];
        if (item.type === 'start') {
            queue.shift();
            decoder.reset();
            continue;
        }
        if (item.type === 'latent') {
            let n = 0;
            while (n < queue.length && queue[n].type === 'latent' && n < MAX_BATCH) {
                n++;
            }
            // Wait for a second frame unless the sentence is already over.
            if (n < MIN_BATCH && n === queue.length) {
                break;
            }
            const frames = queue.splice(0, n).map((x) => x.latent);
            try {
                /* eslint-disable-next-line no-await-in-loop */
                const audio = await decoder.decode(frames);
                post({type: 'audio', id: item.id, samples: audio}, [audio.buffer]);
            } catch (e) {
                post({type: 'decerror', id: item.id, message: String((e && e.message) || e)});
            }
            continue;
        }
        queue.shift();
        if (item.type === 'done') {
            post({type: 'decdone', id: item.id});
        }
    }
    busy = false;
};

self.onmessage = async (event) => {
    const msg = event.data || {};
    if (msg.type !== 'init') {
        return;
    }
    msg.port.onmessage = (e) => {
        queue.push(e.data);
        pump();
    };
    try {
        const ort = await import(msg.orturl);
        if (msg.wasmpaths) {
            ort.env.wasm.wasmPaths = msg.wasmpaths;
        }
        ort.env.wasm.numThreads = 1;
        decoder = await createDecoder({
            ort,
            baseurl: msg.baseurl,
            fetchJson,
            fetchBytes: (url, label) => fetchCached(url, label, (p) => post({type: 'progress', ...p})),
        });
        post({type: 'ready'});
        pump();
    } catch (e) {
        post({type: 'initerror', message: String((e && e.message) || e)});
    }
};
