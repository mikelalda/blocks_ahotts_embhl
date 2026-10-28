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
 * Tests for reading Rise 360 hosted ("connected") courses from Articulate.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_ahotts_embhl\local\hosted_course
 * @covers     \block_ahotts_embhl\local\scorm_pdf
 */

namespace block_ahotts_embhl;

use block_ahotts_embhl\local\hosted_course;
use block_ahotts_embhl\local\scorm_pdf;

class hosted_course_test extends \advanced_testcase {

    /** @var string A course id as Articulate writes it. */
    const ID = 'RaQm6PEpAoIDk6BS11waYao2WB7EqphB';

    /** @var string Where Articulate serves it. */
    const COURSE = 'https://packages.articulateusercontent.com/content/' . self::ID . '/';

    /**
     * The indexAPI.html of a connected package, as Rise exports it or as the proxy rewrote it.
     *
     * @param string $host
     * @return string
     */
    private function indexapi(string $host = 'https://packages.articulateusercontent.com'): string {
        return '<script type="application/json" id="__ENV__">{"REMOTE_PACKAGES_CDN_URL":"' . $host . '"}</script>'
            . '<script crossorigin src="' . $host . '/content/' . self::ID . '/dist/host.js"></script>'
            . '<iframe id="scormdriver_content" src="' . $host . '/content/' . self::ID
            . '/boot.html#token=eyJhbGciOi.J9-x_y%3D"></iframe>';
    }

    /**
     * Articulate, as far as these tests need it: the course page needs the token.
     *
     * @param array $log Requests made, as "METHOD url".
     * @return callable
     */
    private function articulate(array &$log): callable {
        $course = ['course' => ['title' => 'Curso conectado', 'lessons' => [
            ['type' => 'blocks', 'title' => 'Lección alojada', 'items' => [
                ['type' => 'text', 'family' => 'text', 'variant' => 'paragraph',
                    'items' => [['paragraph' => '<p>Texto servido por Articulate.</p>']]],
                ['type' => 'image', 'family' => 'image', 'variant' => 'hero',
                    'items' => [['media' => ['image' => ['crushedKey' => 'foto.png']], 'caption' => '<p>Pie</p>']]],
            ]],
        ]]];
        $locale = '__resolveJsonp("course:und","' . base64_encode(json_encode($course)) . '")';
        $png = file_get_contents(__DIR__ . '/fixtures/scorm/rise/scormcontent/assets/foto.png');
        $info = base64_encode(json_encode(['courseId' => self::ID, 'courseVersion' => '15316c53-7bda']));
        return function(string $method, string $url, array $post) use (&$log, $locale, $png, $info): array {
            $log[] = $method . ' ' . $url;
            switch ($method . ' ' . $url) {
                case 'GET ' . self::COURSE . 'boot.html?bootEnabled=true':
                    return ['status' => 200, 'body' => '<meta id="__COURSE_INFO__" content="' . $info . '" />'];
                case 'POST ' . self::COURSE . 'content.html':
                    return ($post['courseAccessToken'] ?? '') === 'eyJhbGciOi.J9-x_y='
                        ? ['status' => 200, 'body' => '<script>window.i18n = {"available":["und"],"default":"und"};</script>']
                        : ['status' => 403, 'body' => 'denied'];
                case 'GET ' . self::COURSE . 'locales/und.js':
                    return ['status' => 200, 'body' => $locale];
                case 'GET ' . self::COURSE . 'assets/foto.png':
                    return ['status' => 200, 'body' => $png];
            }
            return ['status' => 404, 'body' => ''];
        };
    }

    public function test_the_course_and_its_token_come_from_the_package(): void {
        foreach (['https://packages.articulateusercontent.com', 'https://moodle.example.local'] as $host) {
            $course = hosted_course::from_package($this->indexapi($host), 'https://moodle.example.local');
            $this->assertSame(self::ID, $course->course_id());
        }
        $this->assertSame('eyJhbGciOi.J9-x_y=', hosted_course::token($this->indexapi(), self::ID));
        $this->assertNull(hosted_course::from_package('<html>indexAPI of a packaged course</html>',
            'https://moodle.example.local'));
    }

    public function test_a_hosted_course_becomes_a_document_with_its_images(): void {
        $log = [];
        $course = hosted_course::from_package($this->indexapi(), 'https://moodle.example.local', $this->articulate($log));
        $converter = scorm_pdf::from_hosted($course, make_request_directory());

        $this->assertSame('rise', $converter->kind());
        $document = $converter->document('fallback');
        $html = implode("\n", array_column($document['sections'], 'html'));

        $this->assertSame('Curso conectado', $document['title']);
        $this->assertSame(['Lección alojada'], array_column($document['sections'], 'title'));
        $this->assertStringContainsString('Texto servido por Articulate.', $html);
        $this->assertStringContainsString('<img ', $html);
        $this->assertSame('15316c53-7bda', $course->version());
        $this->assertContains('POST ' . self::COURSE . 'content.html', $log);
    }

    public function test_a_refused_token_gives_an_empty_document_and_says_why(): void {
        $log = [];
        $html = str_replace('eyJhbGciOi.J9-x_y%3D', 'expired', $this->indexapi());
        $course = hosted_course::from_package($html, 'https://moodle.example.local', $this->articulate($log));
        $converter = scorm_pdf::from_hosted($course, '');

        $this->assertTrue(scorm_pdf::is_empty($converter->document('fallback')));
        $this->assertStringContainsString('content.html: HTTP 403', $converter->source());
    }

    public function test_only_articulate_is_ever_asked(): void {
        $this->assertTrue(hosted_course::allowed(self::COURSE . 'assets/a.png'));
        $this->assertTrue(hosted_course::allowed('https://cdn.articulate.com/assets/rise/fonts/Lato.woff'));
        $this->assertFalse(hosted_course::allowed('http://packages.articulateusercontent.com/content/x'));
        $this->assertFalse(hosted_course::allowed('https://packages.articulateusercontent.com.evil.example/x'));
        $this->assertFalse(hosted_course::allowed('https://user@packages.articulateusercontent.com/x'));
        $this->assertFalse(hosted_course::allowed('https://127.0.0.1/x'));

        $log = [];
        $course = hosted_course::from_package($this->indexapi(), 'https://moodle.example.local', $this->articulate($log));
        $reader = $course->reader();
        $this->assertNull($reader('https://moodle.example.local/login/index.php'));
        $this->assertNull($reader('imsmanifest.xml'));
        $this->assertSame([], array_filter($log, function($line) {
            return strpos($line, 'moodle.example.local') !== false;
        }));
    }
}
