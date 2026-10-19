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
     * Build the PDF of a SCORM activity in the background once its package is
     * saved, so that the first learner does not wait for it.
     *
     * @param \core\event\base $event course_module_created or course_module_updated.
     */
    public static function course_module_changed(\core\event\base $event): void {
        if (($event->other['modulename'] ?? '') !== 'scorm' || !local\scorm_pdf_file::enabled()) {
            return;
        }
        $task = new task\build_scorm_pdf();
        $task->set_custom_data(['cmid' => (int) $event->objectid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
