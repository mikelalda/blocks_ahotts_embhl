/**
 * Choosing the reading language and the engine that speaks it: Basque with the
 * local Piper voices, and the languages without one with the browser.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import {loadSpeech, tick} from './helpers.mjs';

const LABELS = {
    listen: 'Entzun',
    loading: 'Kargatzen',
    pause: 'Pausatu',
    resume: 'Jarraitu',
    stop: 'Gelditu',
    language: 'Hizkuntza',
    voice: 'Ahotsa',
};

const STATUS = {
    preparingvoice: 'Ahotsa prestatzen.',
    voicefallback: 'Lineako zerbitzua erabiliko da.',
    enginesunavailable: 'Ez dago ahotsik.',
    nocontent: 'Ez dago testurik.',
    externalnobridge: 'Kanpoko edukia.',
};

const PIPER = {
    defaults: {eu: 'antton', es: 'davefx'},
    chooser: true,
    backend: 'auto',
    orturl: 'https://moodle.example.org/ort/ort.webgpu.min.js',
    wasmpath: 'https://moodle.example.org/ort/',
    phonemizerurl: 'https://moodle.example.org/phonemizer.js',
    loaderurl: 'https://moodle.example.org/blocks/ahotts_embhl/js/esm-bridge.js',
    voices: [
        {id: 'antton', label: 'Antton', modelurl: 'https://models.example.org/antton.onnx', language: 'eu'},
        {id: 'maider', label: 'Maider', modelurl: 'https://models.example.org/maider.onnx', language: 'eu'},
        {id: 'davefx', label: 'Davefx', modelurl: 'https://models.example.org/davefx.onnx', language: 'es'},
        {id: 'claude', label: 'Claude', modelurl: 'https://models.example.org/claude.onnx', language: 'es'},
    ],
};

const LANGUAGES = [
    {
        code: 'eu', label: 'Euskara', bcp47: 'eu-ES',
        engines: [{kind: 'piper'}, {kind: 'api', url: 'https://tts.example.org/eu', language: 'eu', voice: 'antton'}],
    },
    {
        code: 'es', label: 'Espainiera', bcp47: 'es-ES',
        engines: [
            {kind: 'piper'},
            {kind: 'webspeech'},
            {kind: 'api', url: 'https://tts.example.org/es', language: 'es', voice: 'laura'},
        ],
    },
    {code: 'en', label: 'Ingelesa', bcp47: 'en-GB', engines: [{kind: 'webspeech'}]},
];

const page = (body, attrs = '') => `<!doctype html><html${attrs}><body>
    <div class="block_ahotts_embhl">
        <div id="readspeaker_button1" class="rs_skip rsbtn">
            <a class="rsbtn_play" href="#"><span class="rsbtn_text"><span>Entzun</span></span></a>
        </div>
    </div>
    <div id="region-main">${body}</div>
</body></html>`;

/**
 * An engine that records how it was used instead of making any sound.
 *
 * @param {String} kind
 * @param {Array} log
 * @param {Object} options {warmup}
 * @return {Object}
 */
const recorder = (kind, log, options = {}) => ({
    kind,
    warmup: options.warmup === undefined ? undefined : () => Promise.resolve(options.warmup),
    prepare: (text) => {
        log.push({kind, text});
        return Promise.resolve(text);
    },
    play: () => Promise.resolve(),
    pause: () => {},
    resume: () => {},
    stop: () => {},
    release: () => {},
});

/**
 * Start the block with every engine replaced by a recorder.
 *
 * @param {Object} overrides Configuration overrides.
 * @param {Object} options {piperWarmup, html, htmlattrs, stored}
 * @return {Object}
 */
