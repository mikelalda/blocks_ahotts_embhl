<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Provision one inherited aHoTTS block per course. Dry run by default.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 BIRT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../lib/clilib.php');

[$options, $unknown] = cli_get_params([
    'help' => false,
    'courseid' => false,
    'all' => false,
    'dry-run' => false,
    'apply' => false,
    'language' => false,
    'language-map' => false,
    'scorm-current-window' => false,
    'expected-site' => 'https://ikastaroak.birt.eus',
], ['h' => 'help']);

if ($options['help']) {
    echo <<<'HELP'
Configura ahotts_embhl en todos los apartados de cada curso (patrón *, herencia).
Por defecto SOLO simula. --apply es necesario para guardar.

  php blocks/ahotts_embhl/cli/provision_courses.php --courseid=261 --dry-run
  php blocks/ahotts_embhl/cli/provision_courses.php --courseid=261 --language=eu --scorm-current-window --apply
  php blocks/ahotts_embhl/cli/provision_courses.php --all --dry-run
  php blocks/ahotts_embhl/cli/provision_courses.php --all --apply

Opciones:
  --courseid=N          Un curso (excluye la portada).
  --all                 Todos los cursos existentes, incluidos los ocultos.
  --language=CODE       Idioma explícito para --courseid; no se permite con --all.
  --language-map=FILE   JSON de idiomas explícitos: {"261":"eu","262":"en_uk"}.
                        Tiene prioridad sobre course.lang y la detección automática.
  --scorm-current-window Pone Apariencia > Mostrar paquete en Ventana actual
                        en todos los SCORM del alcance, incluso si se omite el bloque.
  --expected-site=URL   Comprobación del destino; por defecto aurrekoikastaroak.birt.eus.
  --dry-run             Solo lectura de bloques; incompatible con --apply.
  --apply               Guarda las acciones planificadas, con bloqueo y transacción.
  --help                Esta ayuda.

Idiomas: es -> es_es, eu -> eu_es, en -> en_us; se acepta en_uk/en_gb -> en_uk.
Si course.lang está vacío, usa las referencias facilitadas, el nombre del curso
y, después, títulos de secciones/páginas/SCORM y resumen. Informa las pistas
léxicas utilizadas. Los casos ambiguos siguen pendientes de revisión.
Se amplía una instancia existente del curso o se crea una nueva. Los conflictos
con instancias de actividades/categorías/sistema o excepciones de posición se
omiten para no crear controles duplicados ni borrar configuraciones anteriores.
Si algún curso queda pendiente, sale con código 2. Error general: código 1.
Salida JSONL: sitio, referencias, plan por curso y resumen.

HELP;
    exit(0);
}
if ($unknown || (bool)$options['all'] === (bool)$options['courseid'] ||
        ($options['apply'] && $options['dry-run'])) {
    cli_error('Indica --courseid=N o --all; --apply y --dry-run son incompatibles. Consulta --help.');
}
if ($options['courseid'] !== false && !preg_match('/^[1-9][0-9]*$/D', $options['courseid'])) {
    cli_error('courseid debe ser un entero positivo.');
}
$languagecodes = ['es' => 'es_es', 'es_es' => 'es_es', 'eu' => 'eu_es', 'eu_es' => 'eu_es',
    'en' => 'en_us', 'en_us' => 'en_us', 'en_gb' => 'en_uk', 'en_uk' => 'en_uk'];
