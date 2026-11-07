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

/**
 * The aHoTTS Listen button block.
 *
 * @package    block_ahotts_embhl
 * @copyright  2016 ReadSpeaker <info@readspeaker.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_ahotts_embhl extends block_base {
    /** @var string Reading language set by the administrator or on this instance. */
    protected $configlanguage = '';

    /** @var string Id of the page element whose text is read. */
    protected $configreadid = '';

    /** @var string Whether the Listen button is hidden while editing ('1') or not. */
    protected $disableinem = '';

    /**
     * Set the block title and the site-wide defaults.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_ahotts_embhl');

        $this->configlanguage = (string) get_config('block_ahotts_embhl', 'lang');
        $this->configreadid = (string) get_config('block_ahotts_embhl', 'readid');
        $this->disableinem = (string) get_config('block_ahotts_embhl', 'disableinem');
    }

    /**
     * Apply the settings of this block instance over the site-wide defaults.
     */
    public function specialization() {
        if (isset($this->config) && !empty($this->config->lang)) {
            $this->configlanguage = $this->config->lang;
        }
    }

    /**
     * Build the Listen button and hand its configuration to the front-end module.
     *
     * @return stdClass The block content.
     */
    public function get_content() {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = $this->usage_link();

        // Don't show the Listen button while editing the page, if configured so.
        $editmode = $this->page->user_is_editing();
        if ($this->disableinem === '1' && $editmode) {
            return $this->content;
        }

        // Set default text on the Listen button.
        $listentext = get_string('listentext', 'block_ahotts_embhl');
        $listentexttitle = get_string('listen_titletext', 'block_ahotts_embhl');

        // Work out which languages the Listen button offers, and with which
        // engine. Basque is spoken by the itzune Piper voices in the browser and
        // by the aHoTTS API behind them; every other language is spoken by the
        // browser's own Web Speech API.
        $langmode = get_config('block_ahotts_embhl', 'langmode') ?: 'fixed';
        $configuredlang = $this->configlanguage;
        $offered = \block_ahotts_embhl\local\languages::offered();
        $piperenabled = \block_ahotts_embhl\local\languages::piper_enabled();
        $pocketttsenabled = \block_ahotts_embhl\local\languages::pockettts_enabled();

        $defaultlang = \block_ahotts_embhl\local\languages::resolve(
            $langmode,
            $configuredlang,
            current_language(),
            $offered
        );

        // Only the modes that let the reading language change at runtime need
        // more than the resolved one.
        $codes = in_array($langmode, ['chooser', 'content'], true) ? $offered : [$defaultlang];
        $languages = \block_ahotts_embhl\local\languages::build(
            $codes,
            $configuredlang,
            $piperenabled,
            $pocketttsenabled
        );

        // No language has an engine that could speak it: offer nothing at all.
        if (!$languages) {
            return $this->content;
        }

        // Build the configuration for the front-end TTS module.
        $labels = [
            'listen' => $listentext,
            'loading' => get_string('loadingtext', 'block_ahotts_embhl'),
            'pause' => get_string('pausetext', 'block_ahotts_embhl'),
            'resume' => get_string('resumetext', 'block_ahotts_embhl'),
            'stop' => get_string('stoptext', 'block_ahotts_embhl'),
            'language' => get_string('languagelabel', 'block_ahotts_embhl'),
            'voice' => get_string('voicelabel', 'block_ahotts_embhl'),
            'rate' => get_string('ratelabel', 'block_ahotts_embhl'),
            'download' => get_string('downloadaudio', 'block_ahotts_embhl'),
            'downloadpdffile' => get_string('downloadpdffile', 'block_ahotts_embhl'),
            'saveaudio' => get_string('saveaudio', 'block_ahotts_embhl'),
        ];

        $jsconfig = [
            'readid' => $this->configreadid,
            'maxlen' => (int) (get_config('block_ahotts_embhl', 'maxtextlength') ?: 3000),
            'labels' => $labels,
            'langmode' => $langmode,
            'lang' => $defaultlang,
            'languages' => $languages,
            // Reading speed: the menu, and where it starts.
            'rate' => \block_ahotts_embhl\local\languages::rate_config(),
            // PDFs published inside the SCORM package of this activity, best first.
            'packagepdfs' => \block_ahotts_embhl\local\package_pdf::for_page($this->page),
            // Web Worker that encodes the audio export to MP3.
            'exporter' => self::exporter_urls(),
            // Where audio exports report how they went, for the server log.
            'logurl' => (new moodle_url('/blocks/ahotts_embhl/clientlog.php'))->out(false),
            'sesskey' => sesskey(),
            'cmid' => $this->page->cm ? (int) $this->page->cm->id : 0,
            'courseid' => (int) $this->page->course->id,
            // On a SCORM package or a book, the audio download reads the whole activity.
            'texturl' => \block_ahotts_embhl\local\activity_text::supports($this->page->cm)
                ? \block_ahotts_embhl\local\activity_text::url($this->page->cm)->out(false) : '',
        ];

        if ($piperenabled) {
            // Everything the browser needs to run a voice locally.
            $jsconfig['piper'] = [
                'defaults' => \block_ahotts_embhl\local\languages::default_piper_voices($codes),
                'chooser' => (bool) get_config('block_ahotts_embhl', 'pipervoicechooser'),
                'voices' => \block_ahotts_embhl\local\languages::piper_voices($codes),
                'backend' => get_config('block_ahotts_embhl', 'piperbackend') ?: 'auto',
                'orturl' => trim((string) get_config('block_ahotts_embhl', 'piperort')),
                'wasmpath' => trim((string) get_config('block_ahotts_embhl', 'piperwasmpath')),
                'phonemizerurl' => trim((string) get_config('block_ahotts_embhl', 'piperphonemizer')),
                'phonemizerwasm' => trim((string) get_config('block_ahotts_embhl', 'piperphonemizerwasm')),
                'loaderurl' => (new moodle_url('/blocks/ahotts_embhl/js/esm-bridge.js'))->out(false),
            ];
        }

        if ($pocketttsenabled) {
            // The neural voices: the models come from the configured folder,
            // the two workers that run them from this plugin.
            $jsconfig['pockettts'] = [
                'defaults' => \block_ahotts_embhl\local\languages::default_pockettts_voices($codes),
                'chooser' => (bool) get_config('block_ahotts_embhl', 'pipervoicechooser'),
                'voices' => \block_ahotts_embhl\local\languages::pockettts_voices($codes),
                'orturl' => trim((string) get_config('block_ahotts_embhl', 'pocketttsort')),
                'wasmpath' => trim((string) get_config('block_ahotts_embhl', 'pocketttswasmpath')),
                'generatorurl' => (new moodle_url('/blocks/ahotts_embhl/js/pockettts/generator.js'))->out(false),
                'decoderurl' => (new moodle_url('/blocks/ahotts_embhl/js/pockettts/decoder.js'))->out(false),
            ];
        }

        // Cross-origin SCORM packages can only be read when they ship the
        // cooperative bridge and their origin is on the administrator's list.
        $jsconfig['bridge'] = [
            'enabled' => \block_ahotts_embhl\local\origins::bridge_enabled(),
            'origins' => \block_ahotts_embhl\local\origins::configured(),
            'timeout' => (int) (get_config('block_ahotts_embhl', 'scormtimeout') ?: 2500),
        ];

        // Messages announced through the block's aria-live status line.
        $jsconfig['status'] = [
            'externalhosted' => get_string('status_externalhosted', 'block_ahotts_embhl'),
            'externalnobridge' => get_string('status_externalnobridge', 'block_ahotts_embhl'),
            'nocontent' => get_string('status_nocontent', 'block_ahotts_embhl'),
            'preparingvoice' => get_string('status_preparingvoice', 'block_ahotts_embhl'),
            'preparingvoiceprogress' => get_string('status_preparingvoiceprogress', 'block_ahotts_embhl'),
            'voicefallback' => get_string('status_voicefallback', 'block_ahotts_embhl'),
            'downloadunavailable' => get_string('status_downloadunavailable', 'block_ahotts_embhl'),
            'downloadprogress' => get_string('status_downloadprogress', 'block_ahotts_embhl'),
            'downloadready' => get_string('status_downloadready', 'block_ahotts_embhl'),
            'downloaderror' => get_string('status_downloaderror', 'block_ahotts_embhl'),
            'downloadcancelled' => get_string('status_downloadcancelled', 'block_ahotts_embhl'),
            'readingactivity' => get_string('status_readingactivity', 'block_ahotts_embhl'),
            'pdfnotfound' => get_string('status_pdfnotfound', 'block_ahotts_embhl'),
            'pdferror' => get_string('status_pdferror', 'block_ahotts_embhl'),
            'pdfdownloadstarted' => get_string('status_pdfdownloadstarted', 'block_ahotts_embhl'),
            'pdfgenerating' => get_string('status_pdfgenerating', 'block_ahotts_embhl'),
            'enginesunavailable' => get_string('status_enginesunavailable', 'block_ahotts_embhl'),
        ];

        // HTML code for the Listen button. The button is driven by JavaScript, so the
        // link has no navigation target. Its configuration travels in a data attribute:
        // it is too large for the arguments of js_call_amd().
        $this->content->text .= implode(PHP_EOL, [
            '<div id="readspeaker_button1" class="rs_skip rsbtn rs_preserve rscompact"'
                . ' data-config="' . s(json_encode($jsconfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '">',
            '   <a class="rsbtn_play" title="' . s($listentexttitle) . '" href="#" role="button">',
            '       <span class="rsbtn_left rsimg rspart">',
            '           <span class="rsbtn_text">',
            '               <span>' . s($listentext) . '</span>',
            '           </span>',
            '       </span>',
            '       <span class="rsbtn_right rsimg rsplay rspart"></span>',
            '   </a>',
            '</div>',
        ]);

        $this->page->requires->js_call_amd('block_ahotts_embhl/speech', 'init');

        return $this->content;
    }

    /**
     * Link to the usage report, shown while editing to those who may see it.
     *
     * @return string HTML, or '' for nothing.
     */
    private function usage_link(): string {
        if (!$this->page->user_is_editing()) {
            return '';
        }
        $courseid = (int) $this->page->course->id;
        if ($courseid == SITEID) {
            $context = context_system::instance();
            $url = new moodle_url('/blocks/ahotts_embhl/usage.php');
        } else {
            $context = context_course::instance($courseid);
            $url = new moodle_url('/blocks/ahotts_embhl/usage.php', ['courseid' => $courseid]);
        }
        if (!has_capability('block/ahotts_embhl:viewusage', $context)) {
            return '';
        }
        return html_writer::link($url, get_string('usagereport', 'block_ahotts_embhl'));
    }

    /**
     * URL of the MP3 encoder worker, versioned so browsers drop old copies.
     *
     * @return array
     */
    private static function exporter_urls(): array {
        $version = ['v' => (string) get_config('block_ahotts_embhl', 'version')];
        return [
            'mp3worker' => (new moodle_url('/blocks/ahotts_embhl/js/mp3-worker.js', $version))->out(false),
        ];
    }

    /**
     * Each instance can override the reading language.
     *
     * @return bool
     */
    public function instance_allow_config() {
        return true;
    }

    /**
     * The block has site-wide settings.
     *
     * @return bool
     */
    public function has_config() {
        return true;
    }

    /**
     * Only one Listen button per page.
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * A block added to a course is shown on every page of that course, including all its activities.
     *
     * @return bool
     */
    public function instance_create() {
        global $DB;

        $context = context::instance_by_id($this->instance->parentcontextid, IGNORE_MISSING);
        if (!$context || $context->contextlevel != CONTEXT_COURSE) {
            return true;
        }

        $DB->update_record('block_instances', (object) [
            'id' => $this->instance->id,
            'pagetypepattern' => '*',
            'subpagepattern' => null,
            'showinsubcontexts' => 1,
        ]);
        $this->instance->pagetypepattern = '*';
        $this->instance->subpagepattern = null;
        $this->instance->showinsubcontexts = 1;

        return true;
    }

    /**
     * The block can be added to every page type except tag pages.
     *
     * @return array
     */
    public function applicable_formats() {
        return ['all' => true, 'tag' => false];
    }
}
