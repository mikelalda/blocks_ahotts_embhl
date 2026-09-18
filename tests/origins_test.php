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
 * Tests for the SCORM bridge origin allow list.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_ahotts_embhl\local\origins
 */

namespace block_ahotts_embhl;

use block_ahotts_embhl\local\origins;

class origins_test extends \advanced_testcase {

    public function test_parse_accepts_one_origin_per_line(): void {
        $parsed = origins::parse("https://scorm.example.org\nhttps://learning.example.org:8443\n");

        $this->assertSame([
            'https://scorm.example.org',
            'https://learning.example.org:8443',
        ], $parsed);
    }

    public function test_parse_normalises_case_paths_and_trailing_slashes(): void {
        $parsed = origins::parse("HTTPS://Scorm.Example.ORG/course/index.html\nhttps://scorm.example.org/");

        // Both entries describe the same origin, so only one survives.
        $this->assertSame(['https://scorm.example.org'], $parsed);
    }

    public function test_parse_drops_anything_that_is_not_an_origin(): void {
        $parsed = origins::parse(implode("\n", [
            'https://good.example.org',
            '*',
            'scorm.example.org',
            'javascript:alert(1)',
            'file:///etc/passwd',
            'data:text/html,<p>x</p>',
            'https://',
            'not an origin at all',
        ]));

        $this->assertSame(['https://good.example.org'], $parsed);
    }

    public function test_parse_of_an_empty_setting_allows_nothing(): void {
        $this->assertSame([], origins::parse(null));
        $this->assertSame([], origins::parse(''));
        $this->assertSame([], origins::parse("  \n \n"));
    }

    public function test_is_allowed_matches_the_exact_origin(): void {
        $allowed = ['https://scorm.example.org'];

        $this->assertTrue(origins::is_allowed('https://scorm.example.org', $allowed));
        $this->assertFalse(origins::is_allowed('http://scorm.example.org', $allowed), 'scheme must match');
        $this->assertFalse(origins::is_allowed('https://scorm.example.org:8443', $allowed), 'port must match');
        $this->assertFalse(origins::is_allowed('https://other.example.org', $allowed));
        $this->assertFalse(origins::is_allowed('', $allowed));
    }

    public function test_is_allowed_wildcard_covers_subdomains_only(): void {
        $allowed = ['https://*.example.org'];

        $this->assertTrue(origins::is_allowed('https://scorm.example.org', $allowed));
        $this->assertTrue(origins::is_allowed('https://deep.scorm.example.org', $allowed));
        $this->assertFalse(origins::is_allowed('https://example.org', $allowed), 'the bare domain is not a subdomain');
        $this->assertFalse(origins::is_allowed('https://notexample.org', $allowed));
        $this->assertFalse(origins::is_allowed('https://example.org.evil.com', $allowed), 'suffix trickery');
        $this->assertFalse(origins::is_allowed('http://scorm.example.org', $allowed), 'scheme must still match');
    }

    public function test_is_allowed_never_accepts_a_wildcard_as_the_tested_origin(): void {
        $this->assertFalse(origins::is_allowed('*', ['https://*.example.org']));
        $this->assertFalse(origins::is_allowed('https://*.example.org', ['https://*.example.org']));
    }

    public function test_is_allowed_with_an_empty_list_denies_everything(): void {
        $this->assertFalse(origins::is_allowed('https://scorm.example.org', []));
    }

    public function test_bridge_enabled_requires_both_the_switch_and_an_origin(): void {
        $this->resetAfterTest(true);

        set_config('scormbridge', 1, 'block_ahotts_embhl');
        set_config('scormorigins', '', 'block_ahotts_embhl');
        $this->assertFalse(origins::bridge_enabled(), 'no origin means nothing to talk to');

        set_config('scormorigins', 'https://scorm.example.org', 'block_ahotts_embhl');
        $this->assertTrue(origins::bridge_enabled());

        set_config('scormbridge', 0, 'block_ahotts_embhl');
        $this->assertFalse(origins::bridge_enabled(), 'the switch is off');
    }

    public function test_configured_returns_the_parsed_setting(): void {
        $this->resetAfterTest(true);

        set_config('scormorigins', "https://scorm.example.org\nbroken entry", 'block_ahotts_embhl');

        $this->assertSame(['https://scorm.example.org'], origins::configured());
    }
}
