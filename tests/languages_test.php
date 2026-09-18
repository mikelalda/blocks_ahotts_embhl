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
 * Tests for the reading languages, engines and voices.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_ahotts_embhl\local\languages
 */

namespace block_ahotts_embhl;

use block_ahotts_embhl\local\languages;

class languages_test extends \advanced_testcase {

    public function test_normalise_reduces_any_tag_to_its_base_code(): void {
        $this->assertSame('eu', languages::normalise('eu_ES'));
        $this->assertSame('eu', languages::normalise('eu-ES'));
        $this->assertSame('eu', languages::normalise('EU'));
        $this->assertSame('es', languages::normalise('es_419'));
        $this->assertSame('', languages::normalise(''));
    }

    public function test_bcp47_keeps_the_region_configured_for_that_language(): void {
        // The site is set to Mexican Spanish, so Spanish keeps es-MX.
        $this->assertSame('es-MX', languages::bcp47('es', 'es_mx'));
        // Other languages get their default region.
        $this->assertSame('eu-ES', languages::bcp47('eu', 'es_mx'));
        $this->assertSame('en-GB', languages::bcp47('en', 'es_mx'));
        $this->assertSame('fr-FR', languages::bcp47('fr', ''));
    }

    public function test_offered_falls_back_to_the_default_languages(): void {
        $this->assertSame(['eu', 'es', 'en'], languages::offered(''));
        $this->assertSame(['eu', 'es', 'en'], languages::offered('   '));
    }

    public function test_offered_keeps_the_administrators_order_and_drops_junk(): void {
        $this->assertSame(['es', 'eu'], languages::offered('es,eu'));
        $this->assertSame(['eu', 'fr'], languages::offered("eu\nfr\neu\nklingon"));
        $this->assertSame(['eu'], languages::offered('eu_ES'));
    }

    public function test_resolve_follows_the_interface_language_only_in_page_mode(): void {
        $offered = ['eu', 'es', 'en'];

        $this->assertSame('es', languages::resolve('page', 'eu_es', 'es', $offered));
        $this->assertSame('eu', languages::resolve('fixed', 'eu_es', 'es', $offered));
        // An interface language nobody can speak falls back to the setting.
        $this->assertSame('eu', languages::resolve('page', 'eu_es', 'is', $offered));
        // And a setting nobody can speak falls back to the first offered.
        $this->assertSame('eu', languages::resolve('fixed', 'is_is', 'is', $offered));
        $this->assertSame('', languages::resolve('fixed', 'eu_es', 'eu', []));
    }

    public function test_basque_prefers_the_local_voice_then_the_api(): void {
        $this->resetAfterTest(true);
        set_config('apiurl_eu', 'https://tts.example.local/eu', 'block_ahotts_embhl');

        $engines = languages::engines_for('eu', true);

        $this->assertSame(['piper', 'api'], array_column($engines, 'kind'));
        $this->assertSame('https://tts.example.local/eu', $engines[1]['url']);
        $this->assertSame('antton', $engines[1]['voice']);
    }

    public function test_basque_uses_the_api_alone_when_the_local_voice_is_off(): void {
        $this->resetAfterTest(true);
        set_config('apiurl_eu', 'https://tts.example.local/eu', 'block_ahotts_embhl');

        $engines = languages::engines_for('eu', false);

        $this->assertSame(['api'], array_column($engines, 'kind'));
    }

    public function test_basque_is_not_offered_to_a_browser_voice(): void {
        $this->resetAfterTest(true);
        set_config('apiurl_eu', '', 'block_ahotts_embhl');

        // No local voice and no endpoint: nothing can speak Basque, and reading
        // it with a Spanish browser voice is not an acceptable answer.
        $this->assertSame([], languages::engines_for('eu', false));
    }

    public function test_other_languages_are_read_by_the_browser_first(): void {
        $this->resetAfterTest(true);
        set_config('apiurl_es', 'https://tts.example.local/es', 'block_ahotts_embhl');
        set_config('apiurl_en', '', 'block_ahotts_embhl');

        $this->assertSame(['webspeech', 'api'], array_column(languages::engines_for('es', true), 'kind'));
        $this->assertSame(['webspeech'], array_column(languages::engines_for('en', true), 'kind'));
    }

    public function test_build_describes_every_offered_language(): void {
        $this->resetAfterTest(true);
        set_config('apiurl_eu', 'https://tts.example.local/eu', 'block_ahotts_embhl');

        $built = languages::build(['eu', 'es'], 'eu_es', true);

        $this->assertCount(2, $built);
        $this->assertSame('eu', $built[0]['code']);
        $this->assertSame('eu-ES', $built[0]['bcp47']);
        $this->assertNotEmpty($built[0]['label']);
        $this->assertSame(['piper', 'api'], array_column($built[0]['engines'], 'kind'));
        $this->assertSame('es-ES', $built[1]['bcp47']);
    }

    public function test_build_leaves_out_a_language_nothing_can_speak(): void {
        $this->resetAfterTest(true);
        set_config('apiurl_eu', '', 'block_ahotts_embhl');

        $built = languages::build(['eu', 'es'], 'es_es', false);

        $this->assertSame(['es'], array_column($built, 'code'));
    }

    public function test_piper_voices_point_at_the_itzune_models(): void {
        $this->resetAfterTest(true);

        $voices = languages::piper_voices();

        $this->assertSame(['antton', 'maider'], array_column($voices, 'id'));
        foreach ($voices as $voice) {
            $this->assertStringContainsString('.onnx', $voice['modelurl']);
            $this->assertSame('eu', $voice['language']);
            $this->assertNotEmpty($voice['label']);
        }
    }

    public function test_piper_voices_honour_a_self_hosted_model(): void {
        $this->resetAfterTest(true);
        set_config('pipermodel_antton', 'https://moodle.example.local/voices/antton.onnx', 'block_ahotts_embhl');

        $voices = languages::piper_voices();

        $this->assertSame('https://moodle.example.local/voices/antton.onnx', $voices[0]['modelurl']);
        // The voice that was not overridden keeps its published URL.
        $this->assertStringContainsString('huggingface.co/itzune', $voices[1]['modelurl']);
    }

    public function test_piper_is_only_enabled_when_it_can_actually_run(): void {
        $this->resetAfterTest(true);

        set_config('piperenabled', 1, 'block_ahotts_embhl');
        set_config('piperort', 'https://cdn.example.org/ort.webgpu.min.js', 'block_ahotts_embhl');
        set_config('piperphonemizer', 'https://cdn.example.org/phonemizer.js', 'block_ahotts_embhl');
        $this->assertTrue(languages::piper_enabled());

        set_config('piperphonemizer', '', 'block_ahotts_embhl');
        $this->assertFalse(languages::piper_enabled(), 'no phonemizer, no local voice');

        set_config('piperphonemizer', 'https://cdn.example.org/phonemizer.js', 'block_ahotts_embhl');
        set_config('piperenabled', 0, 'block_ahotts_embhl');
        $this->assertFalse(languages::piper_enabled());
    }

    public function test_api_voice_prefers_the_configured_one(): void {
        $this->resetAfterTest(true);

        $this->assertSame('antton', languages::api_voice('eu'));
        set_config('voice_eu', 'maider', 'block_ahotts_embhl');
        $this->assertSame('maider', languages::api_voice('eu'));
        $this->assertSame('', languages::api_voice('is'));
    }
}
