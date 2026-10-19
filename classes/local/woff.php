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

namespace block_ahotts_embhl\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Unwrap a WOFF 1.0 web font into the TrueType/OpenType font it holds.
 *
 * SCORM packages ship their fonts as WOFF, which TCPDF cannot read. A WOFF
 * file is the same font with each table compressed on its own, so rebuilding
 * the sfnt file is a matter of inflating the tables and writing them back.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class woff {

    /**
     * The sfnt font inside a WOFF file.
     *
     * @param string $woff
     * @return string|null Null when this is not a valid WOFF 1.0 file.
     */
    public static function to_sfnt(string $woff): ?string {
        if (strlen($woff) < 44 || substr($woff, 0, 4) !== 'wOFF') {
            return null;
        }
        $header = unpack('Nflavor/Nlength/nnumtables', substr($woff, 4, 10));
        $count = $header['numtables'];
        if ($count < 1 || strlen($woff) < 44 + 20 * $count) {
            return null;
        }

        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = unpack('a4tag/Noffset/Ncomplength/Noriglength/Nchecksum', substr($woff, 44 + 20 * $i, 20));
            $data = substr($woff, $entry['offset'], $entry['complength']);
            if (strlen($data) !== $entry['complength']) {
                return null;
            }
            if ($entry['complength'] < $entry['origlength']) {
                $data = @gzuncompress($data);
                if ($data === false || strlen($data) !== $entry['origlength']) {
                    return null;
                }
            }
            $tables[] = ['tag' => $entry['tag'], 'checksum' => $entry['checksum'], 'data' => $data];
        }

        // Offset table, as the OpenType specification computes it.
        $power = 1;
        $selector = 0;
        while ($power * 2 <= $count) {
            $power *= 2;
            $selector++;
        }
        $out = pack('Nnnnn', $header['flavor'], $count, $power * 16, $selector, $count * 16 - $power * 16);

        $offset = 12 + 16 * $count;
        $records = '';
        $body = '';
        foreach ($tables as $table) {
            $length = strlen($table['data']);
            $records .= pack('a4NNN', $table['tag'], $table['checksum'], $offset, $length);
            $padded = $table['data'] . str_repeat("\0", (4 - $length % 4) % 4);
            $body .= $padded;
            $offset += strlen($padded);
        }
        return $out . $records . $body;
    }
}
