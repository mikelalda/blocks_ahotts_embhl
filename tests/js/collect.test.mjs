/**
 * Collection of readable text from a Moodle page, its same-origin SCORM frames
 * and dynamically added content.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {loadSpeech, stubLayout, tick} from './helpers.mjs';

const page = (body) => `<!doctype html><html><body>
    <div id="readspeaker_button1" class="rs_skip rsbtn">
        <a class="rsbtn_play" href="#"><span class="rsbtn_text"><span>Listen</span></span></a>
    </div>
    <div id="region-main">${body}</div>
</body></html>`;

const texts = (items) => items.filter((i) => i.kind === 'local').map((i) => i.text);

const collect = (speech, root) => {
    const out = [];
    speech.testing.collectFrom(root, out, {seen: Object.create(null), depth: 0});
    return out;
};

test('reads the block level text of an ordinary Moodle page', () => {
    const speech = loadSpeech(page('<h2>Kaixo</h2><p>Lehen paragrafoa.</p><ul><li>Zerrenda</li></ul>'));
    const out = collect(speech, speech.document.getElementById('region-main'));
    assert.deepEqual(texts(out), ['Kaixo', 'Lehen paragrafoa.', 'Zerrenda']);
});

test('keeps the document order of the chunks', () => {
    const speech = loadSpeech(page('<p>Bat</p><p>Bi</p><p>Hiru</p>'));
    const out = collect(speech, speech.document.getElementById('region-main'));
    assert.deepEqual(texts(out), ['Bat', 'Bi', 'Hiru']);
});

test('skips the reader controls, Moodle navigation and hidden content', () => {
    const speech = loadSpeech(page([
        '<nav><p>Ibilbide orria</p></nav>',
        '<div class="breadcrumb"><p>Ikastaroa</p></div>',
        '<p style="display: none">Ezkutua</p>',
        '<p hidden>Hidden attribute</p>',
        '<p aria-hidden="true">Aria hidden</p>',
        '<p class="accesshide">Skip to main content</p>',
        '<p>Benetako edukia.</p>',
    ].join('')));
    const document = speech.document;
    // The button lives outside the read region, but also inside it in "show in
    // content" mode; check the selector, not the placement.
    document.getElementById('region-main').appendChild(
        document.getElementById('readspeaker_button1')
    );
    const out = collect(speech, document.getElementById('region-main'));
    assert.deepEqual(texts(out), ['Benetako edukia.']);
});

test('drops duplicated text', () => {
    const speech = loadSpeech(page('<p>Errepikatua</p><p>Errepikatua</p><p>Beste bat</p>'));
    const out = collect(speech, speech.document.getElementById('region-main'));
    assert.deepEqual(texts(out), ['Errepikatua', 'Beste bat']);
});

test('keeps only leaf blocks, so nested markup is not read twice', () => {
    const speech = loadSpeech(page('<blockquote><p>Aipua</p></blockquote>'));
    const out = collect(speech, speech.document.getElementById('region-main'));
    assert.deepEqual(texts(out), ['Aipua']);
});

test('splits long text into chunks on sentence boundaries', () => {
    const speech = loadSpeech(page('<p>x</p>'));
    const long = 'Lehen esaldia da hau eta nahiko luzea. '.repeat(8);
    const chunks = speech.testing.splitText(long);
    assert.ok(chunks.length > 1, 'long text is split');
    chunks.forEach((chunk) => assert.ok(chunk.length <= 200, `chunk too long: ${chunk.length}`));
    assert.equal(chunks.join(' ').replace(/\s+/g, ' ').trim(), long.replace(/\s+/g, ' ').trim());
});

test('reads a full SCORM package inside a same-origin iframe', async () => {
    const speech = loadSpeech(page('<p>Moodle testua.</p><iframe id="scorm" src="about:blank"></iframe>'));
    const frame = speech.document.getElementById('scorm');
    await tick(10);
    stubLayout(frame.contentWindow);
    frame.contentDocument.body.innerHTML = '<h1>SCORM izenburua</h1><p>SCORM edukia.</p>';

    const out = collect(speech, speech.document.getElementById('region-main'));
    assert.deepEqual(texts(out), ['Moodle testua.', 'SCORM izenburua', 'SCORM edukia.']);
    assert.equal(out.filter((i) => i.kind === 'frame').length, 0, 'same-origin frames need no bridge');
});

test('descends into nested same-origin iframes', async () => {
    const speech = loadSpeech(page('<iframe id="outer" src="about:blank"></iframe>'));
    const outer = speech.document.getElementById('outer');
    await tick(10);
    stubLayout(outer.contentWindow);
    outer.contentDocument.body.innerHTML = '<p>Kanpoko markoa.</p><iframe id="inner" src="about:blank"></iframe>';
    const inner = outer.contentDocument.getElementById('inner');
    await tick(10);
    stubLayout(inner.contentWindow);
    inner.contentDocument.body.innerHTML = '<p>Barruko markoa.</p>';

    const out = collect(speech, speech.document.getElementById('region-main'));
    assert.deepEqual(texts(out), ['Kanpoko markoa.', 'Barruko markoa.']);
});

test('picks up content added after the page loaded (SPA navigation)', () => {
    const speech = loadSpeech(page('<p>Hasierako testua.</p>'));
    const region = speech.document.getElementById('region-main');
    assert.deepEqual(texts(collect(speech, region)), ['Hasierako testua.']);

    // A SPA replaces the region; a second collection sees the new content only.
    region.innerHTML = '<p>Orri berria.</p>';
    assert.deepEqual(texts(collect(speech, region)), ['Orri berria.']);

    region.insertAdjacentHTML('beforeend', '<p>Geroago erantsia.</p>');
    assert.deepEqual(texts(collect(speech, region)), ['Orri berria.', 'Geroago erantsia.']);
});

test('underlines the chunk being read and restores the element afterwards', () => {
    const speech = loadSpeech(page('<p id="p1" style="text-decoration: overline">Testua</p>'));
    const el = speech.document.getElementById('p1');

    speech.testing.highlight(el);
    assert.equal(el.style.textDecoration, 'underline');

    speech.testing.unhighlight(el);
    assert.equal(el.style.textDecoration, 'overline');
    assert.equal(el.dataset.ahottsTextdecoration, undefined);
});
