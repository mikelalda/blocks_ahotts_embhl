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
 * Pocket TTS generator worker: text -> latent frames.
 *
 * Runs text_conditioner, flow_lm_main and flow_lm_flow, and hands every frame
 * straight to the decoder worker over the MessagePort it is given, so the two
 * run on two cores without SharedArrayBuffer.
 *
 * Page -> worker:  {type: 'init', port, baseurl, orturl, wasmpaths}
 *                  {type: 'speak', id, text, language, voiceurl, temperature}
 *                  {type: 'cancel', id}
 * Worker -> page:  {type: 'progress', file, loaded, total, cached}
 *                  {type: 'ready'} | {type: 'initerror', message}
 *                  {type: 'gendone', id, cancelled, error}
 * Worker -> port:  {type: 'start'|'latent'|'end'|'done', id, ...}
 */

import {createGenerator} from './engine.js';
import {fetchCached, fetchJson} from './fetch-cached.js';
import {normalizeBasque} from './normalize-eu.js';

const NORMALISERS = {eu: normalizeBasque};

let generator = null;
let port = null;
let current = null;

const post = (msg) => self.postMessage(msg);

// Let queued messages (a cancel) run between frames. Each ONNX run resolves as
// a microtask, so without this the loop would never yield; a message round trip
// is cheaper than setTimeout, which browsers clamp when nested.
const tick = (() => {
    const channel = new MessageChannel();
    const waiting = [];
    channel.port1.onmessage = () => waiting.shift()();
    return () => new Promise((resolve) => {
        waiting.push(resolve);
        channel.port2.postMessage(0);
    });
})();

const speak = async (msg) => {
    const abort = new AbortController();
    current = {id: msg.id, abort};
    let error = '';
    try {
        const normalise = NORMALISERS[msg.language] || ((text) => text);
        const text = normalise(String(msg.text || ''));
        const items = generator.latents(text, msg.voiceurl, {temperature: msg.temperature, signal: abort.signal});
        for await (const item of items) {
            if (item.type === 'latent') {
                // A copy: the generator still feeds this frame into the next step.
                const latent = item.latent.slice();
                port.postMessage({type: 'latent', id: msg.id, latent}, [latent.buffer]);
                await tick();
            } else {
                port.postMessage({type: item.type, id: msg.id, sentence: item.sentence});
            }
        }
    } catch (e) {
        error = String((e && e.message) || e);
    }
    current = null;
    port.postMessage({type: 'done', id: msg.id});
    post({type: 'gendone', id: msg.id, cancelled: abort.signal.aborted, error});
};

self.onmessage = async (event) => {
    const msg = event.data || {};
    if (msg.type === 'init') {
        try {
            port = msg.port;
            const ort = await import(msg.orturl);
            if (msg.wasmpaths) {
                ort.env.wasm.wasmPaths = msg.wasmpaths;
            }
            // Threads need a cross-origin isolated page, which Moodle is not.
            ort.env.wasm.numThreads = 1;
            generator = await createGenerator({
                ort,
                baseurl: msg.baseurl,
                fetchJson,
                fetchBytes: (url, label) => fetchCached(url, label, (p) => post({type: 'progress', ...p})),
            });
            post({type: 'ready'});
        } catch (e) {
            post({type: 'initerror', message: String((e && e.message) || e)});
        }
    } else if (msg.type === 'speak') {
        speak(msg);
    } else if (msg.type === 'cancel') {
        if (current && current.id === msg.id) {
            current.abort.abort();
        }
    }
};
