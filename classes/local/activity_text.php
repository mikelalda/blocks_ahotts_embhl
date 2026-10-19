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
 * The whole text of an activity, for the audio download.
 *
 * On a SCORM package or a book the screen shows one page at a time, so the
 * audio download reads the whole activity from the server instead: every
 * lesson of the package (see scorm_pdf), or every chapter of the book the
 * reader may see.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_text {

    /** @var string[] Activities read as a whole. */
    const MODULES = ['scorm', 'book'];

    /**
     * Whether an activity is read as a whole.
     *
     * @param \cm_info|\stdClass|null $cm
     * @return bool
     */
    public static function supports($cm): bool {
        return $cm && in_array($cm->modname, self::MODULES, true);
    }

    /**
     * URL that returns the text of an activity.
     *
     * @param \cm_info|\stdClass $cm
     * @return \moodle_url
     */
    public static function url($cm): \moodle_url {
        return new \moodle_url('/blocks/ahotts_embhl/text.php', ['cmid' => $cm->id]);
    }

    /**
     * Title and paragraphs of an activity, in reading order.
     *
     * @param \cm_info|\stdClass $cm A SCORM or book course module the user may view.
     * @return array ['title' => string, 'paragraphs' => string[]]
     */
    public static function get($cm): array {
        $context = \context_module::instance($cm->id);
        if ($cm->modname === 'scorm') {
            $document = scorm_pdf::from_context($context, '')->document(format_string($cm->name));
            return ['title' => $document['title'], 'paragraphs' => scorm_pdf::paragraphs($document)];
        }
        if ($cm->modname === 'book') {
            return self::book($cm, $context);
        }
        throw new \coding_exception('Unsupported activity: ' . $cm->modname);
    }

    /**
     * Every chapter of a book the user may see.
     *
     * @param \cm_info|\stdClass $cm
     * @param \context_module $context
     * @return array
     */
    private static function book($cm, \context_module $context): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/book/locallib.php');

        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
        $viewhidden = has_capability('mod/book:viewhiddenchapters', $context);
        $sections = [];
        foreach (book_preload_chapters($book) as $chapter) {
            if ($chapter->hidden && !$viewhidden) {
                continue;
            }
            $content = file_rewrite_pluginfile_urls($chapter->content, 'pluginfile.php', $context->id, 'mod_book',
                'chapter', $chapter->id);
            $sections[] = [
                'title' => format_string($chapter->title, true, ['context' => $context]),
                'html' => format_text($content, $chapter->contentformat, ['noclean' => true, 'context' => $context]),
                'level' => $chapter->subchapter ? 2 : 1,
            ];
        }
        $document = ['title' => format_string($book->name, true, ['context' => $context]), 'sections' => $sections];
        return ['title' => $document['title'], 'paragraphs' => scorm_pdf::paragraphs($document)];
    }
}
