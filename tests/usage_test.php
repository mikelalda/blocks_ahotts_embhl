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

use block_ahotts_embhl\local\usage;

#[\PHPUnit\Framework\Attributes\CoversClass(\block_ahotts_embhl\local\usage::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_ahotts_embhl\event\usage_base::class)]
/**
 * Tests for the monthly usage totals and their events.
 *
 * @package    block_ahotts_embhl
 * @copyright  2026 Ahotts
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_ahotts_embhl\local\usage
 * @covers     \block_ahotts_embhl\event\usage_base
 */
final class usage_test extends \advanced_testcase {
    public function test_period_follows_the_server_timezone(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid', 'Europe/Madrid');

        // 23:30 UTC on 31 October is already November in Madrid.
        $this->assertSame(202611, usage::period(gmmktime(23, 30, 0, 10, 31, 2026)));
        $this->assertSame(202610, usage::period(gmmktime(12, 0, 0, 10, 31, 2026)));
    }

    public function test_shift_crosses_years(): void {
        $this->assertSame(202512, usage::shift(202601, -1));
        $this->assertSame(202701, usage::shift(202612, 1));
        $this->assertSame(202512, usage::shift(202611, -11));
        $this->assertSame(202611, usage::shift(202611, 0));
    }

    public function test_increment_adds_to_one_total_per_month_course_and_action(): void {
        global $DB;
        $this->resetAfterTest();

        usage::increment(usage::LISTEN, 5, 202611);
        usage::increment(usage::LISTEN, 5, 202611);
        usage::increment(usage::LISTEN, 5, 202610);
        usage::increment(usage::AUDIO, 5, 202611);
        usage::increment(usage::LISTEN, 6, 202611);

        $this->assertSame(4, $DB->count_records(usage::TABLE));
        $november = ['period' => 202611, 'courseid' => 5, 'action' => usage::LISTEN];
        $october = ['period' => 202610, 'courseid' => 5, 'action' => usage::LISTEN];
        $this->assertEquals(2, $DB->get_field(usage::TABLE, 'total', $november));
        $this->assertEquals(1, $DB->get_field(usage::TABLE, 'total', $october));
    }

    public function test_record_logs_an_event_and_counts_it(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $this->setUser($user);

        $sink = $this->redirectEvents();
        usage::record(usage::LISTEN, $context, $course->id, ['language' => 'eu']);
        usage::record(usage::PDF, $context, $course->id, ['source' => 'package']);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(2, $events);
        $this->assertInstanceOf(event\content_listened::class, $events[0]);
        $this->assertInstanceOf(event\pdf_downloaded::class, $events[1]);
        $this->assertSame($context->id, $events[0]->contextid);
        $this->assertEquals($course->id, $events[0]->courseid);
        $this->assertEquals($user->id, $events[0]->userid);
        $this->assertSame('eu', $events[0]->other['language']);
        $this->assertSame('package', $events[1]->other['source']);
        $this->assertNotEmpty($events[0]->get_name());
        $this->assertStringContainsString("'{$user->id}'", $events[0]->get_description());
        $this->assertEquals(new \moodle_url('/mod/page/view.php', ['id' => $page->cmid]), $events[0]->get_url());

        $month = usage::monthly($course->id, 1)[0];
        $this->assertSame([usage::LISTEN => 1, usage::AUDIO => 0, usage::PDF => 1], array_slice($month, 1));
    }

    public function test_record_refuses_an_unknown_action(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        usage::record('print', \context_system::instance(), SITEID);
    }

    public function test_monthly_lists_every_month_newest_first(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC', 'UTC');
        $now = gmmktime(12, 0, 0, 2, 10, 2027);

        usage::increment(usage::LISTEN, 5, 202702);
        usage::increment(usage::LISTEN, 6, 202702);
        usage::increment(usage::AUDIO, 5, 202612);
        // Outside the window: not shown.
        usage::increment(usage::LISTEN, 5, 202611);

        $months = usage::monthly(0, 3, $now);
        $this->assertSame([202702, 202701, 202612], array_column($months, 'period'));
        $this->assertSame([2, 0, 0], array_column($months, usage::LISTEN));
        $this->assertSame([0, 0, 1], array_column($months, usage::AUDIO));

        $course6 = usage::monthly(6, 3, $now);
        $this->assertSame([1, 0, 0], array_column($course6, usage::LISTEN));
        $this->assertSame([0, 0, 0], array_column($course6, usage::AUDIO));
    }

    public function test_by_course_puts_the_most_listened_course_first(): void {
        $this->resetAfterTest();

        usage::increment(usage::LISTEN, 7, 202611);
        for ($i = 0; $i < 3; $i++) {
            usage::increment(usage::LISTEN, 8, 202611);
        }
        usage::increment(usage::PDF, 9, 202611);
        usage::increment(usage::LISTEN, 9, 202610);

        $rows = usage::by_course(202611);
        $this->assertSame([8, 7, 9], array_column($rows, 'courseid'));
        $this->assertSame([3, 1, 0], array_column($rows, usage::LISTEN));
        $this->assertSame([0, 0, 1], array_column($rows, usage::PDF));
    }

    public function test_all_returns_every_month_and_course_oldest_first(): void {
        $this->resetAfterTest();

        usage::increment(usage::AUDIO, 8, 202611);
        usage::increment(usage::LISTEN, 7, 202610);
        usage::increment(usage::LISTEN, 8, 202611);

        $rows = usage::all();
        $this->assertSame([[202610, 7], [202611, 8]], array_map(fn($row) => [$row['period'], $row['courseid']], $rows));
        $this->assertSame(1, $rows[1][usage::LISTEN]);
        $this->assertSame(1, $rows[1][usage::AUDIO]);
        $this->assertCount(1, usage::all(7));
    }

    public function test_course_names_cover_the_site_and_deleted_courses(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Bulegotika']);

        usage::increment(usage::LISTEN, $course->id, 202611);
        usage::increment(usage::LISTEN, SITEID, 202611);
        usage::increment(usage::LISTEN, 999999, 202611);

        $names = usage::course_names();
        $this->assertSame('Bulegotika', $names[$course->id]);
        $this->assertSame(get_string('usagesite', 'block_ahotts_embhl'), $names[SITEID]);
        $this->assertSame(get_string('usagedeletedcourse', 'block_ahotts_embhl', 999999), $names[999999]);
        $this->assertSame([], usage::course_names([]));
    }

    public function test_period_label_names_the_month(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid', 'Europe/Madrid');

        $this->assertStringContainsString('2026', usage::period_label(202611));
        $this->assertStringContainsString('November', usage::period_label(202611));
    }
}
