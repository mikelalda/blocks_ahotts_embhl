block_ahotts_embhl
==================

Moodle block that adds a Listen button to the page and reads its content aloud,
chunk by chunk, with the text currently being spoken underlined.

* **Basque (eu)** is spoken by the **itzune voices running inside the browser**:
  Piper models in ONNX format, executed by ONNX Runtime Web on WebGPU, or on
  WebAssembly where WebGPU is not available. No text leaves the learner's
  machine. If the voice cannot be started, the block falls back to the **aHoTTS
  API** configured in the settings.
* **Every other language** is spoken by the browser's own **Web Speech API**,
  with the aHoTTS API behind it when one is configured for that language.

The next chunk is synthesised while the current one plays, so playback starts
almost immediately.

Which language is read can be fixed by the administrator, taken from the Moodle
interface, taken from the `lang` attribute of the content, or **chosen by the
reader** in the block itself. Readers can also pick which Basque voice they
prefer.

Besides the page itself, the block reads **content inside iframes**: SCORM
packages, H5P activities and anything else embedded in the reading region,
including iframes nested inside iframes.

Requirements
------------

Moodle 3.0 or later. A modern browser. For Basque, a reachable aHoTTS endpoint.

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
| Default Basque voice | `antton` or `maider`. |
| Let the reader choose the Basque voice | Adds a voice menu to the block. |
| Execution backend | `auto`, `webgpu` or `wasm`. |
| Antton / Maider model URL | Where the `.onnx` voice is fetched from. |
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
Piper/VITS models exported to ONNX and published under Apache-2.0.

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
| `phonemizer.js` | [`phonemizer`](https://www.npmjs.com/package/phonemizer) (wraps eSpeak NG) | Apache-2.0, eSpeak NG is GPL-3.0 |
| `eu-antton-medium.onnx` and its `.json` | `itzune/antton-tts` | Apache-2.0 |
| `eu-maider-medium.onnx` and its `.json` | `itzune/maider-tts` | Apache-2.0 |

The voice configuration is read from the model URL with `.json` appended, which
is the Piper convention and matches how the files are published. If you serve
them from another domain, that domain has to allow cross-origin reads.

The block loads the runtime with an ordinary `<script>` tag and the phonemizer
through `js/esm-bridge.js`, a small ES module shipped with the plugin. Neither
uses `eval()` nor an inline script, so a strict CSP only has to allow the
origins the files are served from.

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
| Spanish, English, French, Catalan, Galician, German, Italian, Portuguese | Browser Web Speech API | aHoTTS API when configured for that language | The button says no voice is available. |

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
* Text drawn on a canvas, or living only in an image or PDF, cannot be read.
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
engine selection) and `amd/src/piper.js` (the in-browser Basque engine), and are
compiled to `amd/build/*.min.js`. In a full Moodle checkout, rebuild them with
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
