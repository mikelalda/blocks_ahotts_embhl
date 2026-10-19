block_ahotts_embhl
==================

Moodle block that adds a Listen button to the page and reads its content aloud,
chunk by chunk, with the text currently being spoken underlined.

* **Basque (eu)** is spoken by the **itzune voices running inside the browser**:
  Piper models in ONNX format, executed by ONNX Runtime Web on WebGPU, or on
  WebAssembly where WebGPU is not available. No text leaves the learner's
  machine. If the voice cannot be started, the block falls back to the **aHoTTS
  API** configured in the settings.
* **Spanish (es) and English (en)** also have local Piper voices (Davefx; Alba
  and Ryan), with the browser's **Web Speech API** and the aHoTTS API behind
  them.
* **Every other language** (fr, ca, gl, de, it, pt) is spoken by the browser's
  own Web Speech API, with the aHoTTS API behind it when one is configured for
  that language.

The next chunk is synthesised while the current one plays, so playback starts
almost immediately.

Which language is read can be fixed by the administrator, taken from the Moodle
interface, taken from the `lang` attribute of the content, or **chosen by the
reader** in the block itself. Readers can also pick which Basque voice they
prefer.

Besides the page itself, the block reads **content inside iframes**: SCORM
packages, H5P activities and anything else embedded in the reading region,
including iframes nested inside iframes.

Per-course deployment
---------------------

A block added to a course is shown on every page of that course, activities
included (`pagetypepattern=*`, shown in sub-contexts). To add it to many
courses at once, `cli/provision_courses.php` creates or widens one instance per
course and, optionally, sets SCORM activities to open in the current window so
the block can read them. It only simulates unless `--apply` is given:

```bash
php blocks/ahotts_embhl/cli/provision_courses.php --courseid=261 --language=eu --scorm-current-window --dry-run
php blocks/ahotts_embhl/cli/provision_courses.php --all --scorm-current-window --apply
```

The language of each course comes, in order, from `--language`,
`--language-map`, `course.lang`, and finally an offline lexical detection
(Spanish, Basque, English) over the course name, summary and section, page and
SCORM titles. Ambiguous courses are skipped and reported, never guessed.
Existing instances, exceptions and inherited blocks are never deleted. See
[cli/USAGE.txt](cli/USAGE.txt) for the full procedure, and
`cli/test_course_language.php` for the detection tests, which need no database.

Downloading audio for offline listening
--------------------------------------

**Download audio** generates a single mono MP3 (22050 Hz, 48 kbit/s) in
reading order, encoded with the bundled lamejs (LGPL, `js/lame/`) in a Web
Worker as the fragments are synthesised, one after the other. Where that
worker cannot start, a 16-bit WAV is saved instead. On a SCORM package or a book it reads the **whole activity**,
from `text.php`: every lesson of the package (as for the PDF below) or every
chapter of the book the reader may see. Elsewhere it reads all the text the
block currently receives from the page. It uses the selected
language and local voice (or the configured aHoTTS API). It shows progress and
can be cancelled with **Stop**. The file downloads automatically where allowed;
**Save audio** also remains available for an explicit save. Once saved,
the learner can listen without opening Moodle or connecting to the internet.

Every export writes one line to the web server log through `clientlog.php`
(`block_ahotts_embhl client: ... exportstart|exportdone|exporterror|exportcancel`),
with the source, engine, number of fragments and, on failure, the fragment and
the error, since synthesis happens in the browser and leaves no other trace.

**Download PDF** saves a PDF embedded or linked from the reading area. For a
PDF.js viewer inside a same-origin SCORM package, it downloads the viewer's
`file` document.

On a SCORM activity with no PDF on screen, **Download PDF** offers the PDF of
the whole course, built on the server from the files of the published package
(*Build a PDF from SCORM packages*, on by default):

* **Rise 360**: every lesson and block from the course data in
  `scormcontent/locales/`, with accordions, tabs and flashcards expanded and
  images included. Questions are listed with their options, never with the
  correct answer or the feedback.
* **Storyline 360**: the text of every slide from `html5/data/js/`, in story
  order, leaving feedback layers out. Storyline blocks inside a Rise course are
  read the same way.
* **Anything else** (eXeLearning, plain HTML): the pages listed by
  `imsmanifest.xml`, in the order of its organisation, without navigation.

