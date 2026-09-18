aHoTTS SCORM bridge
===================

`ahotts-scorm-bridge.js` makes a SCORM package readable by the Moodle block
`block_ahotts_embhl` when the package is served from a **different origin** than
Moodle.

Why it is needed
----------------

The Same-Origin Policy stops a page from touching the DOM of an iframe loaded
from another origin. There is no legitimate way around it, and this plugin does
not try: no CORS tricks, no `document.domain`, no proxy. The only supported route
is cooperation — the package runs this script and answers a small, versioned,
origin-checked message protocol.

If your SCORM package is uploaded to Moodle, it is already same-origin and this
file is unnecessary.

Installing
----------

1. Copy `ahotts-scorm-bridge.js` into the package.
2. Add it to **every HTML page** of the package, just before `</body>`:

   ```html
   <script src="ahotts-scorm-bridge.js"
           data-ahotts-parents="https://moodle.example.org"></script>
   ```

   For a package with pages in sub-directories, adjust the relative path, or
   serve the file from a fixed absolute path on the package's own host.

3. Repackage and publish on its host.
4. In Moodle, enable *Enable the cross-origin SCORM bridge* and add the package's
   origin to *Allowed SCORM origins*.

The script does nothing when the document is not framed, so a package that also
opens standalone is unaffected.

### Configuration

Either through attributes on the script tag:

| Attribute | Meaning |
|---|---|
| `data-ahotts-parents` | Origins allowed to drive the bridge, separated by spaces or commas. Omitted: any embedder is accepted. |
| `data-ahotts-debug` | `1` logs handshakes and rejections to the console. |

or through a global set **before** the script loads:

```html
<script>
window.AHOTTS_BRIDGE_CONFIG = {
    parentOrigins: ['https://moodle.example.org'],
    debug: false
};
</script>
<script src="ahotts-scorm-bridge.js"></script>
```

Setting `parentOrigins` is strongly recommended: without it, any site that frames
your package can ask it for its text.

What the bridge does
--------------------

* Extracts readable text from its own document and from **same-origin iframes
  nested inside the package**, skipping hidden elements, `aria-hidden` content,
  `[hidden]`, scripts and styles, anything marked `.ahotts-skip` or
  `[data-ahotts-skip]`, and duplicated text.
* Splits text into ~160-character chunks on sentence boundaries, and gives each
  one an opaque id.
* Underlines the chunk Moodle is speaking and scrolls it into view, moving the
  underline as playback advances.
* Watches the document with a `MutationObserver`, plus `hashchange` and
  `popstate`, and tells Moodle when the page changed so stale chunk ids are
  dropped.

Audio is always synthesised **by Moodle**; the bridge never plays anything and
never loads a network resource.

Message protocol
----------------

Every message carries `{protocol: 'ahotts-scorm-bridge', v: 1}`. Anything without
that envelope is ignored.

Moodle → bridge:

| type | Payload | Meaning |
|---|---|---|
| `hello` | `session`, `nonce` | Discovery and handshake. |
| `collect` | `session`, `requestId` | Give me your readable chunks. |
| `highlight` | `session`, `requestId`, `chunkId` | Underline this chunk. |
| `unhighlight` | `session`, `requestId`, `chunkId?` | Clear one highlight, or all of them. |
| `bye` | `session` | Playback finished; forget me. |

Bridge → Moodle:

| type | Payload | Meaning |
|---|---|---|
| `ready` | — | I have loaded (in case the handshake arrived too early). |
| `hello-ack` | `session`, `nonce`, `bridge`, `capabilities` | Handshake accepted. |
| `chunks` | `session`, `requestId`, `chunks: [{id, text}]`, `truncated` | The readable text. |
| `ack` | `session`, `requestId`, `ok` | Result of a highlight request. |
| `page-changed` | `session` | The document changed; previous chunk ids are stale. |
| `error` | `session`, `requestId`, `code` | The request could not be served. |

Security model
--------------

On the bridge side:

* only `window.parent` may drive the bridge; a message from any other window is
  dropped, which is what stops a sibling frame from impersonating Moodle;
* `event.origin` must be on `parentOrigins` when that list is configured;
* after the handshake, a request must come from the bound window **and** the
  bound origin **and** carry the agreed session id;
* replies always go to the validated origin. `'*'` is used for one thing only:
  the `ready` announcement when no parent origin is configured, and that message
  contains nothing but the protocol name and version;
* chunk ids received from Moodle are matched against `/^c[0-9]{1,9}$/` before
  use; nothing that arrives is ever inserted as HTML, evaluated, or used to build
  a selector. Text leaves the bridge through `textContent` only.

On the Moodle side (`amd/src/speech.js`):

* a frame is only contacted when the origin of its `src` is on the administrator's
  allow list; a frame with no readable `src` (`about:blank`, `srcdoc`) gets the
  discovery message at `'*'` and is then held to the same allow list when it
  answers;
* an answer is accepted only when `event.origin` is allow-listed **and**
  `event.source` is exactly the `contentWindow` registered for that frame;
* the session id and the per-request id must match what was sent; unsolicited
  answers are dropped;
* chunk ids and chunk texts are validated before use, and text is rendered with
  `textContent`.

Limitations
-----------

* A **cross-origin** iframe nested inside the package is not read: the bridge
  handles its own document and its same-origin children. Bridge-to-bridge
  relaying is not implemented.
* Text drawn on a `<canvas>` has no DOM representation and cannot be extracted.
* The package must be allowed to run scripts in the frame (`allow-scripts` if the
  iframe is sandboxed).
* You can only install this in content you control. Content hosted by a third
  party, such as Articulate Review 360, cannot be modified, so it stays
  unreadable — see the plugin README.

License
-------

GNU GPL v3 or later, like the rest of the plugin.
