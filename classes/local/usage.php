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

/**
 * How often pages are listened to and downloaded, month by month.
 *
 * Each reading or download triggers a Moodle event, so it shows in the site
 * logs with its user, and adds one to a monthly total per course. The totals
 * hold no personal data and outlive log retention, so the usage report keeps
 * its history.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage {
    /** @var string A reading started with the Listen button. */
    const LISTEN = 'listen';

    /** @var string The audio of a page or activity was downloaded. */
    const AUDIO = 'audio';

    /** @var string A PDF was downloaded with the block. */
    const PDF = 'pdf';

    /** @var string[] Everything that is counted, in report order. */
    const ACTIONS = [self::LISTEN, self::AUDIO, self::PDF];

    /** @var string[] Event class of each action. */
    const EVENTS = [
        self::LISTEN => \block_ahotts_embhl\event\content_listened::class,
        self::AUDIO => \block_ahotts_embhl\event\audio_downloaded::class,
        self::PDF => \block_ahotts_embhl\event\pdf_downloaded::class,
    ];

    /** @var string Table of the monthly totals. */
    const TABLE = 'block_ahotts_embhl_usage';

    /**
     * Month of a moment in the server timezone.
     *
     * @param int|null $time Unix time; now by default.
     * @return int YYYYMM, for example 202611.
     */
    public static function period(?int $time = null): int {
        $date = new \DateTime('@' . ($time ?? time()));
        $date->setTimezone(\core_date::get_server_timezone_object());
        return (int) $date->format('Ym');
    }

    /**
     * The month before or after another one.
     *
     * @param int $period YYYYMM.
     * @param int $months How many months to move; negative goes back.
     * @return int YYYYMM.
     */
    public static function shift(int $period, int $months): int {
        $index = intdiv($period, 100) * 12 + ($period % 100 - 1) + $months;
        return intdiv($index, 12) * 100 + $index % 12 + 1;
    }

    /**
     * Readable name of a month, such as "November 2026".
     *
     * @param int $period YYYYMM.
     * @return string
     */
    public static function period_label(int $period): string {
        $timezone = \core_date::get_server_timezone();
        $middle = sprintf('%04d-%02d-15 12:00:00', intdiv($period, 100), $period % 100);
        $date = new \DateTime($middle, new \DateTimeZone($timezone));
        return userdate($date->getTimestamp(), get_string('strftimemonthyear', 'langconfig'), $timezone);
    }

    /**
     * Record that something happened on a page: log it and count it.
     *
     * @param string $action One of self::ACTIONS.
     * @param \context $context Context of the page.
     * @param int $courseid Course of the page; the site course outside courses.
     * @param array $other Event details: 'language' and, for PDFs, 'source'.
     */
    public static function record(string $action, \context $context, int $courseid, array $other = []): void {
        if (!isset(self::EVENTS[$action])) {
            throw new \coding_exception('Unknown usage action: ' . $action);
        }
        $class = self::EVENTS[$action];
        // The event takes its course from the context.
        $class::create(['context' => $context, 'other' => $other])->trigger();

        self::increment($action, $courseid);
    }

    /**
     * Add one to the total of a month.
     *
     * @param string $action One of self::ACTIONS.
     * @param int $courseid
     * @param int|null $period YYYYMM; the current month by default.
     */
    public static function increment(string $action, int $courseid, ?int $period = null): void {
        global $DB;

        $key = ['period' => $period ?? self::period(), 'courseid' => $courseid, 'action' => $action];
        $inserted = false;
        if (!$DB->record_exists(self::TABLE, $key)) {
            try {
                $DB->insert_record(self::TABLE, $key + ['total' => 1, 'timemodified' => time()]);
                $inserted = true;
            } catch (\dml_write_exception $e) {
                // Another request created the row first: add to it instead.
                $inserted = false;
            }
        }
        if ($inserted) {
            return;
        }
        // The increment happens in the database, so simultaneous requests are all counted.
        $DB->execute(
            'UPDATE {' . self::TABLE . '}
                SET total = total + 1, timemodified = :now
              WHERE period = :period AND courseid = :courseid AND action = :action',
            $key + ['now' => time()]
        );
    }

    /**
     * Totals of the last months, every month listed even when nothing happened.
     *
     * @param int $courseid Only this course; 0 for the whole site.
     * @param int $months How many months, the current one included.
     * @param int|null $now Unix time of "now", for the tests.
     * @return array Newest first: ['period' => YYYYMM, 'listen' => n, 'audio' => n, 'pdf' => n].
     */
    public static function monthly(int $courseid = 0, int $months = 12, ?int $now = null): array {
        global $DB;

        $last = self::period($now);
        $first = self::shift($last, 1 - $months);

        $where = 'period >= :first AND period <= :last';
        $params = ['first' => $first, 'last' => $last];
        if ($courseid) {
            $where .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        $records = $DB->get_recordset_sql(
            'SELECT period, action, SUM(total) AS total
               FROM {' . self::TABLE . '}
              WHERE ' . $where . '
           GROUP BY period, action',
            $params
        );

        $rows = [];
        for ($period = $last; $period >= $first; $period = self::shift($period, -1)) {
            $rows[$period] = ['period' => $period] + array_fill_keys(self::ACTIONS, 0);
        }
        foreach ($records as $record) {
            if (isset($rows[$record->period]) && in_array($record->action, self::ACTIONS, true)) {
                $rows[$record->period][$record->action] = (int) $record->total;
            }
        }
        $records->close();

        return array_values($rows);
    }

    /**
     * Totals of one month for each course where something happened.
     *
     * @param int $period YYYYMM.
     * @return array Most listened first: ['courseid' => id, 'listen' => n, 'audio' => n, 'pdf' => n].
     */
    public static function by_course(int $period): array {
        global $DB;

        $records = $DB->get_recordset(self::TABLE, ['period' => $period], '', 'id, courseid, action, total');
        $rows = [];
        foreach ($records as $record) {
            if (!in_array($record->action, self::ACTIONS, true)) {
                continue;
            }
            $rows[$record->courseid] ??= ['courseid' => (int) $record->courseid] + array_fill_keys(self::ACTIONS, 0);
            $rows[$record->courseid][$record->action] += (int) $record->total;
        }
        $records->close();

        uasort($rows, fn($a, $b) => [$b[self::LISTEN], $b[self::AUDIO] + $b[self::PDF], $a['courseid']]
            <=> [$a[self::LISTEN], $a[self::AUDIO] + $a[self::PDF], $b['courseid']]);
        return array_values($rows);
    }

    /**
     * Every month and course with totals, oldest first, for the download.
     *
     * @param int $courseid Only this course; 0 for every course.
     * @return array ['period' => YYYYMM, 'courseid' => id, 'listen' => n, 'audio' => n, 'pdf' => n].
     */
    public static function all(int $courseid = 0): array {
        global $DB;

        $records = $DB->get_recordset(
            self::TABLE,
            $courseid ? ['courseid' => $courseid] : [],
            'period ASC, courseid ASC',
            'id, period, courseid, action, total'
        );
        $rows = [];
        foreach ($records as $record) {
            if (!in_array($record->action, self::ACTIONS, true)) {
                continue;
            }
            $key = $record->period . '-' . $record->courseid;
            $rows[$key] ??= ['period' => (int) $record->period, 'courseid' => (int) $record->courseid]
                + array_fill_keys(self::ACTIONS, 0);
            $rows[$key][$record->action] += (int) $record->total;
        }
        $records->close();

        return array_values($rows);
    }

    /**
     * Name of each course that has totals, for the course filter and the tables.
     *
     * @param int[]|null $courseids Only these courses; all with totals by default.
     * @return string[] Course id => name; deleted courses are named by their id.
     */
    public static function course_names(?array $courseids = null): array {
        global $DB;

        if ($courseids === null) {
            $courseids = $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {' . self::TABLE . '}');
        }
        $courseids = array_unique(array_map('intval', $courseids));
        if (!$courseids) {
            return [];
        }

        $courses = $DB->get_records_list('course', 'id', $courseids, '', 'id, fullname');
        $names = [];
        foreach ($courseids as $courseid) {
            if ($courseid == SITEID) {
                $names[$courseid] = get_string('usagesite', 'block_ahotts_embhl');
            } else if (isset($courses[$courseid])) {
                $names[$courseid] = format_string(
                    $courses[$courseid]->fullname,
                    true,
                    ['context' => \context_course::instance($courseid)]
                );
            } else {
                $names[$courseid] = get_string('usagedeletedcourse', 'block_ahotts_embhl', $courseid);
            }
        }
        \core_collator::asort($names);
        return $names;
    }
}
