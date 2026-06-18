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
 * ReadSpeakers webReader for Moodle block.
 *
 * @package    block_ahotts_embhl
 * @copyright  2016 ReadSpeaker <info@readspeaker.com>
 * @author     Richard Risholm
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

#[AllowDynamicProperties]

class block_ahotts_embhl extends block_base {

    public function init() {
        // Set default text on the Listen button.
        $this->title = get_string('pluginname', 'block_ahotts_embhl');

        $this->plugin_config_language = get_config('block_ahotts_embhl', 'lang');
        $this->plugin_config_readid = get_config('block_ahotts_embhl', 'readid');
        $this->plugin_custom_params = get_config('block_ahotts_embhl', 'customparams');

        $this->plugin_disable_in_em = get_config('block_ahotts_embhl', 'disableinem');
        $this->plugin_custom_showincontent = get_config('block_ahotts_embhl', 'showincontent');

        $this->plugin_mode = get_config('block_ahotts_embhl', 'mode');
    }

    public function specialization() {
        if (isset($this->config)) {
            if (!empty($this->config->lang)) {
                $this->plugin_config_language = $this->config->lang;
            }
            if (!empty($this->config->customparams)) {
                $this->plugin_custom_params = $this->config->customparams;
            }
            if (!empty($this->config->mode)) {
                $this->plugin_mode = $this->config->mode;
            }
        }
    }

    public function get_content() {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass;
        $this->content->text = '';

        // Don't show the Listen button while editing the page, if configured so.
        $edit_mode = $this->page->user_is_editing();
        if ($this->plugin_disable_in_em === '1' && $edit_mode) {
            return $this->content;
        }

        // Set default text on the Listen button.
        $listen_text = get_string('listentext', 'block_ahotts_embhl');
        $listen_text_title = get_string('listen_titletext', 'block_ahotts_embhl');

        // Resolve the language bucket. Basque is synthesised by our own aHoTTS API;
        // Spanish and English are read in the browser with the Web Speech API.
        $bucket = \block_ahotts_embhl\local\api::language_bucket($this->plugin_config_language);
        $apiurl = \block_ahotts_embhl\local\api::base_url($this->plugin_config_language);

        // Basque relies entirely on the API: with no endpoint configured there is nothing to offer.
        if ($bucket === 'eu' && $apiurl === '') {
            return $this->content;
        }

        // HTML code for the Listen button. The button is driven by JavaScript, so the
        // link has no navigation target.
        $listen_button_code = implode(PHP_EOL, [
            '<div id="readspeaker_button1" class="rs_skip rsbtn rs_preserve rscompact">',
            '   <a class="rsbtn_play" title="' . $listen_text_title . '" href="#" role="button">',
            '       <span class="rsbtn_left rsimg rspart">',
            '           <span class="rsbtn_text">',
            '               <span>'. $listen_text . '</span>',
            '           </span>',
            '       </span>',
            '       <span class="rsbtn_right rsimg rsplay rspart"></span>',
            '   </a>',
            '</div>'
        ]);

        $this->content->text .= $listen_button_code;

        // Build the configuration for the front-end TTS module.
        $labels = [
            'listen' => $listen_text,
            'loading' => get_string('loadingtext', 'block_ahotts_embhl'),
            'pause' => get_string('pausetext', 'block_ahotts_embhl'),
            'resume' => get_string('resumetext', 'block_ahotts_embhl'),
            'stop' => get_string('stoptext', 'block_ahotts_embhl'),
        ];

        $jsconfig = [
            'readid' => $this->plugin_config_readid,
            'maxlen' => (int) (get_config('block_ahotts_embhl', 'maxtextlength') ?: 3000),
            'labels' => $labels,
        ];

        if ($bucket === 'eu') {
            // Basque: synthesise through the aHoTTS API and play the returned audio.
            $jsconfig['prefer'] = 'api';
            $jsconfig['api'] = [
                'url' => $apiurl,
                'language' => 'eu',
                'voice' => $this->api_voice('eu'),
            ];
        } else {
            // Spanish / English: read in the browser. When the language is also
            // supported by the API, expose it as a fallback for unsupported browsers.
            $jsconfig['prefer'] = 'webspeech';
            $jsconfig['webspeech'] = [
                'lang' => $this->to_bcp47($this->plugin_config_language),
            ];
            $apilang = substr($this->plugin_config_language, 0, 2);
            if ($apiurl !== '' && in_array($apilang, ['es', 'gl', 'ca'], true)) {
                $jsconfig['api'] = [
                    'url' => $apiurl,
                    'language' => $apilang,
                    'voice' => $this->api_voice($apilang),
                ];
            }
        }

        $this->page->requires->js_call_amd('block_ahotts_embhl/speech', 'init', [$jsconfig]);

        return $this->content;
    }

