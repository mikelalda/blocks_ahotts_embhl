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
 * The PDF of a SCORM activity, built once per package revision.
 *
 * The file lives in the activity context (block_ahotts_embhl/scormpdf), with
 * the SCORM revision as item id: replacing the package bumps the revision, so
 * the next request builds the PDF of the new version and the old one is
 * deleted.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_pdf_file {

    /** @var string File area of the generated PDFs. */
    const FILEAREA = 'scormpdf';

    /** @var string Folder of PDFs built by this version of the converter: bump it to rebuild every PDF. */
    const FORMAT = '/v3/';

    /**
     * Whether the administrator lets the block build PDFs from SCORM packages.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return get_config('block_ahotts_embhl', 'scormpdf') !== '0';
    }

    /**
     * URL that downloads the PDF of a SCORM activity.
     *
     * @param \cm_info|\stdClass $cm
     * @return \moodle_url
     */
    public static function url($cm): \moodle_url {
        return new \moodle_url('/blocks/ahotts_embhl/scormpdf.php', ['cmid' => $cm->id]);
    }

    /**
     * File name offered for the PDF of an activity.
     *
     * @param string $name Activity name.
     * @return string
     */
    public static function filename(string $name): string {
        $name = trim(clean_filename(strip_tags(format_string($name))), ' ._-');
        return \core_text::substr($name !== '' ? $name : 'scorm', 0, 120) . '.pdf';
    }

    /**
     * What the converter makes of a package, for the logs.
     *
     * @param \cm_info|\stdClass $cm
     * @return string
     */
    public static function describe($cm): string {
        $context = \context_module::instance($cm->id);
        $count = count(get_file_storage()->get_area_files($context->id, 'mod_scorm', 'content', 0, 'id', false));
        try {
            $kind = scorm_pdf::from_context($context, make_request_directory())->kind();
        } catch (\Throwable $e) {
            $kind = 'unknown';
        }
        return "package: {$kind}, {$count} files";
    }

    /**
     * The PDF of the current package revision, building it when needed.
     *
     * @param \cm_info|\stdClass $cm A SCORM course module.
     * @return \stored_file|null Null when the package holds no readable text.
     */
    public static function get($cm): ?\stored_file {
        global $DB;

        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], 'id, name, revision', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $revision = (int) $scorm->revision;
        $filename = self::filename($scorm->name);

        $file = self::find($context, $revision);
        if ($file) {
            return $file;
        }

        // Two learners opening a new version at once must not both build it.
        $lock = \core\lock\lock_config::get_lock_factory('block_ahotts_embhl_scormpdf')
            ->get_lock('cm' . $cm->id, 120);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $file = self::find($context, $revision);
            if ($file) {
                return $file;
            }
            return self::build($context, $revision, $filename);
        } finally {
            $lock->release();
        }
    }

    /**
     * The stored PDF of a revision, if it was built already.
     *
     * @param \context_module $context
     * @param int $revision
     * @return \stored_file|null
     */
    private static function find(\context_module $context, int $revision): ?\stored_file {
        $files = get_file_storage()->get_directory_files($context->id, 'block_ahotts_embhl', self::FILEAREA,
            $revision, self::FORMAT, false, false);
        return $files ? reset($files) : null;
    }

    /**
     * Build, store and return the PDF of a revision, dropping older ones.
     *
     * @param \context_module $context
     * @param int $revision
     * @param string $filename
     * @return \stored_file|null
     */
    private static function build(\context_module $context, int $revision, string $filename): ?\stored_file {
        \core_php_time_limit::raise(600);
        raise_memory_limit(MEMORY_HUGE);

        $converter = scorm_pdf::from_context($context, make_request_directory());
        $document = $converter->document(pathinfo($filename, PATHINFO_FILENAME));
        if (scorm_pdf::is_empty($document)) {
            return null;
        }
        $content = scorm_pdf::render($document, make_localcache_directory('block_ahotts_embhl/fonts'));

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'block_ahotts_embhl', self::FILEAREA);
        return $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'block_ahotts_embhl',
            'filearea' => self::FILEAREA,
            'itemid' => $revision,
            'filepath' => self::FORMAT,
            'filename' => $filename,
            'mimetype' => 'application/pdf',
        ], $content);
    }
}
