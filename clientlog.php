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
 * Write what happened to an audio export in the browser to the web server log.
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

require_login(null, false);
require_sesskey();

$event = optional_param('event', '', PARAM_ALPHA);
$detail = optional_param('detail', '', PARAM_TEXT);
$cmid = optional_param('cmid', 0, PARAM_INT);
if (!in_array($event, ['exportstart', 'exportdone', 'exporterror', 'exportcancel', 'exportempty', 'exportnoengine'], true)) {
    http_response_code(400);
    die();
}

error_log('block_ahotts_embhl client: user ' . $USER->id . ' cmid ' . $cmid . ' ' . $event . ': '
    . core_text::substr(preg_replace('/\s+/u', ' ', $detail), 0, 800)
    . ' [' . core_text::substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 160) . ']');
http_response_code(204);
