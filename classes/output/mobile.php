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
 * Mobile output functions.
 *
 * @package block_ahotts_embhl
 * @copyright 2024 ReadSpeaker
 * @author     Nikolina Milioni
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ahotts_embhl\output;

/**
 * Mobile app handlers: the Listen button shown by the Moodle app.
 *
 * @package    block_ahotts_embhl
 * @copyright  2024 ReadSpeaker
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /**
     * Function for fetching the Javascript content for the mobile app and
     * adding the window object to the JS file.
     *
     * @return array An associative array containing the JavaScript file content as a string.
     */
    public static function rs_init() {
        return [
            'javascript' => '',
        ];
    }

    /**
     * Return the Listen button.
     *
     * @param array $args Arguments sent by the app.
     * @return array An associative array with the web service response: template.
     */
    public static function mobile_block_view(array $args): array {
        global $OUTPUT, $PAGE;

        // Prepare Listen button data.
        $configlanguage = get_config('block_ahotts_embhl', 'lang');
        $customparams = get_config('block_ahotts_embhl', 'customparams');
        $encodedurl = $PAGE->url->out(false);
        $listentitletext = get_string('listen_titletext', 'block_ahotts_embhl');
        $listentext = get_string('listentext', 'block_ahotts_embhl');

        $href = \block_ahotts_embhl\local\api::build_href(
            $configlanguage,
            $encodedurl,
            get_config('block_ahotts_embhl', 'readid'),
            $customparams
        );

        // Populate the mustache template with data.
        $data = [
            'listen_text_title' => $listentitletext,
            'listen_text' => $listentext,
            'href' => $href,
        ];

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('block_ahotts_embhl/mobile_block_view', $data),
                ],
            ],
            'javascript' => '',
        ];
    }
}
