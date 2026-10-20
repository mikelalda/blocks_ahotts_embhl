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
 * Tests for finding the PDF published inside a SCORM package.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_ahotts_embhl\local\package_pdf
 */

namespace block_ahotts_embhl;

use block_ahotts_embhl\local\package_pdf;

class package_pdf_test extends \advanced_testcase {

    private function file(string $filepath, string $filename, int $filesize, string $mimetype = ''): array {
        return ['filepath' => $filepath, 'filename' => $filename, 'filesize' => $filesize, 'mimetype' => $mimetype];
    }

    public function test_rank_keeps_pdfs_shallowest_then_largest(): void {
        $ranked = package_pdf::rank([
            $this->file('/', 'index.html', 900),
            $this->file('/story_content/', 'handout.pdf', 5000),
            $this->file('/', 'resumen.PDF', 100),
            $this->file('/', 'curso.pdf', 2000),
            $this->file('/', 'sinextension', 50, 'application/pdf'),
        ]);

        $this->assertSame(['curso.pdf', 'resumen.PDF', 'sinextension', 'handout.pdf'],
            array_column($ranked, 'filename'));
    }

    public function test_for_page_lists_the_package_pdf_with_the_current_revision(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('scorm', $scorm->id);
        $context = \context_module::instance($cm->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_scorm', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'curso.pdf',
        ], '%PDF-1.4');
        $revision = (int) $GLOBALS['DB']->get_field('scorm', 'revision', ['id' => $scorm->id]);

        $page = new \moodle_page();
        $page->set_cm(get_fast_modinfo($course)->get_cm($cm->id));
        $pdfs = package_pdf::for_page($page);

        $this->assertCount(2, $pdfs);
        // First the PDF built from the package...
        $this->assertTrue($pdfs[0]['generated']);
        $this->assertStringContainsString('/blocks/ahotts_embhl/scormpdf.php?cmid=' . $cm->id, $pdfs[0]['url']);
        // ...then the one it ships.
        $this->assertFalse($pdfs[1]['generated']);
        $this->assertSame('curso.pdf', $pdfs[1]['filename']);
        $this->assertStringContainsString("/{$context->id}/mod_scorm/content/{$revision}/curso.pdf", $pdfs[1]['url']);
        $this->assertStringContainsString('forcedownload=1', $pdfs[1]['url']);

        // Without the generated PDF, only the shipped one is offered.
        set_config('scormpdf', 0, 'block_ahotts_embhl');
        $this->assertSame(['curso.pdf'], array_column(package_pdf::for_page($page), 'filename'));
    }

    public function test_for_page_ignores_other_activities(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $pageactivity = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $page = new \moodle_page();
        $page->set_cm(get_fast_modinfo($course)->get_cm($pageactivity->cmid));

        $this->assertSame([], package_pdf::for_page($page));
    }
}
