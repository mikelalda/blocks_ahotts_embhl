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

namespace block_ahotts_embhl\event;

/**
 * Something done with the Listen block on a page: read aloud or downloaded.
 *
 * The page is the event context. Details in "other":
 * - language: code of the reading language, such as eu (optional).
 * - source: where a PDF came from (optional, PDF downloads only).
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class usage_base extends \core\event\base {
    /**
     * Read events by a participant, with no object of their own.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Link to the page where it happened.
     *
     * @return \moodle_url|null
     */
    public function get_url() {
        return $this->context->get_url();
    }

    /**
     * Nothing in "other" refers to other records.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['language' => self::NOT_MAPPED, 'source' => self::NOT_MAPPED];
    }
}
