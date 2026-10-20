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
 * Download the PDF of a SCORM activity, built from its current package.
 *
 * Whoever may open the SCORM activity may download its PDF, under the same
 * opening and closing dates.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/mod/scorm/locallib.php');

use block_ahotts_embhl\local\scorm_pdf_file;

$cmid = required_param('cmid', PARAM_INT);
$cm = get_coursemodule_from_id('scorm', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);

if (!scorm_pdf_file::enabled()) {
    throw new moodle_exception('scormpdfdisabled', 'block_ahotts_embhl');
}
if (!has_capability('moodle/course:manageactivities', $context)) {
    $scorm = $DB->get_record('scorm', ['id' => $cm->instance], 'id, timeopen, timeclose', MUST_EXIST);
    [$available] = scorm_get_availability_status($scorm);
    if (!$available) {
        throw new moodle_exception('notopenyet', 'scorm');
    }
}

// Building a long course takes a while: do not hold the session meanwhile.
\core\session\manager::write_close();

try {
    $file = scorm_pdf_file::get($cm);
    if (!$file) {
        throw new moodle_exception('scormpdfempty', 'block_ahotts_embhl');
    }
} catch (Throwable $e) {
    // Leave the cause in the web server log: the package cannot be inspected from the browser.
    error_log('block_ahotts_embhl: no PDF for SCORM cmid ' . $cm->id . ' (' . scorm_pdf_file::describe($cm) . '): '
        . get_class($e) . ': ' . $e->getMessage()
        . (!empty($e->debuginfo) ? ' [' . $e->debuginfo . ']' : '') . ' at ' . $e->getFile() . ':' . $e->getLine());
    // The block fetches the PDF and shows the reason; a browser gets the usual error page.
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $e instanceof moodle_exception ? $e->getMessage() : get_string('scormpdffailed', 'block_ahotts_embhl');
        die();
    }
    throw $e;
}
send_stored_file($file, 0, 0, true);
