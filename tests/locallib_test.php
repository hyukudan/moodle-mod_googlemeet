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

use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
// locallib.php holds global functions that are not autoloaded; load it (and lib.php)
// explicitly so the tested functions are available.
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Unit tests for locallib.php / lib.php pure functions.
 *
 * Covers:
 *   - googlemeet_is_holiday()  (lib.php)
 *   - googlemeet_is_cancelled() (lib.php)
 *   - googlemeet_clear_url()   (locallib.php)
 *   - googlemeet_construct_events_data_for_add() (locallib.php) — requires DB fixtures
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('googlemeet_is_holiday')]
#[CoversFunction('googlemeet_is_cancelled')]
#[CoversFunction('googlemeet_clear_url')]
#[CoversFunction('googlemeet_construct_events_data_for_add')]
class locallib_test extends \advanced_testcase {

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal holiday stdClass like the DB returns.
     *
     * @param int $startdate Unix timestamp (any time during the start day).
     * @param int $enddate   Unix timestamp (any time during the end day).
     * @return \stdClass
     */
    private function make_holiday(int $startdate, int $enddate): \stdClass {
        $h = new \stdClass();
        $h->startdate = $startdate;
        $h->enddate   = $enddate;
        return $h;
    }

    /**
     * Build a minimal cancelled-date stdClass like the DB returns.
     *
     * @param int    $cancelleddate Unix timestamp for the cancelled day.
     * @param string $reason        Optional cancellation reason.
     * @return \stdClass
     */
    private function make_cancelled(int $cancelleddate, string $reason = ''): \stdClass {
        $c = new \stdClass();
        $c->cancelleddate = $cancelleddate;
        $c->reason        = $reason;
        return $c;
    }

    // =========================================================================
    // googlemeet_is_holiday()
    // =========================================================================

    /**
     * An empty holidays array means nothing is a holiday.
     *
     */
    public function test_is_holiday_empty_array(): void {
        $this->assertFalse(googlemeet_is_holiday(mktime(10, 0, 0, 6, 15, 2026), []));
    }

    /**
     * Timestamp exactly on the start day of a single-day holiday.
     *
     */
    public function test_is_holiday_on_start_day(): void {
        // Holiday: 15-Jun-2026 (single day).
        $day = mktime(0, 0, 0, 6, 15, 2026);
        $holidays = [$this->make_holiday($day, $day)];
        // Timestamp in the middle of that day.
        $this->assertTrue(googlemeet_is_holiday(mktime(14, 30, 0, 6, 15, 2026), $holidays));
    }

    /**
     * Timestamp exactly on the end day of a multi-day holiday (inclusive boundary).
     *
     */
    public function test_is_holiday_on_end_day(): void {
        // Holiday: 10-Jun-2026 to 20-Jun-2026.
        $start = mktime(0, 0, 0, 6, 10, 2026);
        $end   = mktime(0, 0, 0, 6, 20, 2026);
        $holidays = [$this->make_holiday($start, $end)];

        // End day — must be inside.
        $this->assertTrue(googlemeet_is_holiday(mktime(9, 0, 0, 6, 20, 2026), $holidays));
    }

    /**
     * Timestamp one day before the holiday start — must not be a holiday.
     *
     */
    public function test_is_holiday_day_before_start(): void {
        $start = mktime(0, 0, 0, 6, 10, 2026);
        $end   = mktime(0, 0, 0, 6, 20, 2026);
        $holidays = [$this->make_holiday($start, $end)];

        $this->assertFalse(googlemeet_is_holiday(mktime(23, 59, 59, 6, 9, 2026), $holidays));
    }

    /**
     * Timestamp one day after the holiday end — must not be a holiday.
     *
     */
    public function test_is_holiday_day_after_end(): void {
        $start = mktime(0, 0, 0, 6, 10, 2026);
        $end   = mktime(0, 0, 0, 6, 20, 2026);
        $holidays = [$this->make_holiday($start, $end)];

        $this->assertFalse(googlemeet_is_holiday(mktime(0, 0, 0, 6, 21, 2026), $holidays));
    }

    /**
     * A date that falls in the middle of a holiday range is a holiday.
     *
     */
    public function test_is_holiday_inside_range(): void {
        $start = mktime(0, 0, 0, 6, 10, 2026);
        $end   = mktime(0, 0, 0, 6, 20, 2026);
        $holidays = [$this->make_holiday($start, $end)];

        $this->assertTrue(googlemeet_is_holiday(mktime(12, 0, 0, 6, 15, 2026), $holidays));
    }