    /**
     * Default aHoTTS API voice for a given language code.
     *
     * @param string $language Two-letter language code (eu, es, gl, ca).
     * @return string
     */
    private function api_voice($language) {
        $configured = get_config('block_ahotts_embhl', 'voice_' . $language);
        if (!empty($configured)) {
            return $configured;
        }
        $defaults = [
            'eu' => 'antton',
            'es' => 'laura',
            'gl' => 'brais',
            'ca' => 'ona',
        ];
        return $defaults[$language] ?? '';
    }

    /**
     * Convert a ReadSpeaker/Moodle language code (xx_yy) to a BCP-47 tag (xx-YY).
     *
     * @param string $lang
     * @return string
     */
    private function to_bcp47($lang) {
        if (empty($lang)) {
            return '';
        }
        $parts = explode('_', $lang);
        if (count($parts) < 2) {
            return $parts[0];
        }
        return $parts[0] . '-' . strtoupper($parts[1]);
    }

    public function instance_allow_config() {
        return true;
    }

    public function has_config() {
        return true;
    }

    public function instance_allow_multiple() {
        return false;
    }

    public function applicable_formats() {
        return array('all' => true, 'tag' => false);
    }

    /**
     * Function replaces diacritic letters in the string with
     * its regular analogues and returns "clear" string.
     *
     * @param string $str
     * @return string
     */
    private function clear_letters($str)
    {
        $a = ['À', 'Á', 'Â', 'Ã', 'Ä', 'Å', 'Æ', 'Ç', 'È', 'É', 'Ê', 'Ë', 'Ì', 'Í', 'Î', 'Ï', 'Ð', 'Ñ', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ø', 'Ù', 'Ú', 'Û', 'Ü', 'Ý', 'ß', 'à', 'á', 'â', 'ã', 'ä', 'å', 'æ', 'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ñ', 'ò', 'ó', 'ô', 'õ', 'ö', 'ø', 'ù', 'ú', 'û', 'ü', 'ý', 'ÿ', 'Ā', 'ā', 'Ă', 'ă', 'Ą', 'ą', 'Ć', 'ć', 'Ĉ', 'ĉ', 'Ċ', 'ċ', 'Č', 'č', 'Ď', 'ď', 'Đ', 'đ', 'Ē', 'ē', 'Ĕ', 'ĕ', 'Ė', 'ė', 'Ę', 'ę', 'Ě', 'ě', 'Ĝ', 'ĝ', 'Ğ', 'ğ', 'Ġ', 'ġ', 'Ģ', 'ģ', 'Ĥ', 'ĥ', 'Ħ', 'ħ', 'Ĩ', 'ĩ', 'Ī', 'ī', 'Ĭ', 'ĭ', 'Į', 'į', 'İ', 'ı', 'Ĳ', 'ĳ', 'Ĵ', 'ĵ', 'Ķ', 'ķ', 'Ĺ', 'ĺ', 'Ļ', 'ļ', 'Ľ', 'ľ', 'Ŀ', 'ŀ', 'Ł', 'ł', 'Ń', 'ń', 'Ņ', 'ņ', 'Ň', 'ň', 'ŉ', 'Ō', 'ō', 'Ŏ', 'ŏ', 'Ő', 'ő', 'Œ', 'œ', 'Ŕ', 'ŕ', 'Ŗ', 'ŗ', 'Ř', 'ř', 'Ś', 'ś', 'Ŝ', 'ŝ', 'Ş', 'ş', 'Š', 'š', 'Ţ', 'ţ', 'Ť', 'ť', 'Ŧ', 'ŧ', 'Ũ', 'ũ', 'Ū', 'ū', 'Ŭ', 'ŭ', 'Ů', 'ů', 'Ű', 'ű', 'Ų', 'ų', 'Ŵ', 'ŵ', 'Ŷ', 'ŷ', 'Ÿ', 'Ź', 'ź', 'Ż', 'ż', 'Ž', 'ž', 'ſ', 'ƒ', 'Ơ', 'ơ', 'Ư', 'ư', 'Ǎ', 'ǎ', 'Ǐ', 'ǐ', 'Ǒ', 'ǒ', 'Ǔ', 'ǔ', 'Ǖ', 'ǖ', 'Ǘ', 'ǘ', 'Ǚ', 'ǚ', 'Ǜ', 'ǜ', 'Ǻ', 'ǻ', 'Ǽ', 'ǽ', 'Ǿ', 'ǿ'];
        $b = ['A', 'A', 'A', 'A', 'A', 'A', 'AE', 'C', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I', 'D', 'N', 'O', 'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'Y', 's', 'a', 'a', 'a', 'a', 'a', 'a', 'ae', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'n', 'o', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'y', 'A', 'a', 'A', 'a', 'A', 'a', 'C', 'c', 'C', 'c', 'C', 'c', 'C', 'c', 'D', 'd', 'D', 'd', 'E', 'e', 'E', 'e', 'E', 'e', 'E', 'e', 'E', 'e', 'G', 'g', 'G', 'g', 'G', 'g', 'G', 'g', 'H', 'h', 'H', 'h', 'I', 'i', 'I', 'i', 'I', 'i', 'I', 'i', 'I', 'i', 'IJ', 'ij', 'J', 'j', 'K', 'k', 'L', 'l', 'L', 'l', 'L', 'l', 'L', 'l', 'l', 'l', 'N', 'n', 'N', 'n', 'N', 'n', 'n', 'O', 'o', 'O', 'o', 'O', 'o', 'OE', 'oe', 'R', 'r', 'R', 'r', 'R', 'r', 'S', 's', 'S', 's', 'S', 's', 'S', 's', 'T', 't', 'T', 't', 'T', 't', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'W', 'w', 'Y', 'y', 'Y', 'Z', 'z', 'Z', 'z', 'Z', 'z', 's', 'f', 'O', 'o', 'U', 'u', 'A', 'a', 'I', 'i', 'O', 'o', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'A', 'a', 'AE', 'ae', 'O', 'o'];
        return str_replace($a, $b, $str);
    }

    /**
     * Function for converting a ReadSpeaker language code to Moodle language,
     * provide Moodle language code (ISO 639-1), returns ReadSpeaker lang-code (xx_yy).
     *
     * @param string $lang
     * @return string
     */
    private function moodle_to_rslang($lang) {
        // Define a list of supported ISO 639-1 to ReadSpeaker lang-codes
        $langlist = [
            'af' => 'af_za',
            'ar' => 'ar_ar',
            'bg' => 'bg_bg',
            'en' => 'en_us',
            'ca' => 'ca_es',
            'ca_valencia' => 'vl_es',
            'cz' => 'cs_cz',
            'cy' => 'cy_cy',
            'de' => 'de_de',
            'da' => 'da_dk',
            'el' => 'el_gr',
            'es' => 'es_es',
            'et' => 'et_ee',
            'eu' => 'eu_es',
            'fi' => 'fi_fi',
            'fr' => 'fr_fr',
            'fo' => 'fo_fo',
            'fy' => 'fy_nl',
            'gl' => 'gl_es',
            'he' => 'he_il',
            'hi' => 'hi_in',
            'hr' => 'hr_hr',
            'it' => 'it_it',
            'is' => 'is_is',
            'ja' => 'ja_jp',
            'ko' => 'ko_kr',
            'lt' => 'lt_lt',
            'nl' => 'nl_nl',
            'nb' => 'no_nb',
            'nd' => 'nr_za',
            'nr' => 'nr_za',
            'nn' => 'no_nn',
            'pl' => 'pl_pl',
            'pt' => 'pt_pt',
            'ro' => 'ro_ro',
            'ru' => 'ru_ru',
            'ss' => 'ss_za',
            'sv' => 'sv_se',
            'tn' => 'tn_za',
            'tr' => 'tr_tr',
            'ts' => 'ts_za',
            'uk' => 'uk_ua',
            've' => 've_za',
            'zh' => 'zh_cn',
            'zh_cn' => 'zh_cn',
            'yue' => 'zh_hk',
            'zh_tw' => 'zh_tw',
            'nan' => 'zh_tw',
            'xh' => 'xh_za',
            'zu' => 'zu_za'
        ];

        // Check if language map exists and return Moodle language code.
        if (isset($langlist[$lang])) {
            return $langlist[$lang];
        }
        // If there is no language found for the entire code, only look at the two first characters in case it is a shortcode.
        if (isset($langlist[substr($lang, 0, 2)])) {
            return $langlist[substr($lang, 0, 2)];
        }

        // If not found, return the default English value.
        return $langlist['en'];
    }

    /**
     * Function for checking if theme has supported improved reading rules, curently only snap and boost.
     * Uses pre-set list of themes that have improved reading. Returns true correct and false otherwise.
     *
     * @param string $theme
     * @return boolean
     */
    private function improved_reading_theme($theme) {
        $theme_list = [
            'snap',
            'boost'
        ];
        return in_array($theme, $theme_list);
    }

    /**
     * Function to create the statistics format for webReader.
     *
     * @return string $final_format
     */
    private function statistics_format() {

        global $PAGE;

        // Retrieve all breadcrumb items.
        $breadcrumbs = $PAGE->navbar->get_items();

        // Initialize an array to store breadcrumb text.
        $breadcrumb_texts = [];
        $context = $PAGE->context;
        
        // Check if we are in a course context and the course ID is not 1 (site context).
        if (($context->contextlevel == CONTEXT_COURSE || $context->contextlevel == CONTEXT_MODULE) && isset($COURSE) && $COURSE->id != 1) {
            // Start from the third element (index 2) if in a course context.
            $start_index = 2;
        } else {
            // Otherwise, start from the beginning.
            $start_index = 0;
        }

        // Loop through each breadcrumb item, starting at the appropriate index.
        foreach ($breadcrumbs as $index => $breadcrumb) {
            // Skip the first two elements if in a course context.
            if ($index < $start_index) {
                continue;
            }

            // Get the text content of the breadcrumb.
            $text = $breadcrumb->get_content();

            // Add the text to the breadcrumb array.
            $breadcrumb_texts[] = $text;
        }

        // Join all breadcrumb texts with a separator.
        $breadcrumb_string = implode('/', $breadcrumb_texts);
        $decoded_text = html_entity_decode($breadcrumb_string, ENT_QUOTES | ENT_HTML5);

        $stats_name = preg_replace('/\s+/', '_', $decoded_text);

        $host = $_SERVER['HTTP_HOST'];
        $hostParts = explode('.', $host);

        // Check if there are more than two parts (indicating a subdomain exists).
        if (count($hostParts) > 2) {
            $subdomain = $hostParts[0];
        } else {
            $subdomain = null;
        }

        $final_format = $subdomain. '/' . $stats_name;

        return $final_format;
    }
}