The package is read, never played, so building the PDF cannot move a learner
through the course or change their progress. Text and images are kept; layout,
animation and interactions are not. The PDF is stored per package revision
(`block_ahotts_embhl/scormpdf` in the activity context): replacing the package
builds a new one in the background (ad hoc task) or, failing that, on the next
download. `scormpdf.php` serves it to whoever may open the activity, within its
opening dates. If the setting is off, a PDF shipped inside the package is
offered instead.

Export skips Web Speech because browser speech does not expose audio data.
Languages that only have Web Speech need a local Piper voice or a configured
API before they can be downloaded. Generation needs access to any uncached
voice/runtime files or the API. The playback character cap does not truncate
exports. External content that cannot be collected is announced as usual;
hidden pages of a SCORM package are not part of the received text. Generation
errors do not produce a partial file. Long exports take time and browser memory.

Requirements
------------

Moodle 3.0 or later. A modern browser. The local voices need no endpoint; an
aHoTTS endpoint is only needed as a fallback or for languages without a local
voice.

Installation
------------

1. Copy this directory to `blocks/ahotts_embhl` in your Moodle installation.
2. Visit *Site administration → Notifications* to complete the install.
3. Configure the plugin at *Site administration → Plugins → Blocks →
   ReadSpeaker webReader*.

See <https://docs.moodle.org/en/Installing_plugins> for the general procedure.

Settings
--------

| Setting | Meaning |
|---|---|
| Reading area ID | Id of the element that is read. Default `region-main`. |
| Basque / Spanish / English API URL | Base URL of your aHoTTS service. The block posts to `<base>/synthesize`. |
| Basque / Spanish voice | Voice name passed to the API. |
| Maximum text length | Character cap across all chunks of one reading. |
| How the reading language is decided | `fixed`, `page`, `content` or `chooser`; see below. |
| Languages offered | Languages the reader may pick from. |
| Use the itzune voices in the browser | Turns on local Basque synthesis. |
| Default voice per language | Basque `antton` or `maider`, Spanish `davefx`, English `alba` or `ryan`. |
| Let the reader choose the voice | Adds a voice menu to the block. |
| Execution backend | `auto`, `webgpu` or `wasm`. |
| Model URL of each voice | Where the `.onnx` voice is fetched from. |
| ONNX Runtime Web URL, WebAssembly directory, Phonemizer URL | Where the runtime is fetched from. |
| Enable the cross-origin SCORM bridge | Turns on the cooperative bridge client. |
| Allowed SCORM origins | Origins allowed to answer the bridge, one per line. |
| Bridge timeout | How long to wait for an external package to answer. |

Choosing the reading language
-----------------------------

*Site administration → Plugins → Blocks → ReadSpeaker webReader → How the
reading language is decided* offers four ways, in increasing order of how much
the reader is involved:

| Mode | What it does | Use it when |
|---|---|---|
| **Fixed** | Always the language configured for the site or the block instance. | The whole site is in one language. |
| **Follow the Moodle interface language** | Uses `current_language()`, so the button follows the language the user picked for Moodle. | The site is multilingual and content follows the interface. |
| **Follow the `lang` attribute of the content** | Reads the `lang` attribute of the reading region, falling back to `<html lang>`. Resolved in the browser, so it works with dynamically loaded content. | Courses mix languages, and the content is correctly tagged. |
| **Let the reader choose** | Adds a language menu to the block. The choice is kept in the browser (`localStorage`) and applies on every later page. | Learners read in a language that is not the one the page is tagged with. |

The last two modes read from *Languages offered*; the other two use the language
of the block or the site. Whatever the mode, only languages that something can
actually speak are offered, so Basque disappears from the menu if neither the
local voices nor a Basque aHoTTS endpoint are available.

Basque voices in the browser
----------------------------

