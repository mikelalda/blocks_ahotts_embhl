<?php
// This file is part of Moodle - http://moodle.org/
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace block_ahotts_embhl\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Explainable language inference for bulk provisioning, without external services.
 *
 * @package block_ahotts_embhl
 * @copyright 2026 BIRT
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_language {
    /** Distinctive words, rather than shared technical terms such as software. */
    private const WORDS = [
        'es' => 'de del la las los el en para con una un y desarrollo entornos interactivos multidispositivo '
            . 'información gestión administración sistemas redes aplicaciones programación servicios seguridad '
            . 'formación orientación laboral empresa iniciativa emprendedora proyecto procesos fabricación '
            . 'mantenimiento montaje instalaciones eléctricas automatismos máquinas mecanizado diseño '
            . 'comunicación atención cliente documentación sanitaria técnicas apoyo organización transporte '
            . 'logística almacenamiento comercial recursos humanos contabilidad fiscalidad tratamiento '
            . 'ofimática procesamiento datos presentación introducción unidad tarea evaluación aprendizaje '
            . 'realidad virtual simulación videojuegos inglés técnico',
        'eu' => 'eta edo da dira du dira ere hau hauek nola zer zein batean egiteko '
            . 'bulegotika informazioaren prozesua informazioa kudeaketa administrazioa sistemak sistemen '
            . 'sareak sareko aplikazioak aplikazioen programazioa zerbitzuak zerbitzuen segurtasuna '
            . 'laneko prestakuntza orientabidea enpresa ekimen ekintzailea proiektua proiektu '
            . 'fabrikazio fabrikazioa mantentze mantentzea muntaketa instalazioak instalazio '
            . 'elektrikoak diseinua diseinu komunikazioa bezeroaren arreta dokumentazioa '
            . 'teknikak laguntza antolaketa garraioa logistika biltegiratzea merkataritza '
            . 'giza baliabideak kontabilitatea fiskalitatea datuak datuen tratamendua '
            . 'aurkezpena sarrera unitatea ataza ebaluazioa ikaskuntza ingurune inguruneak '
            . 'garapena ingelesa teknikoa erabiltzeko erabilera ariketa ariketak jarduera jarduerak',
        'en' => 'the and of for with your you how what where should start is are this that '
            . 'development environments interactive information management administration systems '
            . 'networks applications programming services security training guidance employment '
            . 'business entrepreneurship project processes manufacturing maintenance assembly '
            . 'electrical machines design communication customer care documentation support '
            . 'organisation organization transport storage human resources accounting data '
            . 'processing presentation introduction task assessment learning english technical '
            . 'unit activity activities work working skills reading writing listening speaking',
    ];

    /**
     * Score unique lexical clues; exclude bilingual UI fragments and duplicate titles.
     *
     * @param array $texts Plain or HTML titles/content.
     * @return array Language (empty when uncertain), scores and matching words.
     */
    public static function infer(array $texts): array {
        $scores = ['es' => 0, 'eu' => 0, 'en' => 0];
        $matches = ['es' => [], 'eu' => [], 'en' => []];
        $text = implode(' ', array_unique($texts));
        // Do not let parallel translations decide the course's language.
        $text = preg_replace('/\{mlang\s+[^}]+\}.*?\{mlang\}/isu', ' ', $text);
        $text = preg_replace('/<span\b[^>]*class=["\'][^"\']*multilang[^"\']*["\'][^>]*>.*?<\/span>/isu',
            ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tokens = array_flip(preg_split('/[^\p{L}]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY));
        foreach (self::WORDS as $language => $words) {
            $matches[$language] = array_values(array_intersect(array_unique(explode(' ', $words)), array_keys($tokens)));
            $scores[$language] = count($matches[$language]);
        }
        $ranked = $scores;
        arsort($ranked);
        $winner = array_key_first($ranked);
        $values = array_values($ranked);
        // Require at least two different clues and a clear lead over the runner-up.
        $certain = $values[0] >= 2 && $values[0] >= 2 * $values[1] && $values[0] - $values[1] >= 2;
        return ['language' => $certain ? $winner : '', 'scores' => $scores, 'matches' => $matches];
    }
}
