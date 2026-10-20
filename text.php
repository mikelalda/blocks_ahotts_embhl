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
 * The whole text of a SCORM package or a book, as JSON, for the audio download.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/scorm/locallib.php');

use block_ahotts_embhl\local\activity_text;

$cmid = required_param('cmid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid);
if (!activity_text::supports($cm)) {
    throw new moodle_exception('invalidcoursemodule');
}
require_login($course, false, $cm);
$context = context_module::instance($cm->id);

if ($cm->modname === 'book') {
    require_capability('mod/book:read', $context);
} else if (!has_capability('moodle/course:manageactivities', $context)) {
    // A SCORM package is readable within its opening dates only.
    $scorm = $DB->get_record('scorm', ['id' => $cm->instance], 'id, timeopen, timeclose', MUST_EXIST);
    [$available] = scorm_get_availability_status($scorm);
    if (!$available) {
        throw new moodle_exception('notopenyet', 'scorm');
    }
}
\core\session\manager::write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
echo json_encode(activity_text::get($cm), JSON_UNESCAPED_UNICODE);
