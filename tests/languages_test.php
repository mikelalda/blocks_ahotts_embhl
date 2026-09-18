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

    public function test_languages_with_a_local_voice_prefer_it_to_the_browser(): void {
        $this->resetAfterTest(true);
        set_config('apiurl_es', 'https://tts.example.local/es', 'block_ahotts_embhl');
        set_config('apiurl_en', '', 'block_ahotts_embhl');

        // The browser's own voice stays behind the local one, for when the model
        // cannot be downloaded.
        $this->assertSame(
            ['piper', 'webspeech', 'api'],
            array_column(languages::engines_for('es', true), 'kind')
        );
        $this->assertSame(['piper', 'webspeech'], array_column(languages::engines_for('en', true), 'kind'));
    }

    public function test_languages_with_no_local_voice_are_read_by_the_browser(): void {
        $this->resetAfterTest(true);
        set_config('apiurl_fr', '', 'block_ahotts_embhl');

        // French has no Piper voice published with the block.
        $this->assertSame(['webspeech'], array_column(languages::engines_for('fr', true), 'kind'));
    }

    public function test_the_browser_speaks_alone_when_the_local_voices_are_off(): void {
        $this->resetAfterTest(true);
        set_config('apiurl_es', '', 'block_ahotts_embhl');

        $this->assertSame(['webspeech'], array_column(languages::engines_for('es', false), 'kind'));
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
        $this->assertSame(['piper', 'webspeech'], array_column($built[1]['engines'], 'kind'));
        $this->assertSame('es-ES', $built[1]['bcp47']);
    }

    public function test_build_leaves_out_a_language_nothing_can_speak(): void {
        $this->resetAfterTest(true);
        set_config('apiurl_eu', '', 'block_ahotts_embhl');

        $built = languages::build(['eu', 'es'], 'es_es', false);

        $this->assertSame(['es'], array_column($built, 'code'));
    }

    public function test_piper_voices_are_listed_per_language(): void {
        $this->resetAfterTest(true);

        $this->assertSame(['antton', 'maider'], array_column(languages::piper_voices(['eu']), 'id'));
        $this->assertSame(['davefx', 'claude'], array_column(languages::piper_voices(['es']), 'id'));

        $both = languages::piper_voices(['eu', 'es']);
        $this->assertSame(['eu', 'eu', 'es', 'es'], array_column($both, 'language'));

        foreach (languages::piper_voices() as $voice) {
            $this->assertStringContainsString('.onnx', $voice['modelurl']);
            $this->assertNotEmpty($voice['label']);
            $this->assertArrayHasKey($voice['language'], languages::PIPER_VOICES);
        }
    }

    public function test_piper_voices_of_a_language_with_none_are_empty(): void {
        $this->resetAfterTest(true);

        $this->assertSame([], languages::piper_voices(['fr']));
    }

    public function test_default_piper_voice_falls_back_when_the_setting_makes_no_sense(): void {
        $this->resetAfterTest(true);
        set_config('pipervoice_eu', 'maider', 'block_ahotts_embhl');
        // A voice of another language is not a voice for this one.
        set_config('pipervoice_es', 'antton', 'block_ahotts_embhl');

        $defaults = languages::default_piper_voices(['eu', 'es', 'fr']);

        $this->assertSame('maider', $defaults['eu']);
        $this->assertSame('davefx', $defaults['es']);
        $this->assertArrayNotHasKey('fr', $defaults, 'a language with no voices gets no default');
    }

    public function test_piper_voices_honour_a_self_hosted_model(): void {
        $this->resetAfterTest(true);
        set_config('pipermodel_antton', 'https://moodle.example.local/voices/antton.onnx', 'block_ahotts_embhl');

        $voices = languages::piper_voices(['eu']);

        $this->assertSame('https://moodle.example.local/voices/antton.onnx', $voices[0]['modelurl']);
        // The voice that was not overridden keeps its published URL.
        $this->assertStringContainsString('huggingface.co/itzune', $voices[1]['modelurl']);
    }

    public function test_the_default_phonemizer_carries_more_than_english(): void {
        // The block first shipped pointing at phonemizer@1.2.1, an eSpeak NG
        // build carrying English alone. It rejects every Basque word, so the
        // itzune voices produced no sound at all. Only a full build will do.
        $this->assertStringNotContainsString('npm/phonemizer@', languages::DEFAULT_PHONEMIZER);
        $this->assertStringContainsString('espeak-ng', languages::DEFAULT_PHONEMIZER);
        $this->assertStringEndsWith('.wasm', languages::DEFAULT_PHONEMIZER_WASM);
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
