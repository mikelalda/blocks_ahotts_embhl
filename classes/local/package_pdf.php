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

namespace block_ahotts_embhl\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Find the PDFs of the SCORM activity shown on the page.
 *
 * Moodle unpacks every package into mod_scorm/content and bumps the activity
 * revision whenever the package is replaced, so both the generated PDF and the
 * URLs of the shipped ones always match the latest published version.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_pdf {

    /** @var int Packages rarely hold more than a few PDFs; never list hundreds. */
    const MAX_FILES = 20;

    /**
     * PDFs of the SCORM activity shown on the page, best candidate first: the
     * PDF built from the package (see scorm_pdf), then the PDFs it ships.
     *
     * @param \moodle_page $page
     * @return array List of ['url' => string, 'filename' => string, 'generated' => bool].
     */
    public static function for_page(\moodle_page $page): array {
        global $DB;

        $cm = $page->cm;
        if (!$cm || $cm->modname !== 'scorm') {
            return [];
        }
        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], 'id, name, revision');
        if (!$scorm) {
            return [];
        }
        $context = \context_module::instance($cm->id);
        // Only the PDFs: a Storyline package easily holds thousands of files.
        $candidates = $DB->get_records_select('files',
            "contextid = :contextid AND component = 'mod_scorm' AND filearea = 'content' AND itemid = 0
                AND filename <> '.' AND " . $DB->sql_like('filename', ':pdf', false),
            ['contextid' => $context->id, 'pdf' => '%.pdf'], '', 'id, filepath, filename, filesize, mimetype');
        $candidates = array_map(function($file): array {
            return [
                'filepath' => $file->filepath,
                'filename' => $file->filename,
                'filesize' => (int) $file->filesize,
                'mimetype' => (string) $file->mimetype,
            ];
        }, array_values($candidates));

        $result = [];
        if (scorm_pdf_file::enabled()) {
            $result[] = [
                'url' => scorm_pdf_file::url($cm)->out(false),
                'filename' => scorm_pdf_file::filename($scorm->name),
                'generated' => true,
            ];
        }
        foreach (array_slice(self::rank($candidates), 0, self::MAX_FILES) as $candidate) {
            $url = \moodle_url::make_pluginfile_url($context->id, 'mod_scorm', 'content',
                (int) $scorm->revision, $candidate['filepath'], $candidate['filename'], true);
            $result[] = ['url' => $url->out(false), 'filename' => $candidate['filename'], 'generated' => false];
        }
        return $result;
    }

    /**
     * Keep the PDFs and order them: shallowest folder first (an author puts the
     * course PDF next to the launch file), then the largest (the whole course
     * rather than a one-page handout), then by name for a stable order.
     *
     * @param array $files List of ['filepath', 'filename', 'filesize', 'mimetype'].
     * @return array The PDFs, best first.
     */
    public static function rank(array $files): array {
        $pdfs = array_values(array_filter($files, function(array $file): bool {
            return preg_match('/\.pdf$/i', $file['filename']) || $file['mimetype'] === 'application/pdf';
        }));
        usort($pdfs, function(array $a, array $b): int {
            $depth = substr_count($a['filepath'], '/') <=> substr_count($b['filepath'], '/');
            if ($depth) {
                return $depth;
            }
            $size = $b['filesize'] <=> $a['filesize'];
            if ($size) {
                return $size;
            }
            return strcmp($a['filepath'] . $a['filename'], $b['filepath'] . $b['filename']);
        });
        return $pdfs;
    }
}