if ($options['language'] !== false && ($options['all'] || !is_string($options['language']) ||
        !isset($languagecodes[strtolower(str_replace('-', '_', $options['language']))]))) {
    cli_error('--language requiere --courseid y un idioma admitido: eu, es, en, en_uk.');
}
$languagemap = [];
if ($options['language-map']) {
    $raw = file_get_contents($options['language-map']);
    $languagemap = json_decode($raw ?: '', true);
    if (!is_array($languagemap) || json_last_error() !== JSON_ERROR_NONE) {
        cli_error('El mapa de idiomas debe ser un objeto JSON válido.');
    }
    foreach ($languagemap as $id => $language) {
        if (!ctype_digit((string)$id) || !is_string($language)) {
            cli_error('El mapa debe contener IDs de curso e idiomas de texto.');
        }
    }
}
if ($options['language'] !== false) {
    $languagemap[(int)$options['courseid']] = $options['language'];
}
if (!is_readable(__DIR__ . '/../../../config.php')) {
    cli_error('No se puede leer config.php. Ejecuta este script con el usuario del servicio Moodle. No se han modificado bloques.');
}
require(__DIR__ . '/../../../config.php');
// CLI deployments may still have a class map cached before this helper existed.
$languagehelper = __DIR__ . '/../classes/local/course_language.php';
if (!is_readable($languagehelper)) {
    cli_error('Falta el archivo legible ' . $languagehelper . '. Copia también el detector de idioma al servidor.');
}
require_once($languagehelper);
require_once($CFG->libdir . '/blocklib.php');
require_once($CFG->dirroot . '/course/lib.php');

/** Emit one machine-readable report record. */
function block_ahotts_embhl_report(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
}

if (rtrim($CFG->wwwroot, '/') !== rtrim($options['expected-site'], '/')) {
    cli_error('El wwwroot no coincide con --expected-site. No se han modificado bloques.');
}
if (!$DB->record_exists('block', ['name' => 'ahotts_embhl', 'visible' => 1])) {
    cli_error('El bloque ahotts_embhl no está instalado y habilitado.');
}
$mode = get_config('block_ahotts_embhl', 'langmode') ?: 'fixed';
// Other modes can select the visitor's language or the content language instead.
if ($mode !== 'fixed') {
    cli_error('langmode=' . $mode . ': el idioma por curso requiere modo fixed. Revisa el ajuste global antes de continuar.');
}
$offered = \block_ahotts_embhl\local\languages::offered();
block_ahotts_embhl_report(['type' => 'site', 'url' => $CFG->wwwroot,
    'mode' => $options['apply'] ? 'apply' : 'dry-run', 'language_mode' => $mode]);
