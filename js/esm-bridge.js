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
 * Loads an ES module on behalf of an AMD module.
 *
 * Moodle's AMD build rewrites a native dynamic import into a RequireJS call, so
 * an AMD module cannot import a plain ES module such as the eSpeak NG
 * phonemizer. This file is added to the page as
 *
 *     <script type="module" src=".../js/esm-bridge.js?src=<url>&name=<global>">
 *
 * imports the requested module and publishes its namespace on window under the
 * given name, then fires "<name>:ready" (or "<name>:error"). It stays an
 * external script file, so no inline script and no eval() is involved and the
 * plugin keeps working under a strict Content-Security-Policy.
 *
 * It imports what the caller asks for, which is why the name is restricted to a
 * plain identifier and the source to an absolute http(s) URL.
 *
 * @module     block_ahotts_embhl/esm-bridge
 * @copyright  2026 Ahotts
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const params = new URL(import.meta.url).searchParams;
const name = params.get('name') || '';
const src = params.get('src') || '';

const announce = (type, detail) => {
    window.dispatchEvent(new CustomEvent(name + ':' + type, {detail: detail}));
};

if (!/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(name)) {
    throw new Error('esm-bridge: invalid module name');
}

if (!/^https?:\/\//.test(src)) {
    announce('error', 'esm-bridge: only absolute http(s) module URLs are accepted');
} else {
    try {
        const namespace = await import(src);
        window[name] = namespace;
        announce('ready', namespace);
    } catch (error) {
        announce('error', String(error));
    }
}