    /**
     * Multiple holidays — date matches the second range.
     *
     */
    public function test_is_holiday_multiple_ranges_second_matches(): void {
        $holidays = [
            $this->make_holiday(mktime(0, 0, 0, 1, 1, 2026), mktime(0, 0, 0, 1, 5, 2026)),
            $this->make_holiday(mktime(0, 0, 0, 8, 1, 2026), mktime(0, 0, 0, 8, 15, 2026)),
        ];

        // Falls in the August range.
        $this->assertTrue(googlemeet_is_holiday(mktime(10, 0, 0, 8, 10, 2026), $holidays));
    }

    /**
     * Multiple holidays — date falls between them (not a holiday).
     *
     */
    public function test_is_holiday_between_two_ranges(): void {
        $holidays = [
            $this->make_holiday(mktime(0, 0, 0, 1, 1, 2026), mktime(0, 0, 0, 1, 5, 2026)),
            $this->make_holiday(mktime(0, 0, 0, 8, 1, 2026), mktime(0, 0, 0, 8, 15, 2026)),
        ];

        // March — between both ranges.
        $this->assertFalse(googlemeet_is_holiday(mktime(10, 0, 0, 3, 1, 2026), $holidays));
    }

    // =========================================================================
    // googlemeet_is_cancelled()
    // =========================================================================

    /**
     * Empty cancelled list — nothing is cancelled.
     *
     */
    public function test_is_cancelled_empty_list(): void {
        $this->assertFalse(googlemeet_is_cancelled(mktime(10, 0, 0, 6, 15, 2026), []));
    }

    /**
     * Timestamp matching a cancelled date returns the cancelled object.
     *
     */
    public function test_is_cancelled_matching_date(): void {
        $day = mktime(0, 0, 0, 6, 15, 2026);
        $cancelled = [$this->make_cancelled($day, 'Teacher sick')];

        $result = googlemeet_is_cancelled(mktime(14, 30, 0, 6, 15, 2026), $cancelled);
        $this->assertNotFalse($result);
        $this->assertEquals('Teacher sick', $result->reason);
    }

    /**
     * Timestamp one day off from a cancelled date returns false.
     *
     */
    public function test_is_cancelled_different_day(): void {
        $day = mktime(0, 0, 0, 6, 15, 2026);
        $cancelled = [$this->make_cancelled($day)];

        // One day later.
        $this->assertFalse(googlemeet_is_cancelled(mktime(10, 0, 0, 6, 16, 2026), $cancelled));
    }

    /**
     * Same calendar day, different time within the day — still cancelled (day-level comparison).
     *
     */
    public function test_is_cancelled_same_day_different_time(): void {
        $day = mktime(8, 0, 0, 6, 15, 2026);
        $cancelled = [$this->make_cancelled($day)];

        // Different time but same calendar date.
        $result = googlemeet_is_cancelled(mktime(22, 0, 0, 6, 15, 2026), $cancelled);
        $this->assertNotFalse($result);
    }

    /**
     * Multiple cancelled dates — only the matching one is returned.
     *
     */
    public function test_is_cancelled_multiple_dates_only_one_matches(): void {
        $cancelled = [
            $this->make_cancelled(mktime(0, 0, 0, 3, 10, 2026), 'Reason A'),
            $this->make_cancelled(mktime(0, 0, 0, 5, 20, 2026), 'Reason B'),
            $this->make_cancelled(mktime(0, 0, 0, 9, 1,  2026), 'Reason C'),
        ];

        // Only the May date should match.
        $result = googlemeet_is_cancelled(mktime(10, 0, 0, 5, 20, 2026), $cancelled);
        $this->assertNotFalse($result);
        $this->assertEquals('Reason B', $result->reason);

        // A date not in the list returns false.
        $this->assertFalse(googlemeet_is_cancelled(mktime(10, 0, 0, 7, 4, 2026), $cancelled));
    }

    /**
     * Cancelled reason can be an empty string — still matches.
     *
     */
    public function test_is_cancelled_empty_reason(): void {
        $day = mktime(0, 0, 0, 4, 1, 2026);
        $cancelled = [$this->make_cancelled($day, '')];
        $result = googlemeet_is_cancelled(mktime(9, 0, 0, 4, 1, 2026), $cancelled);
        $this->assertNotFalse($result);
        $this->assertSame('', $result->reason);
    }

    // =========================================================================
    // googlemeet_clear_url()
    // =========================================================================

