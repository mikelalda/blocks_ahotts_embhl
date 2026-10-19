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

// Download a file once and keep it in the Cache Storage, reporting progress.
const CACHE = 'block_ahotts_embhl_pockettts_v1';

export const fetchCached = async (url, label, onProgress) => {
    let cache = null;
    try {
        cache = await caches.open(CACHE);
        const hit = await cache.match(url);
        if (hit) {
            const buf = await hit.arrayBuffer();
            onProgress && onProgress({file: label, loaded: buf.byteLength, total: buf.byteLength, cached: true});
            return buf;
        }
    } catch (e) {
        cache = null;
    }
    const response = await fetch(url, {credentials: 'omit'});
    if (!response.ok) {
        throw new Error('Cannot download ' + url + ' (' + response.status + ')');
    }
    const total = Number(response.headers.get('content-length')) || 0;
    const reader = response.body.getReader();
    const parts = [];
    let loaded = 0;
    let last = 0;
    for (;;) {
        const {done, value} = await reader.read();
        if (done) {
            break;
        }
        parts.push(value);
        loaded += value.length;
        if (onProgress && performance.now() - last > 250) {
            last = performance.now();
            onProgress({file: label, loaded, total, cached: false});
        }
    }
    const bytes = new Uint8Array(loaded);
    let offset = 0;
    for (const p of parts) {
        bytes.set(p, offset);
        offset += p.length;
    }
    onProgress && onProgress({file: label, loaded, total: loaded, cached: false});
    if (cache) {
        try {
            await cache.put(url, new Response(bytes));
        } catch (e) {
            // Over quota: still usable, just not kept.
        }
    }
    return bytes.buffer;
};

export const fetchJson = async (url) => {
    const r = await fetch(url, {credentials: 'omit'});
    if (!r.ok) {
        throw new Error('Cannot download ' + url + ' (' + r.status + ')');
    }
    return r.json();
};
