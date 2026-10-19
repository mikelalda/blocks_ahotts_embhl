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
 * Build the PDF of a SCORM activity from the command line and say what was found.
 *
 *   sudo -u www-data php blocks/ahotts_embhl/cli/scorm_pdf.php --cmid=24337 [--output=/tmp/curso.pdf]
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use block_ahotts_embhl\local\scorm_pdf;

[$options] = cli_get_params(['cmid' => 0, 'output' => '', 'help' => false], ['h' => 'help']);
if ($options['help'] || !$options['cmid']) {
    cli_writeln('Usage: php blocks/ahotts_embhl/cli/scorm_pdf.php --cmid=<SCORM course module id> [--output=<file.pdf>]');
    exit($options['help'] ? 0 : 1);
}

$cm = get_coursemodule_from_id('scorm', (int) $options['cmid'], 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);
$converter = scorm_pdf::from_context($context, make_request_directory());
cli_writeln('SCORM: ' . format_string($cm->name) . ' (cmid ' . $cm->id . ')');
cli_writeln('Package: ' . $converter->kind());

$document = $converter->document($cm->name);
cli_writeln('Title: ' . $document['title']);
foreach ($document['sections'] as $section) {
    $text = trim(html_entity_decode(strip_tags($section['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    cli_writeln(sprintf('  %s %s (%d characters)', str_repeat('-', $section['level']), $section['title'] ?: '[untitled]',
        core_text::strlen($text)));
}
if (scorm_pdf::is_empty($document)) {
    cli_problem('No readable text was found.');
    exit(2);
}
if ($options['output']) {
    file_put_contents($options['output'], scorm_pdf::render($document, make_localcache_directory('block_ahotts_embhl/fonts')));
    cli_writeln('Written: ' . $options['output']);
}
