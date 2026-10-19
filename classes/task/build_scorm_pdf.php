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
namespace block_ahotts_embhl\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Build the PDF of a SCORM activity for its current package revision.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_scorm_pdf extends \core\task\adhoc_task {

    public function execute() {
        $data = $this->get_custom_data();
        $cm = get_coursemodule_from_id('scorm', (int) ($data->cmid ?? 0), 0, false, IGNORE_MISSING);
        if (!$cm || !\block_ahotts_embhl\local\scorm_pdf_file::enabled()) {
            return;
        }
        $file = \block_ahotts_embhl\local\scorm_pdf_file::get($cm);
        mtrace($file ? 'Built ' . $file->get_filename() : 'No readable text in SCORM cmid ' . $cm->id);
    }
}
