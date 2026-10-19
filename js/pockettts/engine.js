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
 * Pocket TTS (Kyutai, itzune Basque finetune) on ONNX Runtime Web.
 *
 * A port of pocket_tts_onnx.py (KevinAHM, Apache-2.0) restricted to what a
 * browser needs: predefined voices stored as .safetensors attention states,
 * int8 graphs, one LSD step. Runs the same in Node (for testing) and in a Web
 * Worker.
 *
 *   text --(SentencePiece unigram)--> token ids --(text_conditioner)--> embeddings
 *        --(flow_lm_main + flow_lm_flow, one frame per step)--> 32-d latents
 *        --(mimi_decoder, streaming)--> 24 kHz float32 audio
 *
 * The transformer's KV cache is declared with a fixed length of 1000 in the
 * published graph, but the graph itself reads the length from the input shape.
 * The declared dimension is rewritten in the model bytes to an unnamed one, so
 * each sentence only carries the cache it needs (~200 positions instead of 1000).
 */

const SPACE = '▁';
const UNK_PENALTY = 10;
const EOS_THRESHOLD = -4.0;
const TOKENS_PER_SECOND_ESTIMATE = 3.0;
const GEN_SECONDS_PADDING = 2.0;

// ---------------------------------------------------------------------------
// Tokenizer: SentencePiece unigram, identity normaliser, dummy prefix, byte fallback.

export class UnigramTokenizer {
    constructor(vocab, scores) {
        this.pieces = new Map();
        this.idToPiece = [];
        this.maxLen = 1;
        let minScore = Infinity;
        for (const [piece, id] of Object.entries(vocab)) {
            this.idToPiece[id] = piece;
            if (/^<.*>$/.test(piece)) {
                continue; // Control, unknown and byte pieces never match text.
            }
            const score = scores[piece] ?? 0;
            this.pieces.set(piece, {id, score});
            this.maxLen = Math.max(this.maxLen, Array.from(piece).length);
            minScore = Math.min(minScore, score);
        }
        this.unkScore = minScore - UNK_PENALTY;
        this.byteIds = [];
        for (let b = 0; b < 256; b++) {
            const name = '<0x' + b.toString(16).toUpperCase().padStart(2, '0') + '>';
            this.byteIds[b] = vocab[name];
        }
        this.unkId = vocab['<unk>'] ?? 0;
        this.encoder = new TextEncoder();
        this.decoder = new TextDecoder();
    }

    encode(text) {
        const chars = Array.from(SPACE + String(text).replace(/ /g, SPACE));
        const n = chars.length;
        const best = new Float64Array(n + 1).fill(-Infinity);
        const backLen = new Int32Array(n + 1);
        const backId = new Int32Array(n + 1);
        best[0] = 0;
        for (let i = 0; i < n; i++) {
            if (best[i] === -Infinity) {
                continue;
            }
            let single = false;
            let sub = '';
            for (let len = 1; len <= this.maxLen && i + len <= n; len++) {
                sub += chars[i + len - 1];
                const piece = this.pieces.get(sub);
                if (!piece) {
                    continue;
                }
                if (len === 1) {
                    single = true;
                }
                const score = best[i] + piece.score;
                if (score > best[i + len]) {
                    best[i + len] = score;
                    backLen[i + len] = len;
                    backId[i + len] = piece.id;
                }
            }
            if (!single) {
                const score = best[i] + this.unkScore;
                if (score > best[i + 1]) {
                    best[i + 1] = score;
                    backLen[i + 1] = 1;
                    backId[i + 1] = -1;
                }
            }
        }
        const out = [];
        for (let end = n; end > 0; end -= backLen[end]) {
            const id = backId[end];
            if (id >= 0) {
                out.push([id]);
            } else {
                const bytes = this.encoder.encode(chars[end - 1]);
                out.push(Array.from(bytes, (b) => this.byteIds[b] ?? this.unkId));
            }
        }
        return out.reverse().flat();
    }