    /**
     * A full Meet URL is normalised to the canonical https:// form.
     *
     */
    public function test_clear_url_full_meet_url(): void {
        $url = 'https://meet.google.com/abc-defg-hij';
        $this->assertEquals($url, googlemeet_clear_url($url));
    }

    /**
     * A Meet URL with extra query parameters is cleaned to just the canonical form.
     *
     */
    public function test_clear_url_with_extra_params(): void {
        $url    = 'https://meet.google.com/xyz-abcd-efg?authuser=0&hl=es';
        $result = googlemeet_clear_url($url);
        $this->assertEquals('https://meet.google.com/xyz-abcd-efg', $result);
    }

    /**
     * HTTP (not HTTPS) input: the extracted URL is still returned with https://.
     *
     */
    public function test_clear_url_http_input(): void {
        $url    = 'http://meet.google.com/aaa-bbbb-ccc';
        $result = googlemeet_clear_url($url);
        $this->assertEquals('https://meet.google.com/aaa-bbbb-ccc', $result);
    }

    /**
     * A URL that embeds the Meet code inside a longer string is extracted correctly.
     *
     */
    public function test_clear_url_embedded_in_text(): void {
        $raw    = 'Join the meeting at https://meet.google.com/pqr-stuv-wxy and have fun';
        $result = googlemeet_clear_url($raw);
        $this->assertEquals('https://meet.google.com/pqr-stuv-wxy', $result);
    }

    /**
     * Completely invalid input returns null.
     *
     */
    public function test_clear_url_invalid_returns_null(): void {
        $this->assertNull(googlemeet_clear_url('https://zoom.us/j/12345'));
        $this->assertNull(googlemeet_clear_url('not-a-url'));
        $this->assertNull(googlemeet_clear_url(''));
    }

    /**
     * Code segments with wrong lengths are rejected.
     *
     */
    public function test_clear_url_wrong_code_format_returns_null(): void {
        // googlemeet_clear_url() extracts the first 3-4-3 code found anywhere in the
        // string, so a "wrong format" case must contain no valid 3-4-3 code at all.
        // (A longer tail like abc-defg-hijklm would still yield the valid abc-defg-hij
        // prefix — that is intended extraction behaviour, not a rejection case.)
        $this->assertNull(googlemeet_clear_url('https://meet.google.com/ab-cdef-ghi'));   // 2-4-3.
        $this->assertNull(googlemeet_clear_url('https://meet.google.com/abcd-ef-ghij'));  // 4-2-4.
    }

    // =========================================================================
    // googlemeet_construct_events_data_for_add() — DB-backed
    //
    // These tests require a real Moodle database schema (googlemeet_holidays,
    // googlemeet tables) and Moodle global functions (usergetdate, make_timestamp,
    // HOURSECS, MINSECS, DAYSECS, WEEKSECS). They are skipped gracefully when
    // the constants/tables are absent (e.g. plain CLI lint run without bootstrap).
    // =========================================================================

    /**
     * Non-recurring event: single event returned, no holidays set.
     *
     */
    public function test_construct_events_single_no_holiday(): void {
        global $DB, $CFG;

        if (!defined('HOURSECS')) {
            $this->markTestSkipped('Moodle constants not available; run inside a full Moodle PHPUnit bootstrap.');
        }
        $this->resetAfterTest();

        // Insert a minimal googlemeet row so get_holidays() can query it.
        $googlemeetid = $DB->insert_record('googlemeet', (object)[
            'course'        => 1,
            'name'          => 'Test Meet',
            'url'           => 'https://meet.google.com/abc-defg-hij',
            'timemodified'  => time(),
        ]);

        $googlemeet = new \stdClass();
        $googlemeet->id          = $googlemeetid;
        $googlemeet->starthour   = 10;
        $googlemeet->startminute = 0;
        $googlemeet->endhour     = 11;
        $googlemeet->endminute   = 0;
        // A fixed Monday in June 2026.
        $googlemeet->eventdate   = mktime(0, 0, 0, 6, 1, 2026);
        // No recurrence.
        unset($googlemeet->addmultiply);

        $events = googlemeet_construct_events_data_for_add($googlemeet);

        $this->assertCount(1, $events);
        $this->assertEquals($googlemeetid, $events[0]->googlemeetid);
        $this->assertEquals(HOURSECS, $events[0]->duration); // 1 h = 3600 s.
    }