const start = (overrides = {}, options = {}) => {
    const speech = loadSpeech(page(options.html || '<p>Testua.</p>', options.htmlattrs || ''));

    // couldRun() asks the browser whether it can speak at all.
    speech.window.speechSynthesis = {speak() {}, cancel() {}, pause() {}, resume() {}};
    speech.window.SpeechSynthesisUtterance = function (text) {
        this.text = text;
    };

    const log = [];
    const built = [];
    const factories = speech.testing.engineFactories;
    factories.piper = (config) => {
        built.push({kind: 'piper', config});
        return recorder('piper', log, {warmup: options.piperWarmup !== false});
    };
    factories.webspeech = (lang) => {
        built.push({kind: 'webspeech', lang});
        return recorder('webspeech', log);
    };
    factories.api = (spec) => {
        built.push({kind: 'api', spec});
        return recorder('api', log);
    };

    // Seed what a reader would have chosen on an earlier page, before init
    // reads it back.
    const stored = options.stored || {};
    if (stored.language) {
        speech.window.localStorage.setItem('block_ahotts_embhl_language', stored.language);
    }
    Object.entries(stored.voices || {}).forEach(([code, voice]) => {
        speech.window.localStorage.setItem('block_ahotts_embhl_voice:' + code, voice);
    });

    speech.init(Object.assign({
        readid: 'region-main',
        maxlen: 3000,
        labels: LABELS,
        status: STATUS,
        langmode: 'fixed',
        lang: 'eu',
        languages: LANGUAGES,
        piper: PIPER,
        bridge: {enabled: false, origins: [], timeout: 200},
    }, overrides));

    return {
        ...speech,
        log,
        built,
        link: speech.document.querySelector('a.rsbtn_play'),
        status: speech.document.querySelector('.ahotts-status'),
        controls: speech.document.querySelector('.ahotts-controls'),
    };
};

test('normalises any language tag to its base code', () => {
    const speech = loadSpeech(page(''));
    const {normaliseLanguage} = speech.testing;

    assert.equal(normaliseLanguage('eu_ES'), 'eu');
    assert.equal(normaliseLanguage('eu-ES'), 'eu');
    assert.equal(normaliseLanguage('EU'), 'eu');
    assert.equal(normaliseLanguage('es_419'), 'es');
    assert.equal(normaliseLanguage(''), '');
});

test('the fixed mode reads the language the block was configured with', () => {
    const speech = loadSpeech(page(''));
    const config = {langmode: 'fixed', lang: 'es', readid: 'region-main', languages: LANGUAGES};

    assert.equal(speech.testing.pickLanguage(config, speech.document, 'en').code, 'es');
});

test('an unknown configured language falls back to the first one offered', () => {
    const speech = loadSpeech(page(''));
    const config = {langmode: 'fixed', lang: 'is', readid: 'region-main', languages: LANGUAGES};

    assert.equal(speech.testing.pickLanguage(config, speech.document, '').code, 'eu');
});

test('the content mode follows the lang attribute the page declares', () => {
    const speech = loadSpeech(page('<p>Hola.</p>', ' lang="es-ES"'));
    const config = {langmode: 'content', lang: 'eu', readid: 'region-main', languages: LANGUAGES};

    assert.equal(speech.testing.pickLanguage(config, speech.document, '').code, 'es');
});

test('a lang attribute on the reading region wins over the one on the page', () => {
    const speech = loadSpeech(page('<p>Hello.</p>', ' lang="es-ES"'));
    speech.document.getElementById('region-main').setAttribute('lang', 'en-GB');
    const config = {langmode: 'content', lang: 'eu', readid: 'region-main', languages: LANGUAGES};

    assert.equal(speech.testing.pickLanguage(config, speech.document, '').code, 'en');
});

test('a declared language nobody can speak falls back to the configured one', () => {
    const speech = loadSpeech(page('<p>Halló.</p>', ' lang="is-IS"'));
    const config = {langmode: 'content', lang: 'eu', readid: 'region-main', languages: LANGUAGES};

    assert.equal(speech.testing.pickLanguage(config, speech.document, '').code, 'eu');
});

test('the chooser mode honours what the reader picked before', () => {
    const speech = loadSpeech(page(''));
    const config = {langmode: 'chooser', lang: 'eu', readid: 'region-main', languages: LANGUAGES};

    assert.equal(speech.testing.pickLanguage(config, speech.document, 'en').code, 'en');
    assert.equal(speech.testing.pickLanguage(config, speech.document, 'zz').code, 'eu', 'unknown choice');
});

