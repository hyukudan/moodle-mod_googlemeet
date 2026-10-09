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

namespace mod_googlemeet;

use mod_googlemeet\local\calendar_export;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

/**
 * "Add to my calendar" (NOT-04): iCalendar export and Google Calendar link.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(calendar_export::class)]
final class calendar_export_test extends \advanced_testcase {

    /** @var int 2 November 2026 16:00 UTC (17:00 in Madrid, CET). */
    const SESSION1 = 1793635200;

    /**
     * Activity with three sessions: a past one, an upcoming one and a cancelled one.
     *
     * @return array [course, googlemeet, upcoming event id, now]
     */
    private function create_fixture(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'Oposiciones; Auxiliar, 2026']);
        $googlemeet = $gen->create_module('googlemeet', [
            'course' => $course->id,
            'name' => 'Tema 3: Constitución, Título VIII; repaso',
            'url' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $now = self::SESSION1 - 2 * DAYSECS;
        $insert = function(int $eventdate) use ($DB, $googlemeet, $now) {
            return (int)$DB->insert_record('googlemeet_events', (object)[
                'googlemeetid' => $googlemeet->id,
                'eventdate' => $eventdate,
                'duration' => HOURSECS,
                'timemodified' => $now,
            ]);
        };
        $insert($now - DAYSECS);
        $upcoming = $insert(self::SESSION1);
        $cancelled = self::SESSION1 + 7 * DAYSECS;
        $insert($cancelled);
        $DB->insert_record('googlemeet_cancelled', (object)[
            'googlemeetid' => $googlemeet->id,
            'cancelleddate' => $cancelled,
            'reason' => '',
            'timemodified' => $now,
        ]);
        return [$course, $googlemeet, $upcoming, $now];
    }

    /**
     * The export is a valid VCALENDAR: CRLF lines folded at 75 octets, one VEVENT per upcoming,
     * non-cancelled session, UTC times, escaped text and stable UIDs.
     */
    public function test_build_ics(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        [, $googlemeet, $upcoming, $now] = $this->create_fixture();

        $events = calendar_export::get_upcoming_events((int)$googlemeet->id, $now);
        $this->assertCount(1, $events);
        $ics = calendar_export::build_ics($googlemeet, (int)$googlemeet->cmid, 'Oposiciones; Auxiliar, 2026', $events,
            $now, 'Europe/Madrid');

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("\r\nPRODID:", $ics);
        $this->assertSame(0, preg_match("/(?<!\r)\n/", $ics), 'Only CRLF line endings');
        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), $line);
        }

        // Unfold before inspecting values.
        $unfolded = str_replace("\r\n ", '', $ics);
        $this->assertSame(1, substr_count($unfolded, "BEGIN:VEVENT\r\n"));
        $this->assertSame(1, substr_count($unfolded, "END:VEVENT\r\n"));
        $this->assertStringContainsString("\r\nDTSTART:20261102T160000Z\r\n", $unfolded);
        $this->assertStringContainsString("\r\nDTEND:20261102T170000Z\r\n", $unfolded);
        $this->assertStringContainsString("\r\nX-WR-TIMEZONE:Europe/Madrid\r\n", $unfolded);
        $this->assertStringContainsString("\r\nUID:googlemeet-{$upcoming}@", $unfolded);
        $this->assertStringContainsString("\r\nSUMMARY:Tema 3: Constitución\\, Título VIII\\; repaso\r\n", $unfolded);
        $this->assertStringContainsString("\r\nLOCATION:https://meet.google.com/abc-defg-hij\r\n", $unfolded);
        $this->assertStringContainsString('/mod/googlemeet/view.php?id=' . $googlemeet->cmid, $unfolded);
        $this->assertMatchesRegularExpression('/\r\nDESCRIPTION:[^\r]*\\\\n[^\r]*\r\n/', $unfolded);
    }

    /**
     * Folding never splits a multibyte character and continuation lines start with a space.
     */
    public function test_fold_multibyte(): void {
        $line = 'SUMMARY:' . str_repeat('Título ñ ', 30);
        $folded = calendar_export::fold($line);
        foreach (explode("\r\n", $folded) as $i => $part) {
            $this->assertLessThanOrEqual(75, strlen($part));
            $this->assertTrue(mb_check_encoding($part, 'UTF-8'));
            if ($i > 0) {
                $this->assertSame(' ', $part[0]);
            }
        }
        $this->assertSame($line, str_replace("\r\n ", '', $folded));
    }

    /**
     * Google Calendar template link for the next session, in UTC.
     */
    public function test_google_calendar_url_and_hero_context(): void {
        $this->resetAfterTest();
        [, $googlemeet, , $now] = $this->create_fixture();

        $context = calendar_export::hero_context($googlemeet, (int)$googlemeet->cmid, $now);
        $this->assertTrue($context['hascalendarlinks']);
        $this->assertTrue($context['hascalendargoogle']);
        $this->assertStringContainsString('/mod/googlemeet/calendar.php?id=' . $googlemeet->cmid,
            $context['calendaricsurl']);

        $url = $context['calendargoogleurl'];
        $this->assertStringStartsWith('https://calendar.google.com/calendar/render?action=TEMPLATE', $url);
        $this->assertStringContainsString('dates=20261102T160000Z%2F20261102T170000Z', $url);
        $this->assertStringContainsString('location=' . rawurlencode('https://meet.google.com/abc-defg-hij'), $url);

        // No upcoming sessions: no links.
        $this->assertSame(['hascalendarlinks' => false],
            calendar_export::hero_context($googlemeet, (int)$googlemeet->cmid, self::SESSION1 + 30 * DAYSECS));
    }
}
