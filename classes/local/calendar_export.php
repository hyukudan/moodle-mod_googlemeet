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

namespace mod_googlemeet\local;

use stdClass;

/**
 * "Add to my calendar" (NOT-04): per-activity iCalendar export and Google Calendar template link.
 *
 * The export lists every upcoming session as its own VEVENT (expanded events rather than an
 * RRULE), because googlemeet_events already reflects holiday periods, cancelled dates and manual
 * edits that a single recurrence rule cannot express. Times are written in UTC ("Z" suffix), which
 * every client converts to the viewer's zone, so there is no VTIMEZONE to get wrong; the viewer's
 * zone is announced in X-WR-TIMEZONE as a display hint. UIDs are stable per session so a
 * re-import updates the entries instead of duplicating them.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar_export {

    /**
     * Upcoming (or in progress) sessions of an activity, cancelled ones left out.
     *
     * @param int $googlemeetid Activity id.
     * @param int $now Reference timestamp.
     * @return stdClass[] Events (id, eventdate, duration) in chronological order.
     */
    public static function get_upcoming_events(int $googlemeetid, int $now): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
        $events = $DB->get_records_select('googlemeet_events', 'googlemeetid = :gid AND eventdate + duration > :now',
            ['gid' => $googlemeetid, 'now' => $now], 'eventdate ASC, id ASC', 'id, eventdate, duration');
        $cancelled = \googlemeet_get_cancelled($googlemeetid);
        if ($cancelled) {
            $events = array_filter($events, static function($event) use ($cancelled) {
                return \googlemeet_is_cancelled((int)$event->eventdate, $cancelled) === false;
            });
        }
        return array_values($events);
    }

    /**
     * Build the iCalendar document for an activity.
     *
     * @param stdClass $googlemeet Activity record (id, name, url).
     * @param int $cmid Course module id.
     * @param string $coursename Course name (plain text).
     * @param stdClass[] $events Sessions to export.
     * @param int $now DTSTAMP.
     * @param string $timezone Display-hint timezone (e.g. Europe/Madrid).
     * @return string text/calendar content (CRLF line endings, folded at 75 octets).
     */
    public static function build_ics(stdClass $googlemeet, int $cmid, string $coursename, array $events, int $now,
            string $timezone): string {
        global $CFG;

        $host = parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'moodle';
        $name = format_string($googlemeet->name, true, ['context' => \context_module::instance($cmid)]);
        $description = self::description($googlemeet, $cmid);
        $activityurl = (new \moodle_url('/mod/googlemeet/view.php', ['id' => $cmid]))->out(false);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Moodle//mod_googlemeet//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escape_text(get_string('calendar_ics_name', 'googlemeet',
                (object)['name' => $name, 'course' => $coursename])),
            'X-WR-TIMEZONE:' . self::escape_text($timezone),
        ];
        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:googlemeet-' . (int)$event->id . '@' . $host;
            $lines[] = 'DTSTAMP:' . self::utc($now);
            $lines[] = 'DTSTART:' . self::utc((int)$event->eventdate);
            $lines[] = 'DTEND:' . self::utc((int)$event->eventdate + max(0, (int)$event->duration));
            $lines[] = 'SUMMARY:' . self::escape_text($name);
            $lines[] = 'DESCRIPTION:' . self::escape_text($description);
            if (!empty($googlemeet->url)) {
                $lines[] = 'LOCATION:' . self::escape_text((string)$googlemeet->url);
            }
            $lines[] = 'URL:' . $activityurl;
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /**
     * Google Calendar "create event" template link for one session.
     *
     * @param stdClass $googlemeet Activity record (id, name, url).
     * @param int $cmid Course module id.
     * @param stdClass $event Session (eventdate, duration).
     * @return string URL.
     */
    public static function google_calendar_url(stdClass $googlemeet, int $cmid, stdClass $event): string {
        $params = [
            'action' => 'TEMPLATE',
            'text' => format_string($googlemeet->name, true, ['context' => \context_module::instance($cmid)]),
            'dates' => self::utc((int)$event->eventdate) . '/' .
                self::utc((int)$event->eventdate + max(0, (int)$event->duration)),
            'details' => self::description($googlemeet, $cmid),
        ];
        if (!empty($googlemeet->url)) {
            $params['location'] = (string)$googlemeet->url;
        }
        return 'https://calendar.google.com/calendar/render?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Template context for the "Add to my calendar" links of the live-class block.
     *
     * @param stdClass $googlemeet Activity record.
     * @param int $cmid Course module id.
     * @param int $now Reference timestamp.
     * @return array hascalendarlinks, calendaricsurl, calendargoogleurl.
     */
    public static function hero_context(stdClass $googlemeet, int $cmid, int $now): array {
        $events = self::get_upcoming_events((int)$googlemeet->id, $now);
        if (!$events) {
            return ['hascalendarlinks' => false];
        }
        // The next session that has not started yet (an in-progress one is not worth adding).
        $next = null;
        foreach ($events as $event) {
            if ((int)$event->eventdate > $now) {
                $next = $event;
                break;
            }
        }
        return [
            'hascalendarlinks' => true,
            'calendaricsurl' => (new \moodle_url('/mod/googlemeet/calendar.php', ['id' => $cmid]))->out(false),
            'hascalendargoogle' => $next !== null,
            'calendargoogleurl' => $next ? self::google_calendar_url($googlemeet, $cmid, $next) : '',
        ];
    }

    /**
     * Plain-text event description (join link + activity page).
     *
     * @param stdClass $googlemeet Activity record.
     * @param int $cmid Course module id.
     * @return string
     */
    protected static function description(stdClass $googlemeet, int $cmid): string {
        $parts = [];
        if (!empty($googlemeet->url)) {
            $parts[] = get_string('calendar_ics_join', 'googlemeet', (string)$googlemeet->url);
        }
        $parts[] = get_string('calendar_ics_activity', 'googlemeet',
            (new \moodle_url('/mod/googlemeet/view.php', ['id' => $cmid]))->out(false));
        return implode("\n", $parts);
    }

    /**
     * Format a timestamp as an iCalendar UTC date-time.
     *
     * @param int $timestamp Unix timestamp.
     * @return string e.g. 20261009T160000Z
     */
    public static function utc(int $timestamp): string {
        return gmdate('Ymd\THis\Z', $timestamp);
    }

    /**
     * Escape a TEXT value (RFC 5545 3.3.11).
     *
     * @param string $text Raw text.
     * @return string
     */
    public static function escape_text(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }

    /**
     * Fold a content line at 75 octets without splitting UTF-8 characters (RFC 5545 3.1).
     *
     * @param string $line Unfolded line.
     * @return string Folded line (CRLF + space continuations).
     */
    public static function fold(string $line): string {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = [];
        $current = '';
        $limit = 75;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out[] = $current;
                $current = '';
                $limit = 74; // Continuation lines start with a space.
            }
            $current .= $char;
        }
        $out[] = $current;
        return implode("\r\n ", $out);
    }
}
