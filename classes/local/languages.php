<?php
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
 * Languages, engines and voices offered by the Listen button.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ahotts_embhl\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the language and engine configuration handed to amd/src/speech.js.
 *
 * Every language with a Piper voice is spoken in the browser by that voice;
 * behind it come the browser's own Web Speech API and the aHoTTS API, when one
 * is configured. Basque is the exception: no browser ships a Basque voice, and
 * reading it with a Spanish one is worse than not offering the button.
 */
class languages {

    /** @var string[] Languages the Listen button knows how to offer. */
    const SUPPORTED = ['eu', 'es', 'en', 'fr', 'ca', 'gl', 'de', 'it', 'pt'];

    /** @var string[] Languages offered when the administrator has chosen none. */
    const DEFAULT_OFFERED = ['eu', 'es', 'en'];

    /** @var array Region used for a language when the setting does not name one. */
    const DEFAULT_REGIONS = [
        'eu' => 'eu-ES',
        'es' => 'es-ES',
        'en' => 'en-GB',
        'fr' => 'fr-FR',
        'ca' => 'ca-ES',
        'gl' => 'gl-ES',
        'de' => 'de-DE',
        'it' => 'it-IT',
        'pt' => 'pt-PT',
    ];

    /** @var array Default aHoTTS voice per language. */
    const DEFAULT_API_VOICES = [
        'eu' => 'antton',
        'es' => 'laura',
        'gl' => 'brais',
        'ca' => 'ona',
    ];

    /**
     * @var string Default phonemizer: an eSpeak NG build carrying every language.
     *
     * It has to be a full build. The widely used phonemizer npm package only
     * carries English, and with it the Basque voices produce no sound at all.
     */
    const DEFAULT_PHONEMIZER = 'https://cdn.jsdelivr.net/npm/espeak-ng@1.0.2/dist/espeak-ng.js';

    /** @var string The WebAssembly binary of DEFAULT_PHONEMIZER, fetched once and reused. */
    const DEFAULT_PHONEMIZER_WASM = 'https://cdn.jsdelivr.net/npm/espeak-ng@1.0.2/dist/espeak-ng.wasm';

    /**
     * @var array The Piper voices offered per language, and where they are published.
     *
     * Basque is spoken by the itzune voices; the others come from the Piper
     * project's own collection. Every one of them carries a single speaker, so
     * the engine never has to pick a voice from inside a model.
     */
    const PIPER_VOICES = [
        'eu' => [
            'maider' => 'https://huggingface.co/itzune/maider-tts/resolve/main/eu-maider-medium.onnx',
            'antton' => 'https://huggingface.co/itzune/antton-tts/resolve/main/eu-antton-medium.onnx',
        ],
        'es' => [
            'davefx' => 'https://huggingface.co/rhasspy/piper-voices/resolve/main/es/es_ES/davefx/medium/es_ES-davefx-medium.onnx',
            'claude' => 'https://huggingface.co/rhasspy/piper-voices/resolve/main/es/es_MX/claude/high/es_MX-claude-high.onnx',
        ],
        'en' => [
            'alba' => 'https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_GB/alba/medium/en_GB-alba-medium.onnx',
            'ryan' => 'https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/ryan/medium/en_US-ryan-medium.onnx',
        ],
    ];

    /** @var array The voice a language starts with when the administrator has chosen none. */
    const DEFAULT_PIPER_VOICES = [
        'eu' => 'antton',
        'es' => 'davefx',
        'en' => 'alba',
    ];

    /**
     * Reduce any language tag to its base code.
     *
     * @param string $value For example eu_ES, eu-ES or EU.
     * @return string For example eu.
     */
    public static function normalise(string $value): string {
        $value = strtolower(trim(str_replace('_', '-', $value)));
        $parts = explode('-', $value);
        return $parts[0];
    }

    /**
     * The BCP-47 tag the Web Speech API should be asked for.
     *
     * The tag configured for the site wins for its own language, so a site set
     * to Mexican Spanish keeps es-MX; other languages fall back to the region in
     * DEFAULT_REGIONS.
     *
     * @param string $code Base language code.
     * @param string $configured The full setting value, e.g. es_mx.
     * @return string
     */
    public static function bcp47(string $code, string $configured = ''): string {
        $code = self::normalise($code);
        $parts = explode('-', strtolower(str_replace('_', '-', trim($configured))));

        if (count($parts) > 1 && $parts[0] === $code && $parts[1] !== '') {
            return $code . '-' . strtoupper($parts[1]);
        }

        return self::DEFAULT_REGIONS[$code] ?? $code;
    }

    /**
     * Default aHoTTS voice for a language.
     *
     * @param string $code
     * @return string
     */
    public static function api_voice(string $code): string {
        $code = self::normalise($code);
        $configured = get_config('block_ahotts_embhl', 'voice_' . $code);
        if (!empty($configured)) {
            return (string) $configured;
        }
        return self::DEFAULT_API_VOICES[$code] ?? '';
    }