    decode(ids) {
        const bytes = [];
        let text = '';
        const flush = () => {
            if (bytes.length) {
                text += this.decoder.decode(Uint8Array.from(bytes));
                bytes.length = 0;
            }
        };
        for (const id of ids) {
            const piece = this.idToPiece[id] || '';
            const m = /^<0x([0-9A-F]{2})>$/.exec(piece);
            if (m) {
                bytes.push(parseInt(m[1], 16));
            } else if (!/^<.*>$/.test(piece)) {
                flush();
                text += piece;
            }
        }
        flush();
        return text.replace(new RegExp(SPACE, 'g'), ' ').replace(/^ /, '');
    }
}

// ---------------------------------------------------------------------------
// Text preparation and sentence chunking, as in the reference runtime.

export const prepareText = (text) => {
    text = String(text).trim();
    if (!text) {
        throw new Error('Text cannot be empty');
    }
    text = text.replace(/[\n\r]/g, ' ').replace(/ {2}/g, ' ');
    const words = text.split(/\s+/).length;
    const guess = words <= 4 ? 3 : 1;
    text = text[0].toUpperCase() + text.slice(1);
    if (/[\p{L}\p{N}]$/u.test(text)) {
        text += '.';
    }
    return {text, framesAfterEos: guess + 2};
};

const boundaryIndices = (tokens, set) => {
    const idx = [0];
    let prev = false;
    tokens.forEach((t, i) => {
        if (set.has(t)) {
            prev = true;
        } else {
            if (prev) {
                idx.push(i);
            }
            prev = false;
        }
    });
    idx.push(tokens.length);
    return idx;
};

export const splitSentences = (tokenizer, text, maxTokens) => {
    const prepared = prepareText(text).text.trim();
    const segmentsOf = (tokens, set) => {
        const b = boundaryIndices(tokens, set);
        const out = [];
        for (let i = 0; i < b.length - 1; i++) {
            out.push([b[i + 1] - b[i], tokenizer.decode(tokens.slice(b[i], b[i + 1]))]);
        }
        return out;
    };
    const eos = new Set(tokenizer.encode('.!...?').slice(1));
    const fallback = new Set(tokenizer.encode(',;:').slice(1));
    const refined = [];
    for (const [count, seg] of segmentsOf(tokenizer.encode(prepared), eos)) {
        if (count <= maxTokens) {
            refined.push([count, seg]);
            continue;
        }
        const sub = segmentsOf(tokenizer.encode(seg.trim()), fallback);
        if (sub.length > 1) {
            refined.push(...sub);
        } else {
            refined.push([count, seg]);
        }
    }
    const chunks = [];
    let current = '';
    let count = 0;
    for (const [c, seg] of refined) {
        if (!current) {
            current = seg;
            count = c;
        } else if (count + c > maxTokens) {
            chunks.push(current.trim());
            current = seg;
            count = c;
        } else {
            current += ' ' + seg;
            count += c;
        }
    }
    if (current) {
        chunks.push(current.trim());
    }
    return chunks.filter(Boolean);
};

// ---------------------------------------------------------------------------
// File formats.

export const parseSafetensors = (buffer) => {
    const view = new DataView(buffer);
    const headerLen = Number(view.getBigUint64(0, true));
    const header = JSON.parse(new TextDecoder().decode(new Uint8Array(buffer, 8, headerLen)));
    const base = 8 + headerLen;
    const out = {};
    for (const [name, info] of Object.entries(header)) {
        if (name === '__metadata__') {
            continue;
        }
        const [start, end] = info.data_offsets;
        const bytes = buffer.slice(base + start, base + end);
        let data;
        if (info.dtype === 'F32') {
            data = new Float32Array(bytes);
        } else if (info.dtype === 'I64') {
            data = new BigInt64Array(bytes);
        } else {
            continue;
        }
        out[name] = {shape: info.shape, data};
    }
    return out;
};

