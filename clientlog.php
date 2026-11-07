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
 * Receive what happened in the browser: readings and downloads for the usage
 * report, and how audio exports went, for the web server log.
 *
 * Audio is synthesised in the learner's browser, so without this an export that
 * fails or never ends leaves no trace an administrator can look at.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../config.php');

use block_ahotts_embhl\local\usage;

$event = optional_param('event', '', PARAM_ALPHA);
$detail = optional_param('detail', '', PARAM_TEXT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$language = core_text::substr(optional_param('lang', '', PARAM_ALPHANUMEXT), 0, 20);
$source = optional_param('source', '', PARAM_ALPHA);

// How an audio export went in the browser, for the web server log.
$exportevents = ['exportstart', 'exportdone', 'exporterror', 'exportcancel', 'exportempty', 'exportnoengine'];
// What the usage report counts.
$counted = ['listen' => usage::LISTEN, 'exportdone' => usage::AUDIO, 'pdfdownload' => usage::PDF];
if (!in_array($event, $exportevents, true) && !isset($counted[$event])) {
    http_response_code(400);
    die();
}

// The page the report comes from: an activity, a course or the rest of the site.
if ($cmid) {
    [$course, $cm] = get_course_and_cm_from_cmid($cmid);
    require_login($course, false, $cm, false, true);
    $context = context_module::instance($cm->id);
} else if ($courseid && $courseid != SITEID) {
    $course = get_course($courseid);
    require_login($course, false, null, false, true);
    $context = context_course::instance($course->id);
} else {
    require_login(null, false, null, false, true);
    $course = get_site();
    $context = context_system::instance();
}
require_sesskey();

if (isset($counted[$event])) {
    $other = ['language' => $language];
    if ($event === 'pdfdownload' && in_array($source, ['screen', 'package', 'generated'], true)) {
        $other['source'] = $source;
    }
    usage::record($counted[$event], $context, (int) $course->id, array_filter($other));
}

if (in_array($event, $exportevents, true)) {
    // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative -- The web server log is the intended destination.
    error_log('block_ahotts_embhl client: user ' . $USER->id . ' cmid ' . $cmid . ' ' . $event . ': '
        . core_text::substr(preg_replace('/\s+/u', ' ', $detail), 0, 800)
        . ' [' . core_text::substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 160) . ']');
}
http_response_code(204);
