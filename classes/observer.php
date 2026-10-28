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

defined('MOODLE_INTERNAL') || die();

/**
 * Event observers.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Once a SCORM package is saved: point a hosted Rise 360 package at this
     * site's reverse proxy, so it can be read, and build the activity's PDF in
     * the background, so that the first learner does not wait for it.
     *
     * @param \core\event\base $event course_module_created or course_module_updated.
     */
    public static function course_module_changed(\core\event\base $event): void {
        if (($event->other['modulename'] ?? '') !== 'scorm') {
            return;
        }
        try {
            local\rise_proxy::package_saved((int) $event->objectid);
        } catch (\Throwable $e) {
            // The package still works from Articulate's hosting; it is just not readable.
            debugging('Rise proxy: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        if (!local\scorm_pdf_file::enabled()) {
            return;
        }
        $task = new task\build_scorm_pdf();
        $task->set_custom_data(['cmid' => (int) $event->objectid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