/**
 * Make the KV cache length of flow_lm_main dynamic.
 *
 * Each state tensor is declared [2, 1, 1000, 16, 64]. The Dimension message for
 * 1000 (field dim_value) is replaced by one carrying only a denotation, which
 * ONNX treats as an unknown dimension. Both encodings are five bytes long, so
 * no enclosing length prefix changes.
 */
export const makeCacheDynamic = (bytes) => {
    const u8 = new Uint8Array(bytes);
    const pattern = [0x0a, 2, 8, 2, 0x0a, 2, 8, 1, 0x0a, 3, 8, 0xe8, 0x07, 0x0a, 2, 8, 16, 0x0a, 2, 8, 64];
    let hits = 0;
    outer:
    for (let i = 0; i <= u8.length - pattern.length; i++) {
        for (let j = 0; j < pattern.length; j++) {
            if (u8[i + j] !== pattern[j]) {
                continue outer;
            }
        }
        u8.set([0x0a, 3, 0x1a, 1, 0x63], i + 8);
        hits++;
        i += pattern.length - 1;
    }
    return {bytes: u8, hits};
};

// ---------------------------------------------------------------------------
// Engine. The generator (text -> latents) and the decoder (latents -> audio)
// are independent so that each can run in its own Web Worker: neither needs
// SharedArrayBuffer, and together they use two cores.

const gaussian = () => {
    let u = 0;
    while (u === 0) {
        u = Math.random();
    }
    return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * Math.random());
};

const filledTensor = (ort, entry, shape) => {
    const size = shape.reduce((a, b) => a * b, 1);
    if (entry.dtype === 'int64') {
        return new ort.Tensor('int64', new BigInt64Array(size), shape);
    }
    if (entry.dtype === 'bool') {
        return new ort.Tensor('bool', new Uint8Array(size).fill(entry.fill === 'ones' ? 1 : 0), shape);
    }
    const data = new Float32Array(size);
    if (entry.fill === 'nan') {
        data.fill(NaN);
    } else if (entry.fill === 'ones') {
        data.fill(1);
    }
    return new ort.Tensor('float32', data, shape);
};

const absorb = (state, results, manifest) => {
    for (const entry of manifest) {
        state[entry.input_name] = results[entry.output_name];
    }
};

/**
 * Text to latents: text_conditioner, flow_lm_main and flow_lm_flow.
 */