test('the old single-language configuration still works', () => {
    const speech = loadSpeech(page(''));
    const {adaptLegacyConfig} = speech.testing;

    // The module runs in the jsdom realm, so copy its arrays before comparing.
    const kinds = (adapted) => [...adapted.languages[0].engines].map((engine) => engine.kind);

    const basque = adaptLegacyConfig({prefer: 'api', api: {url: 'https://tts/eu', language: 'eu'}});
    assert.equal(basque.languages.length, 1);
    assert.deepEqual(kinds(basque), ['api']);

    const spanish = adaptLegacyConfig({prefer: 'webspeech', webspeech: {lang: 'es-ES'}, api: {url: 'https://tts/es'}});
    assert.deepEqual(kinds(spanish), ['webspeech', 'api']);
    assert.equal(spanish.languages[0].bcp47, 'es-ES');

    const already = adaptLegacyConfig({languages: LANGUAGES});
    assert.equal(already.languages, LANGUAGES, 'a new configuration is left alone');
});

test('Basque is read by the local itzune voice', async () => {
    const ctx = start({lang: 'eu'});

    ctx.link.click();
    await tick(20);

    assert.deepEqual(ctx.log.map((entry) => entry.kind), ['piper']);
    assert.equal(ctx.built[0].kind, 'piper');
    assert.equal(ctx.built[0].config.modelurl, 'https://models.example.org/antton.onnx');
    assert.equal(ctx.built[0].config.language, undefined, 'the model names its own eSpeak voice');
    assert.equal(ctx.built[0].config.backend, 'auto');
    assert.equal(ctx.status.textContent, '', 'a voice that starts raises no notice');
});

test('Spanish is read by its own local voice', async () => {
    const ctx = start({lang: 'es'});
    ctx.link.click();
    await tick(20);

    assert.deepEqual(ctx.log.map((entry) => entry.kind), ['piper']);
    assert.equal(ctx.built[0].config.modelurl, 'https://models.example.org/davefx.onnx');
});

test('a language with no local voice is read by the browser', async () => {
    const ctx = start({lang: 'en'});
    ctx.link.click();
    await tick(20);

    assert.deepEqual(ctx.log.map((entry) => entry.kind), ['webspeech']);
    assert.equal(ctx.built[0].lang, 'en-GB', 'with the right BCP-47 tag');
});

test('Spanish falls back to the browser when its local voice cannot start', async () => {
    const ctx = start({lang: 'es'}, {piperWarmup: false});
    ctx.link.click();
    await tick(20);

    assert.deepEqual(ctx.log.map((entry) => entry.kind), ['webspeech']);
    assert.equal(ctx.built[1].lang, 'es-ES');
});

test('Basque falls back to the aHoTTS API when the local voice cannot start', async () => {
    const ctx = start({lang: 'eu'}, {piperWarmup: false});

    ctx.link.click();
    await tick(20);

    assert.deepEqual(ctx.log.map((entry) => entry.kind), ['api'], 'the API spoke instead');
    assert.equal(ctx.status.textContent, STATUS.voicefallback, 'and the reader is told');
});

test('says so when no engine at all can speak the language', async () => {
    const ctx = start({
        lang: 'eu',
        languages: [{code: 'eu', label: 'Euskara', bcp47: 'eu-ES', engines: [{kind: 'piper'}]}],
    }, {piperWarmup: false});

    ctx.link.click();
    await tick(20);

    assert.equal(ctx.log.length, 0);
    assert.match(ctx.status.textContent, /Ez dago ahotsik/);
});

test('the button stays inert when nothing is configured to speak', () => {
    const ctx = start({
        lang: 'eu',
        languages: [{code: 'eu', label: 'Euskara', bcp47: 'eu-ES', engines: [{kind: 'api', url: ''}]}],
        piper: null,
    });

    assert.equal(ctx.document.querySelector('.ahotts-controls'), null);
    assert.equal(ctx.document.querySelector('.rsbtn_stop'), null);
});

test('the chooser offers the languages and remembers the choice', async () => {
    const ctx = start({langmode: 'chooser', lang: 'eu'});

    const select = ctx.document.querySelector('select.ahotts-language');
    assert.ok(select, 'a language menu is shown');
    assert.deepEqual([...select.options].map((option) => option.value), ['eu', 'es', 'en']);
    assert.equal(select.value, 'eu');

    const label = ctx.document.querySelector('label[for="' + select.id + '"]');
    assert.equal(label.textContent, LABELS.language, 'and it is labelled for screen readers');

    select.value = 'es';
    select.dispatchEvent(new ctx.window.Event('change'));
    ctx.link.click();
    await tick(20);

    assert.deepEqual(ctx.log.map((entry) => entry.kind), ['piper'], 'Spanish has a local voice');
    assert.equal(ctx.window.localStorage.getItem('block_ahotts_embhl_language'), 'es');
});

