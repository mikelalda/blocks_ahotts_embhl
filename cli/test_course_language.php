<?php
// This file is part of Moodle - http://moodle.org/
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Standalone regression checks for language inference; no database needed.
 *
 * @package block_ahotts_embhl
 * @copyright 2026 BIRT
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/course_language.php');

$cases = [
    'Reported course 724' => [['Desarrollo de entornos interactivos multidispositivo copia 1'], 'es'],
    'Reported SCORM titles' => [['DEIM06.3 Realidad Virtual (VR)',
        'DEIM07.1 Entornos de simulación de videojuegos', 'DEIM07.2 Máquinas virtuales'], 'es'],
    'Basque course' => [['Bulegotika eta informazioaren prozesua'], 'eu'],
    'English page' => [['IT01 Where should I start?'], 'en'],
    'Case and accents' => [['GESTIÓN DE LA DOCUMENTACIÓN'], 'es'],
    'Uninformative code' => [['DEIM724'], ''],
    'Shared terminology' => [['Software hardware SCORM HTML'], ''],
    'Bilingual mlang' => [['{mlang es}Desarrollo de aplicaciones{mlang}'
        . '{mlang eu}Aplikazioen garapena eta programazioa{mlang}'], ''],
    'Bilingual HTML' => [['<span lang="es" class="multilang">Desarrollo de aplicaciones</span>'
        . '<span lang="eu" class="multilang">Aplikazioen garapena</span>'], ''],
    'Mixed evidence' => [['Desarrollo aplicaciones programación', 'development applications programming'], ''],
    'Repeated ambiguous title' => [array_fill(0, 100, 'de'), ''],
    'Multilang boilerplate plus Basque' => [['Bulegotika eta informazioaren prozesua',
        '{mlang es}Desarrollo de aplicaciones y sistemas{mlang}'], 'eu'],
];
foreach ($cases as $name => [$texts, $expected]) {
    $result = \block_ahotts_embhl\local\course_language::infer($texts);
    if ($result['language'] !== $expected) {
        fwrite(STDERR, 'FAIL ' . $name . ': ' . json_encode($result) . PHP_EOL);
        exit(1);
    }
    echo 'PASS ' . $name . PHP_EOL;
}