export async function createGenerator(options) {
    const {ort, fetchBytes, fetchJson, baseurl} = options;
    const sessionOptions = options.sessionOptions || {executionProviders: ['wasm']};
    const progress = options.onProgress || (() => {});
    const bundle = options.bundle || await fetchJson(baseurl + 'bundle.json');
    const [vocab, scores] = await Promise.all([fetchJson(baseurl + 'vocab.json'), fetchJson(baseurl + 'token_scores.json')]);
    const tokenizer = new UnigramTokenizer(vocab, scores);

    const sessions = {};
    for (const [key, file] of [['text', 'text_conditioner.onnx'], ['main', 'flow_lm_main_int8.onnx'], ['flow', 'flow_lm_flow_int8.onnx']]) {
        let bytes = await fetchBytes(baseurl + file, file);
        if (key === 'main') {
            const patched = makeCacheDynamic(bytes);
            if (!patched.hits) {
                throw new Error('flow_lm_main: KV cache declaration not found');
            }
            bytes = patched.bytes;
        }
        progress({stage: 'session', file});
        sessions[key] = await ort.InferenceSession.create(bytes, sessionOptions);
    }

    const manifest = bundle.flow_lm_state_manifest;
    const latentDim = bundle.latent_dim;
    const condDim = bundle.conditioning_dim;
    const maxTokens = bundle.max_token_per_chunk || 50;
    const voices = new Map();
    const emptySeq = new ort.Tensor('float32', new Float32Array(0), [1, 0, latentDim]);
    const emptyText = new ort.Tensor('float32', new Float32Array(0), [1, 0, condDim]);
    const sTensor = new ort.Tensor('float32', Float32Array.from([0]), [1, 1]);
    const tTensor = new ort.Tensor('float32', Float32Array.from([1]), [1, 1]);

    const loadVoice = async (voiceurl) => {
        if (!voices.has(voiceurl)) {
            const tensors = parseSafetensors(await fetchBytes(voiceurl, voiceurl.split('/').pop()));
            let offset = 0;
            const layers = manifest.filter((e) => e.key === 'cache').map((e) => {
                const t = tensors[e.module + '/cache'];
                const off = tensors[e.module + '/offset'];
                offset = Math.max(offset, off ? Number(off.data[0]) : t.shape[2]);
                return t;
            });
            voices.set(voiceurl, {layers, offset});
        }
        return voices.get(voiceurl);
    };

    /** Attention state for a new sentence, sized for `length` positions. */
    const voiceState = (voice, length) => {
        const state = {};
        let layer = 0;
        for (const entry of manifest) {
            if (entry.key === 'cache') {
                const src = voice.layers[layer++];
                const [, , srcLen, heads, dim] = src.shape;
                const stride = heads * dim;
                const keep = Math.min(srcLen, length);
                const data = new Float32Array(2 * length * stride).fill(NaN);
                for (let kv = 0; kv < 2; kv++) {
                    data.set(src.data.subarray(kv * srcLen * stride, (kv * srcLen + keep) * stride), kv * length * stride);
                }
                state[entry.input_name] = new ort.Tensor('float32', data, [2, 1, length, heads, dim]);
            } else if (entry.key === 'step') {
                state[entry.input_name] = new ort.Tensor('int64', BigInt64Array.from([BigInt(voice.offset)]), [1]);
            } else {
                state[entry.input_name] = filledTensor(ort, entry, entry.shape);
            }
        }
        return state;
    };

    /**
     * @yields {{type: 'start', sentence, text} | {type: 'latent', sentence, latent: Float32Array} | {type: 'end', sentence}}
     */
    async function* latents(text, voiceurl, opts = {}) {
        const voice = await loadVoice(voiceurl);
        const std = Math.sqrt(opts.temperature ?? 0.3);
        const timing = opts.timing || {};
        const add = (k, v) => {
            timing[k] = (timing[k] || 0) + v;
        };
        const sentences = splitSentences(tokenizer, text, maxTokens);

        for (let si = 0; si < sentences.length; si++) {
            const {text: prepared, framesAfterEos} = prepareText(sentences[si]);
            const ids = tokenizer.encode(prepared);
            const frameLimit = Math.ceil((ids.length / TOKENS_PER_SECOND_ESTIMATE + GEN_SECONDS_PADDING) * bundle.frame_rate);
            const length = Math.ceil((voice.offset + ids.length + frameLimit + 4) / 16) * 16;
            const state = voiceState(voice, length);
            yield {type: 'start', sentence: si, text: prepared};

            let t0 = performance.now();
            const {embeddings} = await sessions.text.run({
                token_ids: new ort.Tensor('int64', BigInt64Array.from(ids, BigInt), [1, ids.length]),
            });
            absorb(state, await sessions.main.run({sequence: emptySeq, text_embeddings: embeddings, ...state}), manifest);
            add('text', performance.now() - t0);

            let curr = new ort.Tensor('float32', new Float32Array(latentDim).fill(NaN), [1, 1, latentDim]);
            let eosStep = null;
            for (let step = 0; step < frameLimit; step++) {
                if (opts.signal && opts.signal.aborted) {
                    return;
                }
                t0 = performance.now();
                const out = await sessions.main.run({sequence: curr, text_embeddings: emptyText, ...state});
                add('main', performance.now() - t0);
                absorb(state, out, manifest);

                if (out.eos_logit.data[0] > EOS_THRESHOLD && eosStep === null) {
                    eosStep = step;
                }
                if (eosStep !== null && step >= eosStep + framesAfterEos) {
                    break;
                }

                const x = new Float32Array(latentDim);
                if (std > 0) {
                    for (let i = 0; i < latentDim; i++) {
                        x[i] = gaussian() * std;
                    }
                }
                t0 = performance.now();
                const flow = (await sessions.flow.run({
                    c: out.conditioning, s: sTensor, t: tTensor,
                    x: new ort.Tensor('float32', x, [1, latentDim]),
                })).flow_dir.data;
                add('flow', performance.now() - t0);
                for (let i = 0; i < latentDim; i++) {
                    x[i] += flow[i];
                }
                add('frames', 1);
                curr = new ort.Tensor('float32', x, [1, 1, latentDim]);
                yield {type: 'latent', sentence: si, latent: x};
            }
            yield {type: 'end', sentence: si};
        }
    }

    return {
        bundle,
        tokenizer,
        splitSentences: (text) => splitSentences(tokenizer, text, maxTokens),
        latents,
    };
}

