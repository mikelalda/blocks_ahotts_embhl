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
 * Usage report: how many times pages were listened to and downloaded, month by month.
 *
 * Managers see the whole site and can narrow it to one course; teachers see
 * their own course.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use block_ahotts_embhl\local\usage;

$courseid = optional_param('courseid', 0, PARAM_INT);
$months = optional_param('months', 12, PARAM_INT);
$period = optional_param('period', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

$months = in_array($months, [12, 24, 36], true) ? $months : 12;
$baseparams = array_filter(['courseid' => $courseid, 'months' => $months == 12 ? 0 : $months]);
$url = new moodle_url('/blocks/ahotts_embhl/usage.php', $baseparams);
$systemcontext = context_system::instance();
$title = get_string('usagereport', 'block_ahotts_embhl');

if ($courseid && $courseid != SITEID && !has_capability('block/ahotts_embhl:viewusage', $systemcontext)) {
    // A teacher looking at their own course.
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($course->id);
    require_capability('block/ahotts_embhl:viewusage', $context);
    $PAGE->set_url($url);
    $PAGE->set_pagelayout('report');
    $PAGE->set_title($title . moodle_page::TITLE_SEPARATOR . format_string($course->fullname, true, ['context' => $context]));
    $PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
    $sitewide = false;
} else if (has_capability('moodle/site:config', $systemcontext)) {
    admin_externalpage_setup('block_ahotts_embhl_usage', '', null, $url);
    $sitewide = true;
} else {
    // Managers without access to the site settings, where the report is listed.
    require_login();
    require_capability('block/ahotts_embhl:viewusage', $systemcontext);
    $PAGE->set_context($systemcontext);
    $PAGE->set_url($url);
    $PAGE->set_pagelayout('report');
    $PAGE->set_title($title);
    $PAGE->set_heading($SITE->fullname);
    $sitewide = true;
}

$labels = [
    usage::LISTEN => get_string('usagelisten', 'block_ahotts_embhl'),
    usage::AUDIO => get_string('usageaudio', 'block_ahotts_embhl'),
    usage::PDF => get_string('usagepdf', 'block_ahotts_embhl'),
];

if ($download !== '') {
    $rows = usage::all($courseid);
    $names = usage::course_names(array_column($rows, 'courseid'));
    $columns = [
        'month' => get_string('usagemonth', 'block_ahotts_embhl'),
        'course' => get_string('usagecourse', 'block_ahotts_embhl'),
        'courseid' => get_string('usagecourseid', 'block_ahotts_embhl'),
    ] + $labels;
    $export = array_map(fn($row) => [
        sprintf('%04d-%02d', intdiv($row['period'], 100), $row['period'] % 100),
        $names[$row['courseid']] ?? $row['courseid'],
        $row['courseid'],
        $row[usage::LISTEN],
        $row[usage::AUDIO],
        $row[usage::PDF],
    ], $rows);
    \core\dataformat::download_data('ahotts-usage-' . userdate(time(), '%Y%m%d', 99, false), $download, $columns, $export);
    die();
}

// Month by month, newest first.
$monthly = usage::monthly($courseid, $months);
$totals = array_fill_keys(usage::ACTIONS, 0);
$monthrows = [];
foreach ($monthly as $row) {
    foreach (usage::ACTIONS as $action) {
        $totals[$action] += $row[$action];
    }
    $monthrows[] = [
        'label' => usage::period_label($row['period']),
        'listen' => $row[usage::LISTEN],
        'audio' => $row[usage::AUDIO],
        'pdf' => $row[usage::PDF],
        'downloads' => $row[usage::AUDIO] + $row[usage::PDF],
    ];
}

// The chart reads left to right, oldest month first.
$chart = new \core\chart_bar();
$chart->set_title(get_string('usagechart', 'block_ahotts_embhl'));
$chart->set_labels(array_map(fn($row) => usage::period_label($row['period']), array_reverse($monthly)));
foreach (usage::ACTIONS as $action) {
    $chart->add_series(new \core\chart_series($labels[$action], array_column(array_reverse($monthly), $action)));
}

$data = [
    'explained' => get_string('usageexplained', 'block_ahotts_embhl'),
    'filters' => '',
    'chart' => $OUTPUT->render($chart),
    'months' => $monthrows,
    'totals' => [
        'listen' => $totals[usage::LISTEN],
        'audio' => $totals[usage::AUDIO],
        'pdf' => $totals[usage::PDF],
        'downloads' => $totals[usage::AUDIO] + $totals[usage::PDF],
    ],
    'showcourses' => false,
    'download' => $OUTPUT->download_dataformat_selector(
        get_string('usagedownload', 'block_ahotts_embhl'),
        $url->out_omit_querystring(),
        'download',
        $url->params()
    ),
];

// How far back to look, and which course.
$filters = [];
$monthchoices = [];
foreach ([12, 24, 36] as $choice) {
    $monthchoices[$choice] = get_string('usagemonths', 'block_ahotts_embhl', $choice);
}
$select = new single_select(new moodle_url($url, ['months' => null]), 'months', $monthchoices, $months, null);
$select->set_label(get_string('usageperiod', 'block_ahotts_embhl'));
$filters[] = $OUTPUT->render($select);

if ($sitewide) {
    $courses = [0 => get_string('usageallcourses', 'block_ahotts_embhl')] + usage::course_names();
    $select = new single_select(new moodle_url($url, ['courseid' => null]), 'courseid', $courses, $courseid, null);
    $select->set_label(get_string('usagecourse', 'block_ahotts_embhl'));
    $filters[] = $OUTPUT->render($select);
}
$data['filters'] = implode('', $filters);

// One month course by course, for the whole site.
if ($sitewide && !$courseid) {
    $periods = array_column($monthly, 'period');
    $period = in_array($period, $periods, true) ? $period : reset($periods);
    $periodchoices = [];
    foreach ($periods as $choice) {
        $periodchoices[$choice] = usage::period_label($choice);
    }
    $select = new single_select($url, 'period', $periodchoices, $period, null);
    $select->set_label(get_string('usagemonth', 'block_ahotts_embhl'));

    $bycourse = usage::by_course($period);
    $names = usage::course_names(array_column($bycourse, 'courseid'));
    $data['showcourses'] = true;
    $data['coursesheading'] = get_string('usagebycourse', 'block_ahotts_embhl', usage::period_label($period));
    $data['periodselect'] = $OUTPUT->render($select);
    $data['courses'] = array_map(fn($row) => [
        'name' => $names[$row['courseid']] ?? $row['courseid'],
        'url' => (new moodle_url($url, ['courseid' => $row['courseid']]))->out(false),
        'listen' => $row[usage::LISTEN],
        'audio' => $row[usage::AUDIO],
        'pdf' => $row[usage::PDF],
        'downloads' => $row[usage::AUDIO] + $row[usage::PDF],
    ], $bycourse);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo $OUTPUT->render_from_template('block_ahotts_embhl/usage_report', $data);
echo $OUTPUT->footer();