The voices come from [huggingface.co/itzune](https://huggingface.co/itzune):
[`itzune/antton-tts`](https://huggingface.co/itzune/antton-tts) (male) and
[`itzune/maider-tts`](https://huggingface.co/itzune/maider-tts) (female), both
Piper/VITS models exported to ONNX and published under Apache-2.0. The Spanish
(`davefx`) and English (`alba`, `ryan`) voices come from
[`rhasspy/piper-voices`](https://huggingface.co/rhasspy/piper-voices) and run
through the same pipeline.

The pipeline runs entirely in the page:

```
text --(eSpeak NG, WebAssembly)--> IPA phonemes
     --(phoneme_id_map of the voice)--> int64 ids
     --(VITS ONNX model, WebGPU or WebAssembly)--> waveform --> WAV
```

What that costs the first time a learner presses Listen: about **65 MB** for the
voice, plus the ONNX Runtime Web build and its `.wasm` (around 20 MB for the
WebGPU-capable one). Both are kept in the browser's Cache Storage, so it happens
once per browser and not once per page. After that, synthesis is local, offline
and private.

WebGPU is used when the browser offers it (Chrome and Edge, Safari 26, recent
Firefox) and WebAssembly otherwise; parts of the model fall back to WebAssembly
on some drivers even when WebGPU is available. Set *Execution backend* to `wasm`
to make the behaviour uniform.

### Self-hosting the runtime and the voices

The defaults point at jsDelivr and Hugging Face, which is convenient for trying
the feature out but makes your site depend on two third parties, and will be
blocked by a `Content-Security-Policy` that restricts `script-src` or
`connect-src`. For production, download these once and serve them from your own
site, then point the settings at your copies:

| What | Where it comes from | Licence |
|---|---|---|
| `ort.webgpu.min.js` and `ort-wasm-simd-threaded.jsep.wasm` | [`onnxruntime-web`](https://www.npmjs.com/package/onnxruntime-web) | MIT |
| `espeak-ng.js` and `espeak-ng.wasm` (default phonemizer, all languages) | [`espeak-ng`](https://www.npmjs.com/package/espeak-ng) | GPL-3.0 |
| `eu-antton-medium.onnx` and its `.json` | `itzune/antton-tts` | Apache-2.0 |
| `eu-maider-medium.onnx` and its `.json` | `itzune/maider-tts` | Apache-2.0 |
| Spanish and English `.onnx` voices and their `.json` | `rhasspy/piper-voices` | Per voice; check each model card |

The voice configuration is read from the model URL with `.json` appended, which
is the Piper convention and matches how the files are published. If you serve
them from another domain, that domain has to allow cross-origin reads.

The phonemizer has to be a full eSpeak NG build: the `phonemizer` npm package
only carries English, and with it the Basque voices produce no sound.

The block loads the runtime with an ordinary `<script>` tag and the phonemizer
through `js/esm-bridge.js`, a small ES module shipped with the plugin. Neither
uses `eval()` nor an inline script, so a strict CSP only has to allow the
origins the files are served from.

Natural Basque voices: Pocket TTS
---------------------------------

Basque is read first by [Pocket TTS](https://huggingface.co/kyutai/pocket-tts)
(Kyutai), fine-tuned for Basque by itzune and published as
[`itzune/pocket-tts-basque-onnx`](https://huggingface.co/itzune/pocket-tts-basque-onnx)
(CC BY 4.0). It sounds more natural than Piper and offers five voices (Maider,
Antton, Nahia, Ane, Aitor). Piper stays behind it: when Pocket TTS cannot start,
or when the reader picks a Piper voice in the menu, Piper reads.

```
text --(Basque normaliser: 2024an, 10:30ean, %25...)--> words
     --(SentencePiece)--> tokens --(text_conditioner)--> embeddings
     --(flow_lm_main + flow_lm_flow, one 80 ms frame per step)--> latents   [worker 1]
     --(Mimi decoder, streaming; WSOLA time stretch)--> 24 kHz audio      [worker 2]
     --> Web Audio, played as it arrives
```

* **Two workers.** `js/pockettts/generator.js` and `js/pockettts/decoder.js`
  run single-threaded ONNX Runtime Web each, so two cores are used without the
  cross-origin isolation (COOP/COEP) that WebAssembly threads would need.
* **Smaller attention cache.** The published graph declares a 1000-position
  KV cache; the worker rewrites that declaration (5 bytes) so each sentence
  only carries what it needs, with identical output.
* **Numbers.** The model reads letters only, so `js/pockettts/normalize-eu.js`
  spells out numbers, dates, times, ordinals, percentages, units and common
  abbreviations, keeping the case endings written on them (`1990eko`, `10ean`).
* **Download.** About 125 MB the first time (int8 graphs), kept in Cache
  Storage. The model folder can be self-hosted (*Pocket TTS model folder*).
* **Speed.** On a 2020s laptop it generates 1.3-1.5x faster than real time. On
  slower devices playback waits for a little more audio after each stutter.

Credits: Kyutai Pocket TTS; the HiTZ-Aholab studio corpus (Maider, Antton);
the BSSD dataset (itzune/bssd); `pocket_tts_onnx.py` by KevinAHM (Apache-2.0),
of which `js/pockettts/engine.js` is a port.

Reading speed
-------------

The block offers a speed menu (0.5x to 2x, *Let the reader choose the speed*),
remembered in the reader's browser, and a site default. The pitch is kept:
Piper and the aHoTTS API play through `<audio>` with `preservesPitch`, Pocket TTS
is stretched with WSOLA in its decoder worker, and the browser voice uses
`utterance.rate`. Downloaded audio is always at normal speed.

Reading iframes: what works and what cannot
-------------------------------------------

The browser's **Same-Origin Policy** prevents any page from reading the DOM of an
iframe served from another origin. That is a browser security guarantee, not a
bug to work around: this plugin does **not** attempt to defeat CORS, does not use
`document.domain`, and ships no proxy.

Consequently there are two cases:

* **Same origin** — the content is read directly, at any nesting depth. A SCORM
  package uploaded to this Moodle is served from your own `wwwroot`, so it falls
  in this case and needs no configuration at all.
* **Cross origin** — the content can only be read if the package itself
  cooperates, by shipping `bridge/ahotts-scorm-bridge.js` and answering the
  plugin's postMessage protocol, **and** its origin is listed in *Allowed SCORM
  origins*. Otherwise the block reads the rest of the page and announces, through
  an `aria-live` status line, that part of the page is external content that does
  not allow reading.

### Compatibility matrix

| Content | Read aloud? | How |
|---|---|---|
| Ordinary Moodle page | Yes | Direct DOM traversal of the reading region. |
| SCORM uploaded to this Moodle | Yes | Same origin; read directly. |
| H5P, book, page, quiz text in an embedded frame on this site | Yes | Same origin; read directly. |
| Same-origin iframes nested inside iframes (up to 8 levels) | Yes | Recursive traversal. |
| Content added by JavaScript or by SPA-style navigation | Yes | The page is collected again each time Listen is pressed. |
| Cross-origin SCORM **with** the bridge, origin allow-listed | Yes | Versioned postMessage protocol; audio is still synthesised by Moodle. |
| Cross-origin SCORM with the bridge, origin **not** allow-listed | No | Refused on purpose; accessible status message. |
| Cross-origin SCORM **without** the bridge | No | Handshake times out; accessible status message. |
| Cross-origin iframe nested inside a cross-origin package | No | Would need bridge-to-bridge relaying; see *Limitations*. |
| Text painted on a `<canvas>`, in a PDF viewer, or baked into images | No | There is no DOM text to read. |
| Hidden content, Moodle navigation, the reader's own controls | Deliberately not | Filtered out by the collector. |

### Engine per language

| Language | First choice | Fallback | If nothing works |
|---|---|---|---|
| Basque | itzune voice in the browser (WebGPU, else WebAssembly) | aHoTTS API | The language is not offered; the button says so. |
| Spanish, English | Piper voice in the browser | Browser Web Speech API, then aHoTTS API | The button says no voice is available. |
| French, Catalan, Galician, German, Italian, Portuguese | Browser Web Speech API | aHoTTS API when configured for that language | The button says no voice is available. |

Basque is deliberately never handed to a browser voice: browsers ship none, and
reading Basque with a Spanish voice is worse than not offering the button.

Installing the bridge in a SCORM package
----------------------------------------

Only needed for packages served from **another origin**. Packages hosted by this
Moodle site need nothing.

1. Copy `bridge/ahotts-scorm-bridge.js` into the package, next to its HTML files.
2. Reference it from **every HTML page** of the package, just before `</body>`:

   ```html
   <script src="ahotts-scorm-bridge.js"
           data-ahotts-parents="https://moodle.example.org"></script>
   ```

   `data-ahotts-parents` is the list of Moodle origins allowed to drive the
   bridge, separated by spaces or commas. Leaving it out lets any embedder ask
   the package for its text; set it whenever you can.
3. Repackage and republish the content on its own host.
4. In Moodle, tick *Enable the cross-origin SCORM bridge* and add that host to
   *Allowed SCORM origins*, one origin per line:

   ```
   https://scorm.example.org
   https://*.partner.example.org
   ```

   An entry is scheme, host and optional port, with no path. A single leading
   `*.` wildcard matches sub-domains only, never the bare domain.

Full details, including the message protocol, are in
[bridge/README.md](bridge/README.md).

### Articulate Rise / Storyline "Connected" content

Content published to Articulate's own hosting (Review 360, Articulate Connected,
`*.articulate.com`) is served from Articulate's origin, and you cannot add a
script to files you do not control. Such content will therefore **not** be read,
and the block will say so.

It becomes readable in exactly two situations:

* the bridge is loaded **inside** the Articulate-hosted content — that is, the
  package is published in a way that lets you include the script in its HTML; or
* the content is legitimately served from the **same origin** as Moodle, for
  example by exporting the package for LMS/web and uploading it to your own
  site, within the terms of your Articulate licence.

Rise 360 LMS packages exported with hosted content are a common case: the zip
only holds `indexAPI.html` and `goodbye.html`, and the course itself is framed
from `packages.articulateusercontent.com`. The block recognises such frames and
shows a specific notice (`status_externalhosted`); the rest of the page is still
read aloud.

Nothing else will do it. Proxying someone else's hosted content through your
domain to fake same-origin is **not** part of this plugin: it raises
authentication, CSP, licensing and content-integrity questions that need their
own security review, and it is deliberately left out.

Limitations
-----------

* No cross-origin content is readable without the cooperative bridge. By design.
* A cross-origin iframe **inside** a cross-origin package is not reached: the
  bridge reads its own document and its same-origin children only. Relaying
  between bridges is not implemented.
* Text drawn on a canvas, or living only in an image or scanned PDF, cannot be read.
* The frame must be allowed to run scripts and to talk to its parent. An iframe
  with a `sandbox` attribute needs at least `allow-scripts`; the package's host
  must also allow Moodle in its `frame-ancestors` CSP directive, or the content
  will not display at all.
* The bridge handshake adds a short delay (the configured timeout, 2.5 s by
  default) before playback starts on a page holding an unreachable frame.
* Reading follows the DOM order of the reading region, which is not always the
  visual order in a heavily positioned layout.

Development
-----------

Sources live in `amd/src/speech.js` (collection, bridge client, language and
engine selection), `amd/src/piper.js` (the Piper engine) and
`amd/src/pockettts.js` (the Pocket TTS engine, page side), and are compiled to
`amd/build/*.min.js`. The Pocket TTS workers in `js/pockettts/` are plain ES
modules served as they are, with no build step. In a full Moodle checkout, rebuild them with
Moodle's own tooling:

```bash
cd /path/to/moodle
npx grunt amd --root=blocks/ahotts_embhl
```

The compiled file is a named AMD module (`block_ahotts_embhl/speech`) produced by
Babel's `transform-modules-amd`, which is what grunt runs.

### Tests

PHP (Moodle PHPUnit, from a configured Moodle checkout):

```bash
vendor/bin/phpunit blocks/ahotts_embhl/tests/origins_test.php
vendor/bin/phpunit blocks/ahotts_embhl/tests/languages_test.php
vendor/bin/phpunit blocks/ahotts_embhl/tests/ahotts_embhl_test.php
```

JavaScript (jsdom, no Moodle needed):

```bash
cd blocks/ahotts_embhl/tests/js
npm install
npm test
```

Course language detection (no database needed):

```bash
php blocks/ahotts_embhl/cli/test_course_language.php
```

The JavaScript suite covers an ordinary Moodle page, a same-origin SCORM package,
nested iframes, dynamically added content, a cross-origin package with and
without a bridge, origin and source validation against forged messages, the
bridge's own message handling, playback with pause, resume and stop, the Piper
pipeline (phoneme mapping, WAV packaging, backend choice and failure fallback),
the four language modes with the language and voice menus, and that
`amd/build/*.min.js` are current builds of the sources.

License
-------

GNU GPL v3 or later. This plugin requires no ReadSpeaker licence; audio comes
from the voices running in the learner's browser, from the endpoints you
configure, and from the browser's own speech synthesis. The third-party runtime
and voices it can load are listed under *Self-hosting the runtime and the
voices*, with their own licences.