    /**
     * Languages the reader may be offered, in the administrator's order.
     *
     * @param string|null $raw Raw value of the chooserlangs setting.
     * @return string[]
     */
    public static function offered(?string $raw = null): array {
        if ($raw === null) {
            $raw = (string) get_config('block_ahotts_embhl', 'chooserlangs');
        }

        $codes = [];
        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $code = self::normalise($entry);
            if (in_array($code, self::SUPPORTED, true) && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes ?: self::DEFAULT_OFFERED;
    }

    /**
     * Resolve which language the page should be read in by default.
     *
     * 'content' and 'chooser' are settled in the browser, so they only need a
     * sensible starting point here.
     *
     * @param string $langmode fixed, page, content or chooser.
     * @param string $configured The plugin or block language setting.
     * @param string $current The Moodle interface language.
     * @param string[] $offered Languages that can actually be offered.
     * @return string
     */
    public static function resolve(string $langmode, string $configured, string $current, array $offered): string {
        if (!$offered) {
            return '';
        }

        if ($langmode === 'page') {
            $code = self::normalise($current);
            if (in_array($code, $offered, true)) {
                return $code;
            }
        }

        $code = self::normalise($configured);
        if (in_array($code, $offered, true)) {
            return $code;
        }

        return $offered[0];
    }

    /**
     * The Piper voices available in the browser, with their model URLs.
     *
     * @param string[]|null $codes Languages to list voices for; null lists them all.
     * @return array[] [{id, label, modelurl, language}]
     */
    public static function piper_voices(?array $codes = null): array {
        $wanted = $codes === null ? null : array_map([self::class, 'normalise'], $codes);
        $voices = [];

        foreach (self::PIPER_VOICES as $language => $published) {
            if ($wanted !== null && !in_array($language, $wanted, true)) {
                continue;
            }
            foreach ($published as $id => $default) {
                $url = trim((string) get_config('block_ahotts_embhl', 'pipermodel_' . $id));
                if ($url === '') {
                    $url = $default;
                }
                $voices[] = [
                    'id' => $id,
                    'label' => get_string('pipervoice_' . $id, 'block_ahotts_embhl'),
                    'modelurl' => $url,
                    'language' => $language,
                ];
            }
        }

        return $voices;
    }

    /**
     * The Piper voice each language starts with.
     *
     * @param string[] $codes
     * @return array Language code => voice id.
     */
    public static function default_piper_voices(array $codes): array {
        $defaults = [];

        foreach ($codes as $code) {
            $code = self::normalise($code);
            if (!isset(self::PIPER_VOICES[$code])) {
                continue;
            }
            $configured = (string) get_config('block_ahotts_embhl', 'pipervoice_' . $code);
            $defaults[$code] = isset(self::PIPER_VOICES[$code][$configured])
                ? $configured
                : (self::DEFAULT_PIPER_VOICES[$code] ?? array_key_first(self::PIPER_VOICES[$code]));
        }

        return $defaults;
    }

    /**
     * Whether the in-browser neural voices are switched on and usable.
     *
     * @return bool
     */
    public static function piper_enabled(): bool {
        if (!get_config('block_ahotts_embhl', 'piperenabled')) {
            return false;
        }
        $required = ['piperort', 'piperphonemizer'];
        foreach ($required as $name) {
            if (trim((string) get_config('block_ahotts_embhl', $name)) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * The engines that can speak a language, best first.
     *
     * @param string $code Base language code.
     * @param bool $piperenabled
     * @return array[]
     */
    public static function engines_for(string $code, bool $piperenabled): array {
        $code = self::normalise($code);
        $engines = [];

        // The local neural voice comes first for every language that has one.
        if ($piperenabled && !empty(self::PIPER_VOICES[$code])) {
            $engines[] = ['kind' => 'piper'];
        }

        // The browser's own voice sits behind it, for when the model cannot be
        // downloaded.
        if ($code !== 'eu') {
            $engines[] = ['kind' => 'webspeech'];
        }

        $apiurl = api::base_url($code);
        if ($apiurl !== '') {
            $engines[] = [
                'kind' => 'api',
                'url' => $apiurl,
                'language' => $code,
                'voice' => self::api_voice($code),
            ];
        }

        // Basque deliberately gets no Web Speech fallback: browsers ship no
        // Basque voice, and reading it with a Spanish one is worse than not
        // offering the button at all.

        return $engines;
    }

    /**
     * Build the whole language list handed to the front end.
     *
     * @param string[] $codes
     * @param string $configured The plugin or block language setting.
     * @param bool $piperenabled
     * @return array[]
     */
    public static function build(array $codes, string $configured, bool $piperenabled): array {
        $names = get_string_manager()->get_list_of_languages();
        $out = [];

        foreach ($codes as $code) {
            $code = self::normalise($code);
            $engines = self::engines_for($code, $piperenabled);
            if (!$engines) {
                continue;
            }
            $out[] = [
                'code' => $code,
                'label' => $names[$code] ?? strtoupper($code),
                'bcp47' => self::bcp47($code, $configured),
                'engines' => $engines,
            ];
        }

        return $out;
    }
}
