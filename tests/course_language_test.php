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

use block_ahotts_embhl\local\course_language;

#[\PHPUnit\Framework\Attributes\CoversClass(\block_ahotts_embhl\local\course_language::class)]
/**
 * Regression tests for the offline detection of a course's language.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 BIRT
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_ahotts_embhl\local\course_language
 */
final class course_language_test extends \basic_testcase {
    /**
     * Texts of real courses and the language they must be read in ('' when undecided).
     *
     * @return array
     */
    public static function infer_provider(): array {
        return [
            'Reported course 724' => [['Desarrollo de entornos interactivos multidispositivo copia 1'], 'es'],
            'Reported SCORM titles' => [
                [
                    'DEIM06.3 Realidad Virtual (VR)',
                    'DEIM07.1 Entornos de simulación de videojuegos',
                    'DEIM07.2 Máquinas virtuales',
                ],
                'es',
            ],
            'Basque course' => [['Bulegotika eta informazioaren prozesua'], 'eu'],
            'English page' => [['IT01 Where should I start?'], 'en'],
            'Case and accents' => [['GESTIÓN DE LA DOCUMENTACIÓN'], 'es'],
            'Uninformative code' => [['DEIM724'], ''],
            'Shared terminology' => [['Software hardware SCORM HTML'], ''],
            'Bilingual mlang' => [
                ['{mlang es}Desarrollo de aplicaciones{mlang}{mlang eu}Aplikazioen garapena eta programazioa{mlang}'],
                '',
            ],
            'Bilingual HTML' => [
                [
                    '<span lang="es" class="multilang">Desarrollo de aplicaciones</span>'
                    . '<span lang="eu" class="multilang">Aplikazioen garapena</span>',
                ],
                '',
            ],
            'Mixed evidence' => [['Desarrollo aplicaciones programación', 'development applications programming'], ''],
            'Repeated ambiguous title' => [array_fill(0, 100, 'de'), ''],
            'Multilang boilerplate plus Basque' => [
                ['Bulegotika eta informazioaren prozesua', '{mlang es}Desarrollo de aplicaciones y sistemas{mlang}'],
                'eu',
            ],
        ];
    }

    /**
     * The detected language matches the one a person reading the course would pick.
     *
     * @dataProvider infer_provider
     * @param string[] $texts
     * @param string $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('infer_provider')]
    public function test_infer(array $texts, string $expected): void {
        $result = course_language::infer($texts);
        $this->assertSame($expected, $result['language'], json_encode($result));
    }
}
