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
 * Tests for building a PDF from the files of a SCORM package.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_ahotts_embhl\local\scorm_pdf
 */

namespace block_ahotts_embhl;

use block_ahotts_embhl\local\scorm_pdf;

class scorm_pdf_test extends \advanced_testcase {

    /**
     * Converter for one of the fixture packages.
     *
     * @param string $name
     * @return scorm_pdf
     */
    private function fixture(string $name): scorm_pdf {
        return scorm_pdf::from_directory(__DIR__ . '/fixtures/scorm/' . $name, make_request_directory());
    }

    /**
     * Everything printed, as one string.
     *
     * @param array $document
     * @return string
     */
    private function html(array $document): string {
        return implode("\n", array_map(function(array $section): string {
            return $section['title'] . "\n" . $section['html'];
        }, $document['sections']));
    }

    public function test_rise_lessons_are_written_in_order_without_template_text_or_answers(): void {
        $converter = $this->fixture('rise');
        $this->assertSame('rise', $converter->kind());
        $document = $converter->document('fallback');
        $html = $this->html($document);

        $this->assertSame('Curso Rise', $document['title']);
        $this->assertSame(['Primera parte', 'Lección uno', 'Autoevaluación'], array_column($document['sections'], 'title'));
        foreach (['<strong>visible</strong>', 'Subtítulo real', 'Nota destacada', 'Pestaña A', 'Contenido oculto B',
                '<ol><li>Uno</li><li>Dos</li></ol>', 'Pie de foto', '<img ', '¿Pregunta?', 'Respuesta buena',
                'Respuesta mala', 'Completa la frase'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        // TCPDF prefixes a bare absolute path with DOCUMENT_ROOT in a web request and loses the image.
        $this->assertStringContainsString('<img src="file:///', $html);
        // Text Rise keeps but does not show, feedback and the answers of open questions.
        foreach (['>Heading<', 'present moment', 'Principios para la accesibilidad', 'Feedback secreto', 'solución',
                'correct'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }
    }

    public function test_rise_course_inline_in_the_player_page(): void {
        $converter = $this->fixture('rise_inline');
        $this->assertSame('rise', $converter->kind());
        $document = $converter->document('fallback');

        $this->assertSame('Rise en línea', $document['title']);
        $this->assertSame(['Lección única'], array_column($document['sections'], 'title'));
        $this->assertStringContainsString('Datos dentro de index.html', $this->html($document));
    }

    public function test_storyline_slides_follow_the_story_and_skip_feedback_layers(): void {
        $converter = $this->fixture('storyline');
        $this->assertSame('storyline', $converter->kind());
        $document = $converter->document('Curso Storyline');
        $html = $this->html($document);

        $this->assertSame(['Bienvenida', 'Pregunta'], array_column($document['sections'], 'title'));
        $this->assertStringContainsString('Hola, it\'s the intro', $html);
        $this->assertStringContainsString('Línea con &quot;comillas&quot;', $html);
        $this->assertStringContainsString('¿Qué es un hacker?', $html);
        $this->assertStringNotContainsString('Correcto', $html);
    }

    public function test_other_packages_follow_the_manifest(): void {
        $converter = $this->fixture('html');
        $this->assertSame('html', $converter->kind());
        $document = $converter->document('fallback');
        $html = $this->html($document);

        $this->assertSame('Curso HTML', $document['title']);
        $this->assertSame(['Segunda página', 'Primera página'], array_column($document['sections'], 'title'));
        $this->assertStringContainsString('Texto de la página uno.', $html);
        $this->assertStringContainsString('<p><strong>Tabla</strong></p><table', $html);
        $this->assertStringContainsString('<td colspan="2">B</td>', $html);
        foreach (['Menú', 'Respuesta escondida', 'alert', '<img'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    public function test_render_produces_a_pdf(): void {
        $document = $this->fixture('rise')->document('fallback');
        $this->assertFalse(scorm_pdf::is_empty($document));
        $this->assertStringStartsWith('%PDF-', scorm_pdf::render($document, make_request_directory()));
    }

    public function test_resolve_stays_inside_the_package(): void {
        $this->assertSame('scormcontent/assets/a b.png', scorm_pdf::resolve('scormcontent/', 'assets/a%20b.png?v=2'));
        $this->assertSame('img/x.png', scorm_pdf::resolve('pages/', '../img/x.png'));
        $this->assertNull(scorm_pdf::resolve('', '../../etc/passwd'));
        $this->assertNull(scorm_pdf::resolve('', 'https://example.org/x.png'));
        $this->assertNull(scorm_pdf::resolve('', 'data:image/png;base64,AAAA'));
    }
}
