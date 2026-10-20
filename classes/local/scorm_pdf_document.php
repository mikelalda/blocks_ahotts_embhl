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
if (!class_exists('pdf')) {
    global $CFG;
    require_once($CFG->libdir . '/pdflib.php');
}

/**
 * The PDF of a SCORM course: Moodle's PDF with a running header and page numbers.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_pdf_document extends \pdf {

    /** @var string Course title, repeated at the top of every page but the cover. */
    public $coursetitle = '';

    /** @var int[] Accent colour, as [r, g, b]. */
    public $accent = [47, 93, 138];

    /** @var string Font family of the header and footer. */
    public $fontfamily = 'freesans';

    // @codingStandardsIgnoreStart TCPDF names these methods.
    public function Header() {
        if ($this->getPage() <= 1) {
            return;
        }
        $this->SetFont($this->fontfamily, '', 8);
        $this->SetTextColor(107, 115, 120);
        $this->SetXY($this->original_lMargin, 9);
        $this->Cell(0, 5, $this->coursetitle, 0, 0, 'L', false, '', 1);
        $this->SetDrawColor(213, 218, 221);
        $this->SetLineWidth(0.2);
        $this->Line($this->original_lMargin, 15, $this->getPageWidth() - $this->original_rMargin, 15);
    }

    public function Footer() {
        $this->SetFont($this->fontfamily, 'B', 8);
        $this->SetTextColorArray($this->accent);
        $this->SetY(-13);
        $this->Cell(0, 5, $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
    // @codingStandardsIgnoreEnd
}
