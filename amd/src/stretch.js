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
 * Streaming time stretch (WSOLA) for speech: faster or slower, same pitch.
 *
 * Web Audio can only change speed by resampling, which turns voices into
 * chipmunks. WSOLA instead cuts the signal into overlapping windows, advances
 * through the input faster or slower than through the output, and nudges each
 * window to where it best continues the previous one, so periods line up and
 * the pitch is kept.
 *
 * It is streaming: audio is pushed as it is about to be played and comes out as
 * soon as each window is complete; flush() empties it when the speed changes or
 * the fragment ends.
 *
 * @module     block_ahotts_embhl/stretch
 * @copyright  2026 Ahotts
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @param {Number} rate Speed factor: 1.5 is half as fast again, 0.75 slower.
 * @param {Object} [options] {window, tolerance} in samples (24 kHz defaults: 40 ms and 10 ms).
 * @return {Object} {push(samples) => Float32Array, flush() => Float32Array}
 */
export const createStretcher = (rate, options = {}) => {
    const size = options.window || 960;
    const hop = size / 2;
    const tolerance = options.tolerance || 240;
    const analysisHop = hop * rate;

    // A periodic Hann window: at 50 % overlap the windows add up to exactly 1.
    const window = new Float32Array(size);
    for (let n = 0; n < size; n++) {
        window[n] = 0.5 - 0.5 * Math.cos(2 * Math.PI * n / size);
    }

    let input = new Float32Array(0);
    let base = 0; // Absolute index of input[0].
    let frame = 0;
    let previous = null; // Absolute start of the previous window.
    let overlap = new Float32Array(size);

    const end = () => base + input.length;
    const sample = (index) => {
        const i = index - base;
        return i >= 0 && i < input.length ? input[i] : 0;
    };

    /**
     * Where the window near `nominal` best continues the previous one.
     *
     * @param {Number} nominal
     * @return {Number}
     */
    const bestStart = (nominal) => {
        if (previous === null) {
            return nominal;
        }
        const natural = previous + hop;
        let best = nominal;
        let bestScore = -Infinity;
        for (let d = -tolerance; d <= tolerance; d++) {
            const start = nominal + d;
            if (start < base) {
                continue;
            }
            let score = 0;
            // Every other sample is plenty to find the best alignment.
            for (let n = 0; n < size; n += 2) {
                score += sample(start + n) * sample(natural + n);
            }
            if (score > bestScore) {
                bestScore = score;
                best = start;
            }
        }
        return best;
    };

    const run = (final) => {
        const out = [];
        for (;;) {
            const nominal = Math.round(frame * analysisHop);
            const needed = previous === null
                ? nominal + size
                : Math.max(nominal + tolerance + size, previous + hop + size);
            if (!final && needed > end()) {
                break;
            }
            if (final && nominal >= end()) {
                break;
            }
            const start = bestStart(nominal);
            for (let n = 0; n < size; n++) {
                overlap[n] += window[n] * sample(start + n);
            }
            // The first half can no longer change: the next window starts at its end.
            out.push(overlap.slice(0, hop));
            const next = new Float32Array(size);
            next.set(overlap.subarray(hop));
            overlap = next;
            previous = start;
            frame += 1;

            // Drop input that no future window can reach.
            const keep = Math.min(previous + hop, Math.round(frame * analysisHop) - tolerance);
            if (keep > base) {
                input = input.slice(Math.min(keep - base, input.length));
                base = keep;
            }
        }
        if (final && frame > 0) {
            out.push(overlap.slice(0, hop));
        }
        const length = out.reduce((sum, part) => sum + part.length, 0);
        const joined = new Float32Array(length);
        let offset = 0;
        out.forEach((part) => {
            joined.set(part, offset);
            offset += part.length;
        });
        return joined;
    };

    if (rate === 1) {
        return {
            push: (samples) => Float32Array.from(samples),
            flush: () => new Float32Array(0),
        };
    }

    return {
        push: (samples) => {
            const grown = new Float32Array(input.length + samples.length);
            grown.set(input);
            grown.set(samples, input.length);
            input = grown;
            return run(false);
        },
        flush: () => run(true),
    };
};
