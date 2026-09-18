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
 * Upgrade steps for the aHoTTS listen block.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the block.
 *
 * @param int $oldversion The version the site is coming from.
 * @return bool
 */
function xmldb_block_ahotts_embhl_upgrade($oldversion) {

    if ($oldversion < 2026091802) {
        // The phonemizer this block first shipped with is an eSpeak NG build
        // carrying English only, so it refuses every Basque word and the itzune
        // voices produce no sound. Sites still pointing at it are moved to a
        // full build; a phonemizer an administrator chose themselves is left
        // alone.
        $current = (string) get_config('block_ahotts_embhl', 'piperphonemizer');
        $englishonly = 'https://cdn.jsdelivr.net/npm/phonemizer@1.2.1/dist/phonemizer.js';

        if ($current === '' || $current === $englishonly) {
            set_config(
                'piperphonemizer',
                \block_ahotts_embhl\local\languages::DEFAULT_PHONEMIZER,
                'block_ahotts_embhl'
            );
        }

        if (get_config('block_ahotts_embhl', 'piperphonemizerwasm') === false) {
            set_config(
                'piperphonemizerwasm',
                \block_ahotts_embhl\local\languages::DEFAULT_PHONEMIZER_WASM,
                'block_ahotts_embhl'
            );
        }

        upgrade_block_savepoint(true, 2026091802, 'ahotts_embhl');
    }

    if ($oldversion < 2026091803) {
        // The local voices were Basque only, with one default voice setting for
        // the whole block. They now cover Spanish and English too, so that
        // setting becomes one per language and the old value is carried over.
        $chosen = (string) get_config('block_ahotts_embhl', 'pipervoice');
        if (isset(\block_ahotts_embhl\local\languages::PIPER_VOICES['eu'][$chosen])) {
            set_config('pipervoice_eu', $chosen, 'block_ahotts_embhl');
        }
        unset_config('pipervoice', 'block_ahotts_embhl');

        upgrade_block_savepoint(true, 2026091803, 'ahotts_embhl');
    }

    return true;
}