    /**
     * Non-recurring event falls on a holiday → empty event list returned.
     *
     */
    public function test_construct_events_single_on_holiday(): void {
        global $DB;

        if (!defined('HOURSECS')) {
            $this->markTestSkipped('Moodle constants not available; run inside a full Moodle PHPUnit bootstrap.');
        }
        $this->resetAfterTest();

        $googlemeetid = $DB->insert_record('googlemeet', (object)[
            'course'       => 1,
            'name'         => 'Test Meet Holiday',
            'url'          => 'https://meet.google.com/abc-defg-hij',
            'timemodified' => time(),
        ]);

        // Insert a holiday covering the event date.
        $DB->insert_record('googlemeet_holidays', (object)[
            'googlemeetid' => $googlemeetid,
            'startdate'    => mktime(0, 0, 0, 6, 1, 2026),
            'enddate'      => mktime(0, 0, 0, 6, 1, 2026),
            'timemodified' => time(),
        ]);

        $googlemeet = new \stdClass();
        $googlemeet->id          = $googlemeetid;
        $googlemeet->starthour   = 10;
        $googlemeet->startminute = 0;
        $googlemeet->endhour     = 11;
        $googlemeet->endminute   = 0;
        $googlemeet->eventdate   = mktime(0, 0, 0, 6, 1, 2026);
        unset($googlemeet->addmultiply);

        $events = googlemeet_construct_events_data_for_add($googlemeet);

        $this->assertCount(0, $events, 'Event on a holiday should be excluded.');
    }

    /**
     * Recurring event (weekly, Mon+Wed) over a 2-week span — count and day-of-week checks.
     *
     */
    public function test_construct_events_recurring_weekly(): void {
        global $DB, $CFG;

        if (!defined('HOURSECS')) {
            $this->markTestSkipped('Moodle constants not available; run inside a full Moodle PHPUnit bootstrap.');
        }
        $this->resetAfterTest();

        // Ensure week starts on Monday (standard European setting).
        $CFG->calendar_startwday = '1';

        $googlemeetid = $DB->insert_record('googlemeet', (object)[
            'course'       => 1,
            'name'         => 'Test Meet Weekly',
            'url'          => 'https://meet.google.com/abc-defg-hij',
            'timemodified' => time(),
        ]);

        // Start: Monday 2026-06-01.
        $googlemeet = new \stdClass();
        $googlemeet->id           = $googlemeetid;
        $googlemeet->starthour    = 10;
        $googlemeet->startminute  = 0;
        $googlemeet->endhour      = 11;
        $googlemeet->endminute    = 0;
        $googlemeet->eventdate    = mktime(0, 0, 0, 6, 1, 2026);  // Mon.
        $googlemeet->addmultiply  = 1;
        // End after 2 full weeks: 2026-06-14 (Sunday).
        $googlemeet->eventenddate = mktime(0, 0, 0, 6, 14, 2026);
        // Every week (period=1).
        $googlemeet->period       = 1;
        // Mon + Wed.
        $googlemeet->days         = (object)['Mon' => 1, 'Wed' => 1];

        $events = googlemeet_construct_events_data_for_add($googlemeet);

        // Expect: Mon 01/Jun (first event), Wed 03/Jun, Mon 08/Jun, Wed 10/Jun = 4 events.
        $this->assertCount(4, $events, 'Expected 4 events for Mon+Wed over 2 weeks.');

        // All events must fall on Mon or Wed.
        $alloweddows = [1, 3]; // 1=Mon, 3=Wed per PHP date('N').
        foreach ($events as $ev) {
            $dow = (int)date('N', $ev->eventdate);
            $this->assertContains($dow, $alloweddows, "Event on unexpected weekday: " . date('D Y-m-d', $ev->eventdate));
        }
    }