$referencelanguages = [];
foreach ([24203 => 'en', 16055 => 'es', 23945 => 'eu'] as $cmid => $expectedlanguage) {
    $reference = $DB->get_record_sql('SELECT cm.id, cm.course, c.lang, p.name
          FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
          JOIN {course} c ON c.id = cm.course JOIN {page} p ON p.id = cm.instance
         WHERE cm.id = :id AND m.name = :module', ['id' => $cmid, 'module' => 'page']);
    block_ahotts_embhl_report(['type' => 'reference', 'cmid' => $cmid,
        'user_language' => $expectedlanguage, 'record' => $reference ?: null]);
    // These references were explicitly identified by the user on this site only.
    if ($reference && rtrim($CFG->wwwroot, '/') === 'https://ikastaroak.birt.eus') {
        $referencelanguages[$reference->course][$expectedlanguage] = true;
    }
}
$lock = null;
try {
    if ($options['apply']) {
        $lock = \core\lock\lock_config::get_lock_factory('block_ahotts_embhl')->get_lock('provision_courses', 10);
        if (!$lock) {
            throw new RuntimeException('Otra ejecución del script está en curso.');
        }
    }
    $params = ['siteid' => SITEID];
    $where = 'id <> :siteid';
    if (!$options['all']) {
        $where .= ' AND id = :courseid';
        $params['courseid'] = (int)$options['courseid'];
    }
    $courses = $DB->get_records_select('course', $where, $params, 'id');
    if (!$courses) {
        throw new RuntimeException('No hay cursos para el alcance indicado.');
    }
    $summary = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'skip' => 0];
    $scormsummary = ['update' => 0, 'unchanged' => 0];
    $scormplans = [];
    // Preflight every course before performing any application writes.
    $plans = [];
    foreach ($courses as $course) {
        if ($options['scorm-current-window']) {
            $scorms = $DB->get_records('scorm', ['course' => $course->id], 'id', 'id,course,name,popup');
            foreach ($scorms as $scorm) {
                $action = (int)$scorm->popup === 0 ? 'unchanged' : 'update';
                $scormsummary[$action]++;
                block_ahotts_embhl_report(['type' => 'scorm', 'courseid' => (int)$course->id,
                    'scormid' => (int)$scorm->id, 'name' => $scorm->name, 'action' => $action,
                    'popup_before' => (int)$scorm->popup, 'popup_after' => 0]);
                if ($action === 'update') {
                    $scormplans[] = $scorm;
                }
            }
        }
        $plan = ['type' => 'course', 'courseid' => (int)$course->id, 'name' => $course->fullname,
            'language_source' => $options['language'] !== false ? 'argument' :
                (isset($languagemap[$course->id]) ? 'map' : 'course.lang'),
            'language' => $languagemap[$course->id] ?? $course->lang, 'action' => 'skip'];
        if ($plan['language'] === '' && !isset($languagemap[$course->id])) {
            $references = $referencelanguages[$course->id] ?? [];
            if (count($references) === 1) {
                $plan['language'] = array_key_first($references);
                $plan['language_source'] = 'user_reference';
            } else {
                $inference = \block_ahotts_embhl\local\course_language::infer([$course->fullname]);
                $plan['language_source'] = 'auto.course_name';
                if ($inference['language'] === '') {
                    $texts = [$course->fullname, $course->summary ?? ''];
                    foreach (['course_sections', 'page', 'scorm'] as $table) {
                        $records = $DB->get_records($table, ['course' => $course->id], 'id', 'id,name');
                        foreach ($records as $record) {
                            if (!empty($record->name)) {
                                $texts[] = $record->name;
                            }
                        }
                    }
                    $inference = \block_ahotts_embhl\local\course_language::infer($texts);
                    $plan['language_source'] = 'auto.course_content';
                }
                $plan['language'] = $inference['language'];
                $plan['language_evidence'] = $inference;
            }
        }
        $code = $languagecodes[strtolower(str_replace('-', '_', $plan['language']))] ?? null;
        $context = $DB->get_record('context', ['contextlevel' => CONTEXT_COURSE, 'instanceid' => $course->id]);
        if (!$code) {
            $plan['reason'] = 'Idioma no admitido o detección ambigua: revisa language_evidence o indica --language-map.';
        } else if (!in_array(substr($code, 0, 2), $offered, true)) {
            $plan['reason'] = 'Idioma no habilitado en chooserlangs; el bloque usaría otro idioma.';
        } else if (!$context || !$context->path) {
            $plan['reason'] = 'El curso no tiene un contexto válido.';
        } else {
            $ancestorids = array_map('intval', explode('/', trim($context->path, '/')));
            [$insql, $inparams] = $DB->get_in_or_equal($ancestorids, SQL_PARAMS_NAMED, 'ancestor');
            $sql = "SELECT bi.* FROM {block_instances} bi JOIN {context} ctx ON ctx.id = bi.parentcontextid
                     WHERE bi.blockname = :blockname AND
                           (bi.parentcontextid $insql OR " . $DB->sql_like('ctx.path', ':descendants') . ')';
            $instances = $DB->get_records_sql($sql, $inparams + [
                'blockname' => 'ahotts_embhl', 'descendants' => $context->path . '/%']);
            $own = [];
            $conflicts = [];
            foreach ($instances as $instance) {
                if ((int)$instance->parentcontextid === (int)$context->id) {
                    $own[] = $instance;
                } else if (!in_array((int)$instance->parentcontextid, $ancestorids, true) ||
                        $instance->showinsubcontexts) {
                    $conflicts[] = (int)$instance->id;
                }
            }
            $existing = count($own) === 1 ? reset($own) : null;
            $plan['existing_ids'] = array_map(static fn($b) => (int)$b->id, $own);
            $plan['conflicting_ids'] = $conflicts;
            if ($conflicts || count($own) > 1) {
                $plan['reason'] = 'Hay instancias que podrían duplicarse; requieren revisión.';
            } else if ($existing && $DB->record_exists('block_positions', ['blockinstanceid' => $existing->id])) {
                $plan['reason'] = 'La instancia tiene excepciones de posición/visibilidad; requieren revisión.';
            } else {
                $config = $existing && $existing->configdata !== ''
                    ? unserialize(base64_decode($existing->configdata), ['allowed_classes' => ['stdClass']])
                    : new stdClass();
                if (!$config instanceof stdClass) {
                    $plan['reason'] = 'Configuración de instancia no válida.';
                } else {
                    $page = new moodle_page();
                    $page->set_course($course);
                    $page->set_context(context::instance_by_id($context->id));
                    $page->set_url('/course/view.php', ['id' => $course->id]);
                    $page->set_pagelayout('course');
                    $page->set_pagetype('course-view-' . $course->format);
                    $region = $existing->defaultregion ?? $page->blocks->get_default_region();
                    if (!$region || !in_array($region, $page->blocks->get_regions(), true)) {
                        $plan['reason'] = 'El tema no ofrece una región compatible para el bloque.';
                    } else {
                        $plan['action'] = !$existing ? 'create' : (
                            ($config->lang ?? '') === $code && $existing->pagetypepattern === '*' &&
                            $existing->showinsubcontexts && $existing->subpagepattern === null ? 'unchanged' : 'update');
                        $plan['block_language'] = $code;
                        $plan['region'] = $region;
                        $plan['pagetypepattern'] = '*';
                        $plan['showinsubcontexts'] = 1;
                        $config->lang = $code;
                        $plans[] = [$plan, $page, $existing, $config];
                    }
                }
            }
        }
        $summary[$plan['action']]++;
        block_ahotts_embhl_report($plan);
    }
    if ($options['apply']) {
        $transaction = $DB->start_delegated_transaction();
        foreach ($plans as [$plan, $page, $existing, $config]) {
            if ($plan['action'] === 'unchanged') {
                continue;
            }
            if ($existing) {
                $DB->update_record('block_instances', (object)[
                    'id' => $existing->id, 'pagetypepattern' => '*', 'subpagepattern' => null,
                    'showinsubcontexts' => 1, 'timemodified' => time(),
                ]);
                $block = block_instance('ahotts_embhl', $existing, $page);
            } else {
                $block = $page->blocks->add_block('ahotts_embhl', $plan['region'], 0, true, '*', null);
            }
            if (!$block) {
                throw new RuntimeException('No se pudo instanciar el bloque.');
            }
            $block->instance_config_save($config);
        }
        $changedcourses = [];
        foreach ($scormplans as $scorm) {
            // Only change appearance. scorm_update_instance() also processes packages and grading.
            $DB->update_record('scorm', (object)[
                'id' => $scorm->id, 'popup' => 0, 'timemodified' => time(),
            ]);
            $changedcourses[$scorm->course] = true;
        }
        $transaction->allow_commit();
        foreach (array_keys($changedcourses) as $courseid) {
            rebuild_course_cache($courseid, true);
        }
    }
    block_ahotts_embhl_report(['type' => 'summary', 'mode' => $options['apply'] ? 'apply' : 'dry-run',
        'counts' => $summary, 'saved' => $options['apply'] ? $summary['create'] + $summary['update'] : 0,
        'scorm_counts' => $scormsummary, 'scorm_saved' => $options['apply'] ? $scormsummary['update'] : 0]);
} finally {
    if ($lock) {
        $lock->release();
    }
}
exit($summary['skip'] ? 2 : 0);
