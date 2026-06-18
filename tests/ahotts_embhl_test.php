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
class block_ahotts_embhl_testcase extends advanced_testcase {
    public function test_build_href_routes_to_custom_api_by_language(): void {
        $this->resetAfterTest(true);

        set_config('apiurl_eu', 'https://tts.example.local/eu', 'block_ahotts_embhl');
        set_config('apiurl_es', 'https://tts.example.local/es', 'block_ahotts_embhl');
        set_config('apiurl_en', 'https://tts.example.local/en', 'block_ahotts_embhl');

        $eu = \block_ahotts_embhl\local\api::build_href('eu_es', 'https://example.com/page', 'region-main', '&foo=bar', 'page-title');
        $es = \block_ahotts_embhl\local\api::build_href('es_es', 'https://example.com/page', 'region-main');
        $en = \block_ahotts_embhl\local\api::build_href('en_us', 'https://example.com/page', 'region-main');

        $this->assertStringStartsWith('https://tts.example.local/eu?', $eu);
        $this->assertStringContainsString('lang=eu_es', $eu);
        $this->assertStringContainsString('readid=region-main', $eu);
        $this->assertStringContainsString('audiofilename=page-title', $eu);
        $this->assertStringContainsString('foo=bar', $eu);

        $this->assertStringStartsWith('https://tts.example.local/es?', $es);
        $this->assertStringStartsWith('https://tts.example.local/en?', $en);
    }
}