    /**
     * Recurring event every 2 weeks (period=2) produces correct skipping.
     *
     */
    public function test_construct_events_biweekly(): void {
        global $DB, $CFG;

        if (!defined('HOURSECS')) {
            $this->markTestSkipped('Moodle constants not available; run inside a full Moodle PHPUnit bootstrap.');
        }
        $this->resetAfterTest();

        $CFG->calendar_startwday = '1';

        $googlemeetid = $DB->insert_record('googlemeet', (object)[
            'course'       => 1,
            'name'         => 'Test Meet Biweekly',
            'url'          => 'https://meet.google.com/abc-defg-hij',
            'timemodified' => time(),
        ]);

        // Start: Monday 2026-06-01. End: Sunday 2026-06-28 (4 weeks).
        $googlemeet = new \stdClass();
        $googlemeet->id           = $googlemeetid;
        $googlemeet->starthour    = 9;
        $googlemeet->startminute  = 0;
        $googlemeet->endhour      = 10;
        $googlemeet->endminute    = 0;
        $googlemeet->eventdate    = mktime(0, 0, 0, 6, 1, 2026);  // Mon.
        $googlemeet->addmultiply  = 1;
        $googlemeet->eventenddate = mktime(0, 0, 0, 6, 28, 2026);
        $googlemeet->period       = 2; // Every 2 weeks.
        $googlemeet->days         = (object)['Mon' => 1];

        $events = googlemeet_construct_events_data_for_add($googlemeet);

        // First event = Mon Jun 01 (initial). Then biweekly Mondays from Jun 02 onwards:
        // Jun 01 (first), Jun 08 skipped (week 2), Jun 15 (week 3), Jun 22 skipped (week 4).
        // So: Jun 01 + Jun 15 = 2 events.
        $this->assertCount(2, $events, 'Expected 2 events with period=2 over 4 weeks.');
    }

    /**
     * Recurring event: dates that fall in a holiday are excluded.
     *
     */
    public function test_construct_events_recurring_holiday_excluded(): void {
        global $DB, $CFG;

        if (!defined('HOURSECS')) {
            $this->markTestSkipped('Moodle constants not available; run inside a full Moodle PHPUnit bootstrap.');
        }
        $this->resetAfterTest();

        $CFG->calendar_startwday = '1';

        $googlemeetid = $DB->insert_record('googlemeet', (object)[
            'course'       => 1,
            'name'         => 'Test Meet Holiday Skip',
            'url'          => 'https://meet.google.com/abc-defg-hij',
            'timemodified' => time(),
        ]);

        // Holiday on Wed 2026-06-03.
        $DB->insert_record('googlemeet_holidays', (object)[
            'googlemeetid' => $googlemeetid,
            'startdate'    => mktime(0, 0, 0, 6, 3, 2026),
            'enddate'      => mktime(0, 0, 0, 6, 3, 2026),
            'timemodified' => time(),
        ]);

        $googlemeet = new \stdClass();
        $googlemeet->id           = $googlemeetid;
        $googlemeet->starthour    = 10;
        $googlemeet->startminute  = 0;
        $googlemeet->endhour      = 11;
        $googlemeet->endminute    = 0;
        $googlemeet->eventdate    = mktime(0, 0, 0, 6, 1, 2026);  // Mon.
        $googlemeet->addmultiply  = 1;
        $googlemeet->eventenddate = mktime(0, 0, 0, 6, 7, 2026);  // Sun (one week).
        $googlemeet->period       = 1;
        $googlemeet->days         = (object)['Mon' => 1, 'Wed' => 1, 'Fri' => 1];

        $events = googlemeet_construct_events_data_for_add($googlemeet);

        // Mon Jun 01 (first event), Wed Jun 03 (holiday → excluded), Fri Jun 05.
        // Total: 2 events.
        $this->assertCount(2, $events, 'Wednesday should be excluded due to holiday.');

        $eventdates = array_map(fn($e) => date('D', $e->eventdate), $events);
        $this->assertNotContains('Wed', $eventdates, 'Wednesday event must not appear.');
    }

    /**
     * Duration is correctly computed from start/end hours and minutes.
     *
     */
    public function test_construct_events_duration(): void {
        global $DB;

        if (!defined('HOURSECS')) {
            $this->markTestSkipped('Moodle constants not available; run inside a full Moodle PHPUnit bootstrap.');
        }
        $this->resetAfterTest();

        $googlemeetid = $DB->insert_record('googlemeet', (object)[
            'course'       => 1,
            'name'         => 'Duration Test',
            'url'          => 'https://meet.google.com/abc-defg-hij',
            'timemodified' => time(),
        ]);

        $googlemeet = new \stdClass();
        $googlemeet->id          = $googlemeetid;
        $googlemeet->starthour   = 9;
        $googlemeet->startminute = 30;
        $googlemeet->endhour     = 11;
        $googlemeet->endminute   = 0;
        $googlemeet->eventdate   = mktime(0, 0, 0, 7, 1, 2026);
        unset($googlemeet->addmultiply);

        $events = googlemeet_construct_events_data_for_add($googlemeet);

        $this->assertCount(1, $events);
        // 9:30 → 11:00 = 90 minutes = 5400 seconds.
        $this->assertEquals(5400, $events[0]->duration);
    }
}