/**
 * Latents to audio: the streaming Mimi decoder.
 */
export async function createDecoder(options) {
    const {ort, fetchBytes, fetchJson, baseurl} = options;
    const sessionOptions = options.sessionOptions || {executionProviders: ['wasm']};
    const bundle = options.bundle || await fetchJson(baseurl + 'bundle.json');
    const session = await ort.InferenceSession.create(
        await fetchBytes(baseurl + 'mimi_decoder_int8.onnx', 'mimi_decoder_int8.onnx'), sessionOptions);
    const manifest = bundle.mimi_state_manifest;
    const latentDim = bundle.latent_dim;
    let state = null;

    return {
        sampleRate: bundle.sample_rate,
        /** Forget the previous sentence. */
        reset: () => {
            state = {};
            for (const entry of manifest) {
                state[entry.input_name] = filledTensor(ort, entry, entry.shape);
            }
        },
        /**
         * @param {Float32Array[]} frames
         * @return {Promise<Float32Array>}
         */
        decode: async (frames) => {
            if (!state) {
                throw new Error('decode() before reset()');
            }
            const data = new Float32Array(frames.length * latentDim);
            frames.forEach((f, i) => data.set(f, i * latentDim));
            const out = await session.run({latent: new ort.Tensor('float32', data, [1, frames.length, latentDim]), ...state});
            absorb(state, out, manifest);
            return Float32Array.from(out.audio_frame.data);
        },
    };
}

/**
 * Both halves in one thread, for tests and for Node.
 */
export async function createPocketTTS(options) {
    const bundle = await options.fetchJson(options.baseurl + 'bundle.json');
    const generator = await createGenerator({...options, bundle});
    const decoder = await createDecoder({...options, bundle});

    async function* stream(text, voiceurl, opts = {}) {
        const timing = opts.timing || {};
        const firstChunk = opts.firstChunkFrames ?? 2;
        const chunkFrames = opts.chunkFrames ?? 4;
        let pending = [];
        let decodedAny = false;
        const flush = async (sentence) => {
            const t = performance.now();
            const audio = await decoder.decode(pending);
            timing.decoder = (timing.decoder || 0) + performance.now() - t;
            pending = [];
            decodedAny = true;
            return {audio, sentence, stats: timing};
        };
        for await (const item of generator.latents(text, voiceurl, {...opts, timing})) {
            if (item.type === 'start') {
                decoder.reset();
                decodedAny = false;
            } else if (item.type === 'latent') {
                if (opts.onLatent) {
                    opts.onLatent(item.latent, item.sentence);
                }
                pending.push(item.latent);
                if (pending.length >= (decodedAny ? chunkFrames : firstChunk)) {
                    yield await flush(item.sentence);
                }
            } else if (pending.length) {
                yield await flush(item.sentence);
            }
        }
    }

    return {
        sampleRate: decoder.sampleRate,
        tokenizer: generator.tokenizer,
        splitSentences: generator.splitSentences,
        stream,
    };
}
