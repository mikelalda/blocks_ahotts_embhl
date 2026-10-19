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
 * Turn the files of a published SCORM package into a printable PDF.
 *
 * The package is read from its files, never run, so building the PDF cannot
 * move a learner through the course, answer a question or change any track
 * data. Three kinds of package are understood:
 *
 *  - Rise 360: the whole course is a base64 JSON document in
 *    scormcontent/locales/<lang>.js. Every lesson and block is written out,
 *    with accordions, tabs and flashcards expanded. Questions are listed with
 *    their options but never with the correct answer or the feedback.
 *  - Storyline 360: the text of every slide is read from html5/data/js, in the
 *    order of the story. Feedback layers are left out. Storyline blocks
 *    embedded in a Rise course are read the same way.
 *  - Anything else (eXeLearning, plain HTML): the pages listed by
 *    imsmanifest.xml, in the order of its organisation.
 *
 * Layout, animations and interactions cannot be reproduced on paper; the PDF
 * holds the text and the images of the course.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_pdf {

    /** @var int Widest image, in CSS pixels, that still fits an A4 page. */
    const IMAGE_MAX_WIDTH = 640;

    /** @var int Tallest image, in CSS pixels, so that it never takes a page of its own. */
    const IMAGE_MAX_HEIGHT = 520;

    /** @var string[] Rise text variants, and whether they show their heading and their paragraph. */
    const RISE_TEXT_VARIANTS = [
        'paragraph' => [false, true],
        'heading' => [true, false],
        'subheading' => [true, false],
        'heading paragraph' => [true, true],
        'subheading paragraph' => [true, true],
    ];

    /** @var int Stop embedding images past this many, to bound the file size. */
    const MAX_IMAGES = 400;

    /** @var string[] Image types TCPDF can embed. */
    const IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif'];

    /** @var string Accent colour of packages that do not name one. */
    const DEFAULT_ACCENT = '#2f5d8a';

    /** @var string Colour of body text. */
    const TEXT_COLOUR = '#313537';

    /** @var string Colour of captions and other secondary text. */
    const MUTED_COLOUR = '#6b7378';

    /** @var string Colour of box borders. */
    const BORDER_COLOUR = '#d5dadd';

    /** @var string[] Rise question types whose options can be shown without giving the answer away. */
    const CHOICE_QUESTIONS = ['MULTIPLE_CHOICE', 'MULTIPLE_RESPONSE'];

    /** @var string Storyline layers that only hold feedback. */
    const FEEDBACK_LAYER = '/correct|incorrect|try again|feedback|retroalimentaci|correcto|incorrecto|zuzena|okerra|berriro/i';

    /** @var string[] Elements kept as they are. */
    const KEEP = ['p', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'u', 'sub', 'sup', 'blockquote',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'h3', 'h4', 'h5', 'pre', 'code'];

    /** @var string[] Elements dropped with everything inside them. */
    const DROP = ['script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed', 'video', 'audio',
        'svg', 'canvas', 'button', 'input', 'select', 'textarea', 'form', 'nav', 'head', 'title', 'meta', 'link',
        'colgroup', 'col'];

    /** @var string[] Elements that hold paragraphs of their own. */
    const BLOCKS = ['p', 'ul', 'ol', 'table', 'h3', 'h4', 'h5', 'blockquote', 'pre', 'img'];

    /** @var callable Read one package file: fn(string $path): ?string. */
    private $read;

    /** @var array Package paths, relative to its root, as keys. */
    private $paths;

    /** @var string Directory where images are copied for TCPDF. */
    private $tempdir;

    /** @var int Images embedded so far. */
    private $images = 0;

    /** @var string Accent colour of the package being read. */
    private $accent = self::DEFAULT_ACCENT;

    /**
     * @param callable $read fn(string $path): ?string, paths relative to the package root.
     * @param string[] $paths Every file of the package.
     * @param string $tempdir Writable directory for the images; '' to leave images out.
     */
    public function __construct(callable $read, array $paths, string $tempdir) {
        $this->read = $read;
        $this->paths = array_fill_keys($paths, true);
        $this->tempdir = rtrim($tempdir, '/');
    }

    /**
     * Converter for the package unpacked by mod_scorm in a module context.
     *
     * @param \context_module $context
     * @param string $tempdir
     * @return self
     */
    public static function from_context(\context_module $context, string $tempdir): self {
        $files = [];
        $area = get_file_storage()->get_area_files($context->id, 'mod_scorm', 'content', 0, 'filepath, filename', false);
        foreach ($area as $file) {
            $files[ltrim($file->get_filepath(), '/') . $file->get_filename()] = $file;
        }
        return new self(function(string $path) use ($files): ?string {
            return isset($files[$path]) ? $files[$path]->get_content() : null;
        }, array_keys($files), $tempdir);
    }

    /**
     * Converter for a package unpacked in a local directory (tests, CLI).
     *
     * @param string $directory
     * @param string $tempdir
     * @return self
     */
    public static function from_directory(string $directory, string $tempdir): self {
        $directory = rtrim($directory, '/') . '/';
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,
            \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $paths[] = substr($file->getPathname(), strlen($directory));
            }
        }
        sort($paths);
        return new self(function(string $path) use ($directory): ?string {
            $content = @file_get_contents($directory . $path);
            return $content === false ? null : $content;
        }, $paths, $tempdir);
    }

    /**
     * Which kind of package this is.
     *
     * @return string 'rise', 'storyline' or 'html'.
     */
    public function kind(): string {
        if ($this->rise_base() !== null) {
            return 'rise';
        }
        if ($this->has('html5/data/js/data.js')) {
            return 'storyline';
        }
        return 'html';
    }

    /**
     * The course as a list of sections ready for the PDF.
     *
     * @param string $fallbacktitle Title used when the package names none.
     * @return array ['title' => string, 'sections' => [['title' => string, 'html' => string, 'level' => int]]]
     */
    public function document(string $fallbacktitle): array {
        $this->images = 0;
        $this->accent = self::DEFAULT_ACCENT;
        switch ($this->kind()) {
            case 'rise':
                $document = $this->rise_document();
                break;
            case 'storyline':
                $document = ['title' => '', 'sections' => $this->storyline_sections('')];
                break;
            default:
                $document = $this->manifest_document();
        }
        if ($document === null) {
            $document = $this->manifest_document();
        }
        $document['title'] = trim($document['title']) !== '' ? $document['title'] : $fallbacktitle;
        $document['theme'] = ($document['theme'] ?? []) + ['accent' => self::DEFAULT_ACCENT, 'fonts' => [], 'cover' => ''];
        foreach ($document['sections'] as $index => $section) {
            // A one-page package often repeats the course title as its page title.
            if ($section['title'] === $document['title']) {
                $document['sections'][$index]['title'] = '';
            }
        }
        $document['sections'] = array_values(array_filter($document['sections'], function(array $section): bool {
            return trim($section['title']) !== '' || self::has_content($section['html']);
        }));
        return $document;
    }

    /**
     * Whether the document has anything worth printing.
     *
     * @param array $document As returned by document().
     * @return bool
     */
    public static function is_empty(array $document): bool {
        foreach ($document['sections'] as $section) {
            if (self::has_content($section['html'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * The document as plain paragraphs, in reading order, for speech.
     *
     * @param array $document As returned by document().
     * @return string[]
     */
    public static function paragraphs(array $document): array {
        $out = [trim($document['title'])];
        foreach ($document['sections'] as $section) {
            $out[] = trim($section['title']);
            // Nothing inside scripts or styles is text; every block element ends a paragraph.
            $html = preg_replace('#<(script|style|noscript|template)\b.*?</\1\s*>#is', ' ', $section['html']);
            $html = preg_replace('#<br\s*/?>|</(p|li|h[1-6]|td|th|tr|blockquote|pre|div)>#i', "\n", $html);
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            foreach (preg_split('/\n+/', $text) as $line) {
                $out[] = trim(preg_replace('/\s+/u', ' ', $line));
            }
        }
        return array_values(array_filter($out, function(string $line): bool {
            return $line !== '';
        }));
    }

    /**
     * Write the document into a PDF and return its bytes.
     *
     * @param array $document As returned by document().
     * @param string $fontdir Writable directory where the course typefaces are converted and kept.
     * @return string
     */
    public static function render(array $document, string $fontdir = ''): string {
        $theme = $document['theme'] ?? ['accent' => self::DEFAULT_ACCENT, 'fonts' => [], 'cover' => ''];
        $accent = $theme['accent'];
        $pdf = new scorm_pdf_document();
        // The images are copied to a temporary directory and referenced with
        // file:// (see image()). A bare absolute path would not do: in a web
        // request TCPDF prefixes it with DOCUMENT_ROOT and the image is lost.
        $pdf->setAllowLocalFiles(true);
        $families = $fontdir !== '' ? self::register_fonts($pdf, $theme['fonts'], $fontdir) : [];
        $body = $families['body'] ?? 'freesans';
        $heading = $families['heading'] ?? $body;

        $pdf->coursetitle = $document['title'];
        $pdf->accent = self::rgb($accent);
        $pdf->fontfamily = $body;
        $pdf->SetTitle($document['title']);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(20, 22, 20);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->setHtmlVSpace([
            'p' => [['h' => 0, 'n' => 0], ['h' => 2.2, 'n' => 1]],
            'h3' => [['h' => 3, 'n' => 1], ['h' => 1.5, 'n' => 1]],
            'h4' => [['h' => 2, 'n' => 1], ['h' => 1, 'n' => 1]],
        ]);
        $pdf->SetFont($body, '', 10.5);
        $pdf->SetTextColorArray(self::rgb(self::TEXT_COLOUR));

        // Cover: image, title, and the lessons that follow.
        $pdf->AddPage();
        $cover = $theme['cover'] !== '' ? $theme['cover'] : '';
        $pdf->writeHTML($cover . '<div style="font-family:' . $heading . ';font-size:26pt;font-weight:bold;color:'
            . self::TEXT_COLOUR . ';">' . self::esc($document['title']) . '</div>', true, false, true, false, '');
        self::rule($pdf, $accent, 40);
        $contents = '';
        foreach ($document['sections'] as $section) {
            if ($section['level'] !== 1 || trim($section['title']) === '') {
                continue;
            }
            $contents .= isset($section['kicker'])
                ? '<tr><td width="12%" style="color:' . $accent . ';font-weight:bold;">' . self::esc(preg_replace('/^\D+/u', '',
                    $section['kicker'])) . '</td><td width="88%">' . self::esc($section['title']) . '</td></tr>'
                : '<tr><td colspan="2" style="font-weight:bold;color:' . self::MUTED_COLOUR . ';">'
                    . self::esc(mb_strtoupper($section['title'], 'UTF-8')) . '</td></tr>';
        }
        if ($contents !== '') {
            $pdf->writeHTML('<table cellpadding="3">' . $contents . '</table>', true, false, true, false, '');
        }

        foreach ($document['sections'] as $index => $section) {
            $previous = $document['sections'][$index - 1] ?? null;
            // Every lesson starts a page, unless it follows the title of its section.
            $aftertitle = $previous && $previous['level'] === 1 && !self::has_content($previous['html']);
            if ($section['level'] === 1 && !$aftertitle) {
                $pdf->AddPage();
            }
            $head = '';
            if (isset($section['kicker'])) {
                $head .= '<div style="font-size:8.5pt;font-weight:bold;color:' . $accent . ';">'
                    . self::esc(mb_strtoupper($section['kicker'], 'UTF-8')) . '</div>';
            }
            if (trim($section['title']) !== '') {
                $size = $section['level'] > 1 ? '14pt' : (self::has_content($section['html']) ? '20pt' : '24pt');
                $head .= '<div style="font-family:' . $heading . ';font-size:' . $size . ';font-weight:bold;">'
                    . self::esc($section['title']) . '</div>';
            }
            if ($head !== '') {
                $pdf->writeHTML($head, true, false, true, false, '');
                if ($section['level'] === 1) {
                    self::rule($pdf, $accent, 25);
                }
            }
            // One call per section keeps TCPDF's memory use bounded on long courses.
            $pdf->writeHTML(self::style_headings($section['html'], $heading), true, false, true, false, '');
        }
        $pdf->lastPage();
        return $pdf->Output('', 'S');
    }

    /**
     * Give headings inside the content the course's heading typeface and sizes.
     *
     * @param string $html
     * @param string $family
     * @return string
     */
    private static function style_headings(string $html, string $family): string {
        $sizes = ['h3' => '14pt', 'h4' => '12pt', 'h5' => '11pt'];
        return preg_replace_callback('#<(h[345])>#', function(array $m) use ($sizes, $family): string {
            return '<' . $m[1] . ' style="font-family:' . $family . ';font-size:' . $sizes[$m[1]] . ';">';
        }, $html);
    }

    /**
     * Draw a short accent rule under a heading.
     *
     * @param \TCPDF $pdf
     * @param string $accent
     * @param float $width In mm.
     */
    private static function rule(\TCPDF $pdf, string $accent, float $width): void {
        $y = $pdf->GetY() + 1;
        $pdf->SetDrawColorArray(self::rgb($accent));
        $pdf->SetLineWidth(0.9);
        $pdf->Line($pdf->getMargins()['left'], $y, $pdf->getMargins()['left'] + $width, $y);
        $pdf->SetY($y + 5);
    }

    /**
     * Turn the course typefaces into TCPDF fonts.
     *
     * @param \TCPDF $pdf
     * @param array $fonts [role => [style => sfnt bytes]]
     * @param string $fontdir
     * @return array [role => family name] for the families that could be added.
     */
    private static function register_fonts(\TCPDF $pdf, array $fonts, string $fontdir): array {
        $families = [];
        $suffix = ['' => '', 'B' => 'b', 'I' => 'i', 'BI' => 'bi'];
        foreach ($fonts as $role => $styles) {
            // Named after the regular face, so a typeface is converted once for all courses.
            $family = 'scorm' . substr(sha1($styles['']), 0, 10);
            try {
                foreach ($styles as $style => $sfnt) {
                    $name = $family . $suffix[$style];
                    if (!file_exists("{$fontdir}/{$name}.php")) {
                        file_put_contents("{$fontdir}/{$name}.ttf", $sfnt);
                        $added = \TCPDF_FONTS::addTTFfont("{$fontdir}/{$name}.ttf", 'TrueTypeUnicode', '', 32, $fontdir . '/');
                        if ($added !== $name) {
                            throw new \RuntimeException('Unexpected font name');
                        }
                    }
                    $pdf->AddFont($family, $style, "{$fontdir}/{$name}.php");
                }
                $families[$role] = $family;
            } catch (\Throwable $e) {
                continue; // Keep the built-in font rather than fail the whole PDF.
            }
        }
        return $families;
    }

    /**
     * '#rrggbb' as [r, g, b].
     *
     * @param string $hex
     * @return int[]
     */
    private static function rgb(string $hex): array {
        return sscanf($hex, '#%02x%02x%02x');
    }

    // Rise 360.

    /**
     * Directory of the Rise player (scormcontent/), or null when this is not a Rise package.
     *
     * @return string|null
     */
    private function rise_base(): ?string {
        foreach (array_keys($this->paths) as $path) {
            if (preg_match('#^((?:.*/)?scormcontent/)(?:locales/[^/]+\.js|RISE360\.js|lib/rise/[^/]+\.js)$#', $path, $match)
                    && $this->has($match[1] . 'index.html')) {
                return $match[1];
            }
        }
        return null;
    }

    /**
     * Find and decode the course data of a Rise package.
     *
     * Rise has shipped it in several ways: as JSONP in locales/<lang>.js, or
     * inline in index.html (deserialize("...") or a plain assignment), always
     * as base64 JSON. Every script of the player that is not a library is
     * searched, the default language first.
     *
     * @param string $base Directory of scormcontent/.
     * @return array|null
     */
    private function rise_data(string $base): ?array {
        $index = (string) $this->read($base . 'index.html');
        $sources = [];
        if (preg_match('/"default"\s*:\s*"([^"]+)"/', $index, $match)) {
            $sources[] = $base . 'locales/' . $match[1] . '.js';
        }
        foreach (array_keys($this->paths) as $path) {
            if (strpos($path, $base) === 0 && preg_match('#^(locales/[^/]+\.js|[^/]+\.(js|json|html))$#',
                    substr($path, strlen($base)))) {
                $sources[] = $path;
            }
        }
        foreach (array_unique($sources) as $path) {
            $content = $path === $base . 'index.html' ? $index : (string) $this->read($path);
            if ($content === '') {
                continue;
            }
            $data = self::rise_course($content);
            if ($data !== null) {
                return $data;
            }
        }
        return null;
    }

    /**
     * The Rise course encoded in a script, if there is one.
     *
     * @param string $content
     * @return array|null ['course' => the course with its lessons, 'fonts' => [...], 'labelSet' => [...]].
     */
    public static function rise_course(string $content): ?array {
        if (!preg_match_all('/(["\'])([A-Za-z0-9+\/_-]{100,}={0,2})\1/', $content, $matches)) {
            return null;
        }
        // The longest string is the course; try the others too, but after it.
        $blobs = $matches[2];
        usort($blobs, function(string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });
        foreach ($blobs as $blob) {
            $json = base64_decode(strtr($blob, '-_', '+/'), true);
            if ($json === false || ($json[0] ?? '') !== '{') {
                continue;
            }
            $data = json_decode($json, true);
            $course = is_array($data) ? ($data['course'] ?? $data) : null;
            if (is_array($course) && isset($course['lessons']) && is_array($course['lessons'])) {
                return ['course' => $course] + $data;
            }
        }
        return null;
    }

    /**
     * Read the course data of a Rise package.
     *
     * @return array|null The document, or null when the data cannot be decoded.
     */
    private function rise_document(): ?array {
        $base = $this->rise_base();
        $data = $base !== null ? $this->rise_data($base) : null;
        if ($data === null) {
            return null;
        }
        $course = $data['course'];
        $theme = $this->rise_theme($data, $base);
        $this->accent = $theme['accent'];
        $labels = $data['labelSet']['labels'] ?? [];
        $total = count(array_filter($course['lessons'], function($lesson): bool {
            return is_array($lesson) && empty($lesson['deleted']) && ($lesson['type'] ?? '') !== 'section';
        }));
        $number = 0;

        // Storyline blocks are published as nested stories inside the package.
        $stories = [];
        foreach (array_keys($this->paths) as $path) {
            if (strpos($path, $base) === 0 && preg_match('#^(.*/)html5/data/js/data\.js$#', $path, $story)) {
                $stories[$story[1]] = false;
            }
        }

        $sections = [];
        if (!empty($course['description'])) {
            $sections[] = ['title' => '', 'html' => $this->clean_html($course['description'], $base), 'level' => 1];
        }
        foreach ($course['lessons'] ?? [] as $lesson) {
            if (!empty($lesson['deleted'])) {
                continue;
            }
            $title = $this->text($lesson['title'] ?? '');
            if (($lesson['type'] ?? '') === 'section') {
                $sections[] = ['title' => $title, 'html' => '', 'level' => 1];
                continue;
            }
            $html = !empty($lesson['description']) ? $this->clean_html($lesson['description'], $base) : '';
            foreach ($lesson['items'] ?? [] as $block) {
                $html .= $this->rise_block($block, $base, $stories);
            }
            // "Lesson 3 of 8", in the words of the course.
            $number++;
            $kicker = trim(($labels['lessonName'] ?? 'Lesson') . ' ' . $number . ' '
                . ($labels['lessonPreposition'] ?? 'of') . ' ' . $total);
            $sections[] = ['title' => $title, 'html' => $html, 'level' => 1, 'kicker' => $kicker];
        }
        // Stories no block pointed to still belong to the course.
        foreach ($stories as $story => $used) {
            if (!$used) {
                $sections = array_merge($sections, $this->storyline_sections($story, 2));
            }
        }
        return ['title' => $this->text($course['title'] ?? ''), 'sections' => $sections, 'theme' => $theme];
    }

    /**
     * Colours, typefaces and cover of a Rise course.
     *
     * @param array $data As returned by rise_course().
     * @param string $base Directory of scormcontent/.
     * @return array ['accent' => '#rrggbb', 'fonts' => [role => [style => sfnt bytes]], 'cover' => HTML]
     */
    private function rise_theme(array $data, string $base): array {
        $course = $data['course'];
        $settings = is_array($course['theme'] ?? null) ? $course['theme'] : [];
        $accent = (string) ($settings['colorAccent'] ?? $course['color'] ?? '');
        $theme = [
            'accent' => preg_match('/^#[0-9a-f]{6}$/i', $accent) ? $accent : self::DEFAULT_ACCENT,
            'fonts' => [],
            'cover' => '',
        ];

        // The body and heading typefaces, from the WOFF files the player ships.
        $typefaces = [
            'body' => $settings['bodyTypefaceId'] ?? $course['bodyTypefaceId'] ?? '',
            'heading' => $settings['headingTypefaceId'] ?? $course['headingTypefaceId'] ?? '',
        ];
        foreach ($typefaces as $role => $id) {
            foreach (is_array($data['fonts'] ?? null) ? $data['fonts'] : [] as $font) {
                if (!is_array($font) || ($font['id'] ?? '') !== $id || empty($font['key'])) {
                    continue;
                }
                $weight = (int) ($font['weight'] ?? 400);
                $italic = ($font['style'] ?? 'normal') === 'italic';
                $style = ($weight >= 600 && $weight < 800 ? 'B' : ($weight >= 350 && $weight < 600 ? '' : null));
                if ($style === null) {
                    continue; // Light and black weights have no place in TCPDF's four styles.
                }
                $style .= $italic ? 'I' : '';
                $file = $base . 'lib/fonts/' . basename((string) $font['key']);
                $sfnt = $this->has($file) ? woff::to_sfnt((string) $this->read($file)) : null;
                if ($sfnt !== null && !isset($theme['fonts'][$role][$style])) {
                    $theme['fonts'][$role][$style] = $sfnt;
                }
            }
            if (!isset($theme['fonts'][$role][''])) {
                unset($theme['fonts'][$role]); // A family needs at least its regular face.
            }
        }

        if (is_array($course['coverImage'] ?? null)) {
            $theme['cover'] = $this->rise_media($course['coverImage'], $base);
        }
        return $theme;
    }

    /**
     * A box with a border, like Rise draws interactive blocks.
     *
     * @param string $inner
     * @return string
     */
    private static function box(string $inner): string {
        if (!self::has_content($inner)) {
            return '';
        }
        return '<table cellpadding="8" cellspacing="0"><tr><td style="border:0.6pt solid ' . self::BORDER_COLOUR . ';">'
            . $inner . '</td></tr></table><br />';
    }

    /**
     * A tinted box with an accent rule, like Rise draws statements and notes.
     *
     * @param string $inner
     * @return string
     */
    private function callout(string $inner): string {
        if (!self::has_content($inner)) {
            return '';
        }
        return '<table cellpadding="10" cellspacing="0"><tr><td style="background-color:' . self::tint($this->accent, 0.9)
            . ';border-left:2.5pt solid ' . $this->accent . ';">' . $inner . '</td></tr></table><br />';
    }

    /**
     * Mix a colour with white.
     *
     * @param string $hex '#rrggbb'
     * @param float $amount 0 keeps the colour, 1 gives white.
     * @return string
     */
    public static function tint(string $hex, float $amount): string {
        $rgb = sscanf($hex, '#%02x%02x%02x');
        return vsprintf('#%02x%02x%02x', array_map(function(int $channel) use ($amount): int {
            return (int) round($channel + (255 - $channel) * $amount);
        }, $rgb));
    }

    /**
     * Write one Rise block, or one item inside a block.
     *
     * @param array $node
     * @param string $base Directory of scormcontent/.
     * @param array $stories Nested Storyline directories => already written.
     * @return string HTML.
     */
    private function rise_block(array $node, string $base, array &$stories): string {
        $type = $node['type'] ?? '';
        $html = '';

        // A Storyline block: write the story it publishes.
        $encoded = json_encode($node);
        foreach ($stories as $story => $used) {
            $folder = basename(rtrim($story, '/'));
            if (!$used && $folder !== '' && strpos($encoded, $folder) !== false) {
                $stories[$story] = true;
                foreach ($this->storyline_sections($story, 2) as $section) {
                    $html .= ($section['title'] !== '' ? '<h4>' . self::esc($section['title']) . '</h4>' : '') . $section['html'];
                }
            }
        }

        $isquestion = in_array($type, self::CHOICE_QUESTIONS, true) || isset($node['answers']);
        if (isset($node['heading']) && $this->text($node['heading']) !== '') {
            $html .= '<h3>' . self::esc($this->text($node['heading'])) . '</h3>';
        }
        if (isset($node['title']) && is_string($node['title']) && $this->text($node['title']) !== '') {
            $html .= $isquestion
                ? '<p><b>' . self::esc($this->text($node['title'])) . '</b></p>'
                : '<h4>' . self::esc($this->text($node['title'])) . '</h4>';
        }
        $html .= $this->rise_media($node, $base);
        foreach (['paragraph', 'description', 'text'] as $key) {
            if (isset($node[$key]) && is_string($node[$key])) {
                $html .= $this->clean_html($node[$key], $base);
            }
        }
        if (isset($node['caption']) && is_string($node['caption'])) {
            $caption = $this->clean_html($node['caption'], $base);
            $html .= self::has_content($caption)
                ? '<div style="font-size:8.5pt;color:' . self::MUTED_COLOUR . ';text-align:center;">' . $caption . '</div>' : '';
        }
        foreach (['front', 'back'] as $side) {
            if (isset($node[$side]) && is_array($node[$side])) {
                $html .= $this->rise_block($node[$side], $base, $stories);
            }
        }
        if (!empty($node['label']) && !empty($node['destination']) && preg_match('#^https?://#i', $node['destination'])) {
            $html .= '<p><a href="' . self::esc($node['destination']) . '" style="color:' . $this->accent
                . ';font-weight:bold;text-decoration:none;">' . self::esc(mb_strtoupper($this->text($node['label']), 'UTF-8'))
                . ' &#8250;</a></p>';
        }

        // Show the options of a choice question, never which one is right.
        if (!empty($node['answers']) && is_array($node['answers']) && in_array($type, self::CHOICE_QUESTIONS, true)) {
            $options = '';
            foreach ($node['answers'] as $answer) {
                if (is_array($answer) && $this->text($answer['title'] ?? '') !== '') {
                    $options .= '<li>' . self::esc($this->text($answer['title'])) . '</li>';
                }
            }
            $html .= $options !== '' ? '<ul>' . $options . '</ul>' : '';
        }
        if ($isquestion) {
            $html = self::box($html);
        }

        if (!empty($node['items']) && is_array($node['items'])) {
            // Rise keeps template text in the fields a variant does not show: leave it out too.
            [$showheading, $showparagraph] = [true, true];
            $family = $node['family'] ?? '';
            if ($type === 'text' && $family === 'text' && isset(self::RISE_TEXT_VARIANTS[$node['variant'] ?? ''])) {
                [$showheading, $showparagraph] = self::RISE_TEXT_VARIANTS[$node['variant']];
            } else if ($type === 'text' && in_array($family, ['impact', 'statement', 'note'], true)) {
                $showheading = false;
            }
            $children = [];
            foreach ($node['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!$showheading) {
                    unset($item['heading']);
                }
                if (!$showparagraph) {
                    unset($item['paragraph']);
                }
                $children[] = $this->rise_block($item, $base, $stories);
            }
            if ($type === 'list') {
                $tag = ($node['variant'] ?? '') === 'numbered' ? 'ol' : 'ul';
                $items = array_filter(array_map(function(string $child): string {
                    return self::has_content($child) ? '<li>' . self::unwrap_paragraphs($child) . '</li>' : '';
                }, $children));
                $html .= $items ? "<{$tag}>" . implode('', $items) . "</{$tag}>" : '';
            } else if ($type === 'interactive' && $family !== 'buttons') {
                // Accordions, tabs, processes, flashcards...: every panel open, each in its box.
                $html .= implode('', array_map([self::class, 'box'], $children));
            } else {
                $html .= implode('', $children);
            }
        }
        $family = $node['family'] ?? '';
        if (in_array($family, ['impact', 'statement', 'note', 'quote'], true)) {
            $html = $this->callout($html);
        }
        return $html;
    }

    /**
     * The image of a Rise block or item, if it has one.
     *
     * @param array $node
     * @param string $base
     * @return string
     */
    private function rise_media(array $node, string $base): string {
        $image = $node['media']['image'] ?? null;
        if (!is_array($image)) {
            return '';
        }
        $candidates = [];
        foreach (['crushedKey', 'originalUrl', 'key'] as $key) {
            if (!empty($image[$key]) && is_string($image[$key])) {
                $candidates[] = $base . 'assets/' . $image[$key];
                $candidates[] = $base . 'assets/' . basename($image[$key]);
            }
        }
        foreach ($candidates as $path) {
            if ($this->has($path)) {
                return $this->image($path);
            }
        }
        return '';
    }

    // Storyline 360.

    /**
     * Every slide of a Storyline story, in the order of the story.
     *
     * @param string $base Directory holding html5/, '' for the package root.
     * @param int $level Heading level of the slides.
     * @return array Sections.
     */
    private function storyline_sections(string $base, int $level = 1): array {
        $dir = $base . 'html5/data/js/';
        $data = self::provided_data((string) $this->read($dir . 'data.js'));

        // Slides in story order: the ids as data.js mentions them, then any other slide file.
        $order = [];
        if ($data !== null) {
            array_walk_recursive($data, function($value, $key) use (&$order, $dir) {
                if (is_string($value) && preg_match('/^[A-Za-z0-9_]{3,}$/', $value) && $this->has($dir . $value . '.js')) {
                    $order[$value] = true;
                }
            });
        }
        foreach (array_keys($this->paths) as $path) {
            if (strpos($path, $dir) === 0 && preg_match('#^[^/]+\.js$#', substr($path, strlen($dir)))) {
                $order[substr($path, strlen($dir), -3)] = true;
            }
        }

        $sections = [];
        foreach (array_keys($order) as $id) {
            if (in_array($id, ['data', 'frame', 'paths', 'text', 'fonts'], true)) {
                continue;
            }
            $slide = self::provided_data((string) $this->read($dir . $id . '.js'), 'slide');
            if ($slide === null) {
                continue;
            }
            $paragraphs = [];
            $layers = $slide['slideLayers'] ?? [$slide];
            foreach (array_values($layers) as $index => $layer) {
                $name = (string) ($layer['title'] ?? $layer['name'] ?? '');
                if ($index > 0 && preg_match(self::FEEDBACK_LAYER, $name)) {
                    continue;
                }
                $this->storyline_text($layer, $paragraphs);
            }
            $html = '';
            foreach (array_keys($paragraphs) as $paragraph) {
                $html .= '<p>' . nl2br(self::esc($paragraph)) . '</p>';
            }
            $sections[] = ['title' => $this->text((string) ($slide['title'] ?? '')), 'html' => $html, 'level' => $level];
        }
        return $sections;
    }

    /**
     * Collect the text boxes of a Storyline slide layer.
     *
     * @param mixed $node
     * @param array $paragraphs Accumulator, paragraphs as keys to drop repeats.
     */
    private function storyline_text($node, array &$paragraphs): void {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['vartext']['blocks']) && is_array($node['vartext']['blocks'])) {
            foreach ($node['vartext']['blocks'] as $block) {
                $text = '';
                foreach ($block['spans'] ?? [] as $span) {
                    $text .= is_array($span) ? (string) ($span['text'] ?? '') : '';
                }
                $text = trim(preg_replace('/[ \t]+/u', ' ', $text));
                if ($text !== '') {
                    $paragraphs[$text] = true;
                }
            }
            return;
        }
        foreach ($node as $key => $child) {
            if ($key !== 'vartext') {
                $this->storyline_text($child, $paragraphs);
            }
        }
    }

    /**
     * Decode a Storyline globalProvideData('<type>', '<json>') script.
     *
     * @param string $script
     * @param string|null $type Only accept this type.
     * @return array|null
     */
    public static function provided_data(string $script, ?string $type = null): ?array {
        if (!preg_match("/globalProvideData\(\s*'([^']*)'\s*,\s*'/", $script, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        if ($type !== null && $match[1][0] !== $type) {
            return null;
        }
        $start = $match[0][1] + strlen($match[0][0]);
        $end = strrpos($script, "')");
        if ($end === false || $end < $start) {
            return null;
        }
        // Undo the JavaScript string escapes, leaving valid JSON escapes behind.
        $json = preg_replace_callback('/\\\\(u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|[\s\S])/', function(array $m): string {
            $c = $m[1];
            if ($c[0] === 'u' && strlen($c) === 5) {
                return '\\' . $c;
            }
            if ($c[0] === 'x' && strlen($c) === 3) {
                return '\\u00' . substr($c, 1);
            }
            $map = ['n' => '\\n', 'r' => '\\r', 't' => '\\t', 'b' => '\\b', 'f' => '\\f', 'v' => '\\u000b',
                '0' => '\\u0000', '\\' => '\\', "\n" => '', "\r" => ''];
            return $map[$c] ?? $c;
        }, substr($script, $start, $end - $start));
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    // Any other package: the pages of imsmanifest.xml.

    /**
     * Read the pages listed by the manifest.
     *
     * @return array
     */
    private function manifest_document(): array {
        $manifest = (string) $this->read('imsmanifest.xml');
        $title = '';
        $pages = [];
        if ($manifest !== '') {
            $dom = new \DOMDocument();
            if (@$dom->loadXML($manifest, LIBXML_NONET)) {
                $resources = [];
                foreach ($dom->getElementsByTagName('resource') as $resource) {
                    $href = $resource->getAttribute('href');
                    if ($href !== '') {
                        $base = $resource->getAttribute('xml:base')
                            ?: $resource->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base');
                        $resources[$resource->getAttribute('identifier')] = $base . $href;
                    }
                }
                foreach ($dom->getElementsByTagName('organization') as $organization) {
                    foreach ($organization->childNodes as $child) {
                        if ($child instanceof \DOMElement && $child->localName === 'title' && $title === '') {
                            $title = trim($child->textContent);
                        }
                    }
                    foreach ($organization->getElementsByTagName('item') as $item) {
                        $ref = $item->getAttribute('identifierref');
                        $itemtitle = '';
                        foreach ($item->childNodes as $child) {
                            if ($child instanceof \DOMElement && $child->localName === 'title') {
                                $itemtitle = trim($child->textContent);
                            }
                        }
                        if ($ref !== '' && isset($resources[$ref])) {
                            $pages[] = ['href' => $resources[$ref], 'title' => $itemtitle];
                        }
                    }
                    break; // Only the first organisation.
                }
                if (!$pages) {
                    foreach ($resources as $href) {
                        $pages[] = ['href' => $href, 'title' => ''];
                    }
                }
            }
        }
        if (!$pages && $this->has('index.html')) {
            $pages[] = ['href' => 'index.html', 'title' => ''];
        }

        $sections = [];
        $seen = [];
        foreach ($pages as $page) {
            $path = self::resolve('', preg_replace('/[?#].*$/', '', $page['href']));
            if ($path === null || isset($seen[$path]) || !preg_match('/\.x?html?$/i', $path)) {
                continue;
            }
            $seen[$path] = true;
            $html = (string) $this->read($path);
            if ($html === '') {
                continue;
            }
            $sections[] = [
                'title' => $page['title'],
                'html' => $this->page_html($html, dirname($path) === '.' ? '' : dirname($path) . '/'),
                'level' => 1,
            ];
        }
        return ['title' => $title, 'sections' => $sections];
    }

    /**
     * The main content of a package page.
     *
     * @param string $html
     * @param string $base Directory of the page.
     * @return string
     */
    private function page_html(string $html, string $base): string {
        $dom = self::load_html($html);
        $xpath = new \DOMXPath($dom);
        // Navigation, page chrome and answers that are hidden until the learner replies.
        $noise = '//header | //footer | //*[@id="siteNav" or @id="topPagination" or @id="bottomPagination"'
            . ' or @id="nodeDecoration" or @id="siteFooter" or @id="packageLicense"]'
            . ' | //*[contains(concat(" ", normalize-space(@class), " "), " pagination ")]'
            . ' | //*[contains(@class, "feedback")] | //*[@aria-hidden="true"]';
        foreach (iterator_to_array($xpath->query($noise)) as $node) {
            $node->parentNode->removeChild($node);
        }
        $main = $xpath->query('//main | //*[@id="main"] | //*[@id="content"]')->item(0)
            ?: $dom->getElementsByTagName('body')->item(0);
        return $main ? $this->clean_node($main, $base) : '';
    }

    // HTML clean-up for TCPDF.

    /**
     * Reduce an HTML fragment to what TCPDF prints well.
     *
     * @param string $html
     * @param string $base Directory against which relative image paths resolve.
     * @return string
     */
    private function clean_html(string $html, string $base): string {
        if (trim($html) === '') {
            return '';
        }
        $dom = self::load_html('<body>' . $html . '</body>');
        $body = $dom->getElementsByTagName('body')->item(0);
        return $body ? $this->clean_node($body, $base) : '';
    }

    /**
     * Rewrite the children of a node with the tags TCPDF understands.
     *
     * @param \DOMNode $node
     * @param string $base
     * @return string
     */
    private function clean_node(\DOMNode $node, string $base): string {
        $out = '';
        // Inside a table's structure only rows and cells count.
        $structural = in_array(strtolower($node->nodeName), ['table', 'thead', 'tbody', 'tfoot', 'tr'], true);
        foreach ($node->childNodes as $child) {
            if ($structural && (!$child instanceof \DOMElement || $child->localName === 'caption')) {
                continue;
            }
            if ($child instanceof \DOMText) {
                $out .= self::esc(preg_replace('/\s+/u', ' ', $child->nodeValue));
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->localName);
            if (in_array($tag, self::DROP, true)) {
                continue;
            }
            $style = strtolower((string) $child->getAttribute('style'));
            if (preg_match('/display\s*:\s*none/', $style) || $child->hasAttribute('hidden')) {
                continue;
            }
            switch ($tag) {
                case 'br':
                    $out .= '<br />';
                    break;
                case 'img':
                    $path = self::resolve($base, (string) $child->getAttribute('src'));
                    $out .= $path !== null ? $this->image($path) : '';
                    break;
                case 'a':
                    $href = (string) $child->getAttribute('href');
                    $inner = $this->clean_node($child, $base);
                    $out .= preg_match('#^(https?:|mailto:)#i', $href) && self::has_content($inner)
                        ? '<a href="' . self::esc($href) . '">' . $inner . '</a>' : $inner;
                    break;
                case 'h1':
                case 'h2':
                    $out .= self::wrap('h3', $this->clean_node($child, $base));
                    break;
                case 'h3':
                    $out .= self::wrap('h4', $this->clean_node($child, $base));
                    break;
                case 'h4':
                case 'h5':
                case 'h6':
                    $out .= self::wrap('h5', $this->clean_node($child, $base));
                    break;
                case 'table':
                    $caption = $child->getElementsByTagName('caption')->item(0);
                    if ($caption) {
                        $out .= self::wrap('p', '<strong>' . $this->clean_node($caption, $base) . '</strong>');
                    }
                    $inner = $this->clean_node($child, $base);
                    $out .= self::has_content($inner) ? '<table border="1" cellpadding="4">' . $inner . '</table>' : '';
                    break;
                case 'td':
                case 'th':
                    $attrs = '';
                    foreach (['colspan', 'rowspan'] as $name) {
                        if (ctype_digit((string) $child->getAttribute($name))) {
                            $attrs .= ' ' . $name . '="' . $child->getAttribute($name) . '"';
                        }
                    }
                    $out .= "<{$tag}{$attrs}>" . $this->clean_node($child, $base) . "</{$tag}>";
                    break;
                default:
                    $inner = $this->clean_node($child, $base);
                    if (in_array($tag, self::KEEP, true)) {
                        $out .= in_array($tag, ['tr', 'thead', 'tbody', 'tfoot', 'li'], true)
                            ? "<{$tag}>{$inner}</{$tag}>" : self::wrap($tag, $inner);
                    } else if (in_array($tag, ['div', 'section', 'article', 'main', 'figure', 'figcaption', 'aside',
                            'dl', 'dd', 'dt', 'details', 'summary', 'center'], true)
                            && self::has_content($inner) && !self::has_block($inner)) {
                        // A block of inline content becomes a paragraph of its own.
                        $out .= '<p>' . trim($inner) . '</p>';
                    } else {
                        $out .= $inner;
                    }
            }
        }
        return $out;
    }

    /**
     * Copy a package image where TCPDF can read it and return its tag.
     *
     * @param string $path
     * @return string
     */
    private function image(string $path): string {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($this->tempdir === '' || $this->images >= self::MAX_IMAGES || !in_array($extension, self::IMAGE_TYPES, true)) {
            return '';
        }
        $content = $this->read($path);
        if ($content === null || $content === '') {
            return '';
        }
        $size = @getimagesizefromstring($content);
        if (!$size || $size[0] < 1 || $size[1] < 1) {
            return '';
        }
        // Skip icons and spacers.
        if ($size[0] < 24 && $size[1] < 24) {
            return '';
        }
        $this->images++;
        $file = $this->tempdir . '/image' . $this->images . '.' . $extension;
        if (@file_put_contents($file, $content) === false) {
            return '';
        }
        $scale = min(1, self::IMAGE_MAX_WIDTH / $size[0], self::IMAGE_MAX_HEIGHT / $size[1]);
        $width = max(1, (int) round($size[0] * $scale));
        $height = max(1, (int) round($size[1] * $scale));
        return '<p style="text-align:center;"><img src="' . self::esc('file://' . $file) . '" width="' . $width . '" height="' . $height
            . '" /></p>';
    }

    // Helpers.

    /**
     * Read a package file.
     *
     * @param string|null $path
     * @return string|null
     */
    private function read(?string $path): ?string {
        return $path === null ? null : ($this->read)($path);
    }

    /**
     * Whether the package has a file.
     *
     * @param string $path
     * @return bool
     */
    private function has(string $path): bool {
        return isset($this->paths[$path]);
    }

    /**
     * Plain text of an HTML snippet.
     *
     * @param string $html
     * @return string
     */
    private function text(string $html): string {
        $html = preg_replace('#<br\s*/?>#i', ' ', $html);
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * Resolve a relative URL of the package against a directory.
     *
     * @param string $base
     * @param string $src
     * @return string|null Path from the package root, or null when it leaves the package.
     */
    public static function resolve(string $base, string $src): ?string {
        $src = trim($src);
        if ($src === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $src)) {
            return null;
        }
        $src = rawurldecode(preg_replace('/[?#].*$/', '', $src));
        $parts = [];
        foreach (explode('/', ($src[0] === '/' ? '' : $base) . $src) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (!$parts) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return $parts ? implode('/', $parts) : null;
    }

    /**
     * Parse HTML as UTF-8, quietly.
     *
     * @param string $html
     * @return \DOMDocument
     */
    private static function load_html(string $html): \DOMDocument {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $dom;
    }

    /**
     * Escape text for TCPDF, which only knows numeric and HTML 4 entities.
     *
     * @param string $text
     * @return string
     */
    private static function esc(string $text): string {
        return htmlspecialchars($text, ENT_COMPAT | ENT_HTML401 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Wrap content in a tag, unless it is empty.
     *
     * @param string $tag
     * @param string $inner
     * @return string
     */
    private static function wrap(string $tag, string $inner): string {
        if (!self::has_content($inner)) {
            return '';
        }
        if ($tag === 'p' && self::has_block($inner)) {
            return $inner; // Paragraphs cannot hold lists or tables.
        }
        return "<{$tag}>" . trim($inner) . "</{$tag}>";
    }

    /**
     * Whether some HTML holds text or an image.
     *
     * @param string $html
     * @return bool
     */
    private static function has_content(string $html): bool {
        return stripos($html, '<img') !== false
            || trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0") !== '';
    }

    /**
     * Whether some HTML holds block elements.
     *
     * @param string $html
     * @return bool
     */
    private static function has_block(string $html): bool {
        return (bool) preg_match('#<(' . implode('|', self::BLOCKS) . ')[\s>/]#i', $html);
    }

    /**
     * Turn paragraphs into line breaks, for list items.
     *
     * @param string $html
     * @return string
     */
    private static function unwrap_paragraphs(string $html): string {
        $html = preg_replace('#</p>\s*<p>#i', '<br />', trim($html));
        return preg_replace('#^<p>|</p>$#i', '', $html);
    }
}