test('a language remembered from an earlier page is used again', async () => {
    const ctx = start({langmode: 'chooser', lang: 'eu'}, {stored: {language: 'en'}});

    assert.equal(ctx.document.querySelector('select.ahotts-language').value, 'en');

    ctx.link.click();
    await tick(20);

    assert.deepEqual(ctx.log.map((entry) => entry.kind), ['webspeech']);
    assert.equal(ctx.built[0].lang, 'en-GB');
});

test('a voice remembered from an earlier page is used again', async () => {
    const ctx = start({lang: 'eu'}, {stored: {voices: {eu: 'maider'}}});

    assert.equal(ctx.document.querySelector('select.ahotts-voice').value, 'maider');

    ctx.link.click();
    await tick(20);

    assert.equal(ctx.built[0].config.modelurl, 'https://models.example.org/maider.onnx');
});

test('no language menu is shown unless the chooser mode is on', () => {
    const ctx = start({langmode: 'fixed', lang: 'eu'});

    assert.equal(ctx.document.querySelector('select.ahotts-language'), null);
});

test('the voice menu switches the model that is loaded', async () => {
    const ctx = start({lang: 'eu'});

    const select = ctx.document.querySelector('select.ahotts-voice');
    assert.ok(select, 'a voice menu is shown for Basque');
    assert.deepEqual([...select.options].map((option) => option.value), ['antton', 'maider']);

    select.value = 'maider';
    select.dispatchEvent(new ctx.window.Event('change'));
    ctx.link.click();
    await tick(20);

    assert.equal(ctx.built[0].config.modelurl, 'https://models.example.org/maider.onnx');
    assert.equal(ctx.window.localStorage.getItem('block_ahotts_embhl_voice:eu'), 'maider');
});

test('the voice menu offers the voices of the language being read', () => {
    const ctx = start({langmode: 'chooser', lang: 'eu'});
    const languageSelect = ctx.document.querySelector('select.ahotts-language');
    const voiceSelect = ctx.document.querySelector('select.ahotts-voice');

    assert.deepEqual([...voiceSelect.options].map((option) => option.value), ['antton', 'maider']);

    languageSelect.value = 'es';
    languageSelect.dispatchEvent(new ctx.window.Event('change'));

    assert.deepEqual([...voiceSelect.options].map((option) => option.value), ['davefx', 'claude']);
    assert.equal(voiceSelect.value, 'davefx', 'and the default voice of that language is chosen');
});

test('a voice chosen for one language is not carried into another', async () => {
    const ctx = start({langmode: 'chooser', lang: 'eu'}, {stored: {voices: {eu: 'maider'}}});
    const languageSelect = ctx.document.querySelector('select.ahotts-language');

    languageSelect.value = 'es';
    languageSelect.dispatchEvent(new ctx.window.Event('change'));
    ctx.link.click();
    await tick(20);

    assert.equal(ctx.built[0].config.modelurl, 'https://models.example.org/davefx.onnx');
});

test('the voice menu is hidden while a language without local voices is read', () => {
    const ctx = start({langmode: 'chooser', lang: 'eu'});
    const languageSelect = ctx.document.querySelector('select.ahotts-language');
    const voiceSelect = ctx.document.querySelector('select.ahotts-voice');

    assert.equal(voiceSelect.hidden, false);

    languageSelect.value = 'en';
    languageSelect.dispatchEvent(new ctx.window.Event('change'));
    assert.equal(voiceSelect.hidden, true, 'English has no local voice to pick');

    languageSelect.value = 'eu';
    languageSelect.dispatchEvent(new ctx.window.Event('change'));
    assert.equal(voiceSelect.hidden, false);
});

test('an engine is built once and reused across readings', async () => {
    const ctx = start({lang: 'eu'});

    ctx.link.click();
    await tick(20);
    ctx.link.click();
    await tick(20);

    assert.equal(ctx.built.filter((entry) => entry.kind === 'piper').length, 1);
    assert.equal(ctx.log.length, 2, 'but it spoke both times');
});

test('the controls are never read aloud', async () => {
    const ctx = start({langmode: 'chooser', lang: 'es'}, {html: '<p>Irakurri hau.</p>'});

    ctx.link.click();
    await tick(20);

    assert.deepEqual(ctx.log.map((entry) => entry.text), ['Irakurri hau.']);
});
