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

namespace block_ahotts_embhl;

use block_ahotts_embhl\local\rise_proxy;

/**
 * Hosted Rise 360 packages pointed at the site's reverse proxy.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_ahotts_embhl\local\rise_proxy
 */
final class rise_proxy_test extends \advanced_testcase {

    /** @var string The indexAPI.html of a hosted Rise package, as Articulate publishes it. */
    const PACKAGE = '<!doctype html><html><head>
    <script type="application/json" id="__ENV__">
      {"REMOTE_PACKAGES_CDN_URL":"https://packages.articulateusercontent.com"}
    </script>
    <script crossorigin src="https://packages.articulateusercontent.com/content/RaQm6PEpAoIDk6BS11waYao2WB7EqphB/dist/host.js"></script>
    </head><body><div id="content">
      <iframe id="scormdriver_content" src="https://packages.articulateusercontent.com/content/RaQm6PEpAoIDk6BS11waYao2WB7EqphB/boot.html#token=f1a6e2f6"></iframe>
    </div></body></html>';

    /** @var string This site's origin in the tests. */
    const ORIGIN = 'https://moodle.example.local';

    public function test_a_hosted_package_is_recognised_and_its_course_found(): void {
        $this->assertTrue(rise_proxy::is_connected(self::PACKAGE, self::ORIGIN));
        $this->assertSame('RaQm6PEpAoIDk6BS11waYao2WB7EqphB', rise_proxy::course_id(self::PACKAGE, self::ORIGIN));

        $exported = '<html><body><iframe src="scormcontent/index.html"></iframe></body></html>';
        $this->assertFalse(rise_proxy::is_connected($exported, self::ORIGIN), 'a package with its content inside is left alone');
    }

    public function test_the_package_is_pointed_at_the_site_and_back(): void {
        $rewritten = rise_proxy::rewrite(self::PACKAGE, self::ORIGIN);

        $this->assertStringNotContainsString('articulateusercontent.com', $rewritten);
        $this->assertStringContainsString('"REMOTE_PACKAGES_CDN_URL":"https://moodle.example.local"', $rewritten);
        $this->assertStringContainsString('src="https://moodle.example.local/content/RaQm6PEpAoIDk6BS11waYao2WB7EqphB/boot.html',
            $rewritten);
        $this->assertSame('RaQm6PEpAoIDk6BS11waYao2WB7EqphB', rise_proxy::course_id($rewritten, self::ORIGIN),
            'a rewritten package is still recognised');
        $this->assertSame(self::PACKAGE, rise_proxy::restore($rewritten, self::ORIGIN));
    }

    public function test_the_allowlist_keeps_only_valid_course_ids(): void {
        global $CFG;
        $this->resetAfterTest();

        rise_proxy::write_allowed(['RaQm6PEpAoIDk6BS11waYao2WB7EqphB', 'with space', '../etc', 'RaQm6PEpAoIDk6BS11waYao2WB7EqphB']);

        $this->assertSame(['RaQm6PEpAoIDk6BS11waYao2WB7EqphB'], rise_proxy::allowed());
        $this->assertStringStartsWith($CFG->dataroot, rise_proxy::allowlist_path());
        $lines = file(rise_proxy::allowlist_path(), FILE_IGNORE_NEW_LINES);
        $this->assertContains('RaQm6PEpAoIDk6BS11waYao2WB7EqphB 1', $lines, 'in the format of an Apache txt RewriteMap');
    }

    public function test_enabling_rewrites_a_stored_package_and_allows_its_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_scorm', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'indexAPI.html',
        ], self::PACKAGE);

        $result = rise_proxy::enable_file($file, false);

        $this->assertSame('rewritten', $result['status']);
        $this->assertSame(['RaQm6PEpAoIDk6BS11waYao2WB7EqphB'], rise_proxy::allowed());
        $stored = rise_proxy::package_file($context->id);
        $this->assertStringNotContainsString('articulateusercontent.com', $stored->get_content());
        $this->assertSame('already', rise_proxy::enable_file($stored, false)['status']);

        $this->assertSame('restored', rise_proxy::disable_file($stored)['status']);
        $this->assertSame(self::PACKAGE, rise_proxy::package_file($context->id)->get_content());
    }

    public function test_the_apache_configuration_only_proxies_allowed_courses_without_credentials(): void {
        $this->resetAfterTest();

        $config = rise_proxy::apache_config();

        $this->assertStringContainsString('RewriteMap ahottsrise "txt:' . rise_proxy::allowlist_path() . '"', $config);
        $this->assertStringContainsString('RewriteRule "^/content/" "-" [F,L]', $config, 'any other course is refused');
        $this->assertStringContainsString('RequestHeader unset Cookie', $config);
        $this->assertStringContainsString('Header unset Set-Cookie', $config);
        $this->assertStringContainsString('SSLProxyVerify require', $config);
        $this->assertStringContainsString('Substitute "s|https://packages.articulateusercontent.com|' . rise_proxy::site_origin() . '|nq"',
            $config);
    }
}
