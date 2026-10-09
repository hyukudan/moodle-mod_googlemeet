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

/**
 * "Join the live class" entry point (ANA-02): logs the intention to join and resolves the Meet URL.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class room_entry {

    /** @var int Seconds before the start in which a session already counts as the one being joined. */
    public const EARLY_WINDOW = 30 * MINSECS;

    /** @var string Valid Google Meet room URL. */
    public const MEET_URL_PATTERN = '/^https:\/\/meet\.google\.com\/[-a-zA-Z0-9@:%._\+~#=]{3}-[-a-zA-Z0-9@:%._\+~#=]{4}-[-a-zA-Z0-9@:%._\+~#=]{3}$/';

    /**
     * The session (googlemeet_events row) being joined at $now: live, or starting within the early window.
     *
     * @param int $googlemeetid Activity instance id.
     * @param int|null $now Timestamp (defaults to now).
     * @return \stdClass|null
     */
    public static function current_session(int $googlemeetid, ?int $now = null): ?\stdClass {
        global $DB;

        $now = $now ?? time();
        $records = $DB->get_records_select('googlemeet_events',
            'googlemeetid = :googlemeetid AND eventdate <= :earliest AND eventdate + duration >= :now',
            ['googlemeetid' => $googlemeetid, 'earliest' => $now + self::EARLY_WINDOW, 'now' => $now],
            'eventdate ASC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * The activity's Meet URL when it is a valid room URL.
     *
     * @param \stdClass $googlemeet Activity record.
     * @return string|null
     */
    public static function room_url(\stdClass $googlemeet): ?string {
        $url = trim((string)($googlemeet->url ?? ''));
        return ($url !== '' && preg_match(self::MEET_URL_PATTERN, $url)) ? $url : null;
    }

    /**
     * Trigger the room_entered event.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param \stdClass|\cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param string $via "web" or "mobile".
     * @return int Session id logged (0 when no session is live).
     */
    public static function log(\stdClass $googlemeet, $cm, \context_module $context, string $via = 'web'): int {
        $session = self::current_session((int)$googlemeet->id);
        $sessionid = $session ? (int)$session->id : 0;

        $event = \mod_googlemeet\event\room_entered::create([
            'context' => $context,
            'objectid' => $googlemeet->id,
            'other' => [
                'sessionid' => $sessionid,
                'via' => $via === 'mobile' ? 'mobile' : 'web',
            ],
        ]);
        $event->add_record_snapshot('googlemeet', $googlemeet);
        $event->trigger();

        return $sessionid;
    }

    /**
     * URL of the logging endpoint used by the "Join" button.
     *
     * @param int $cmid Course module id.
     * @return \moodle_url
     */
    public static function entry_url(int $cmid): \moodle_url {
        return new \moodle_url('/mod/googlemeet/enter.php', ['id' => $cmid, 'sesskey' => sesskey()]);
    }
}
