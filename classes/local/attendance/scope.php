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

namespace mod_googlemeet\local\attendance;

/**
 * Site switch and Google OAuth scope management for Meet attendance (ANA-03).
 *
 * The Meet REST API scope is only requested while the site setting is on. Turning it on does not
 * upgrade the tokens teachers already granted: Moodle keeps refreshing the old refresh token, so
 * the teacher who organises the meetings has to unlink and link the Google account again. We
 * remember that relink in a user preference and show a notice until it happens.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope {

    /** @var string Read-only scope of the Google Meet REST API (conferenceRecords + participants). */
    public const MEET_SCOPE = 'https://www.googleapis.com/auth/meetings.space.readonly';

    /** @var string User preference: time the user linked Google with the Meet scope (0/absent = not yet). */
    public const PREF_GRANTED = 'mod_googlemeet_meetscope';

    /**
     * Whether real attendance is enabled for the site.
     *
     * @return bool
     */
    public static function site_enabled(): bool {
        return !empty(get_config('googlemeet', 'attendanceenabled'));
    }

    /**
     * Whether attendance is enabled for the site and the activity.
     *
     * @param \stdClass $googlemeet Activity record.
     * @return bool
     */
    public static function activity_enabled(\stdClass $googlemeet): bool {
        return self::site_enabled() && !empty($googlemeet->attendanceenabled);
    }

    /**
     * Whether the user linked Google after the Meet scope started being requested.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function granted(int $userid): bool {
        return !empty(get_user_preferences(self::PREF_GRANTED, 0, $userid));
    }

    /**
     * Called from the OAuth callback after the user linked Google.
     *
     * Records the grant (when the Meet scope was requested) and removes refresh tokens left from
     * earlier links with other scopes: core looks the refresh token up by user and issuer only, so
     * a stale row could be picked instead of the new one.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function after_login(int $userid): void {
        global $DB;

        $issuerid = (int)get_config('googlemeet', 'issuerid');
        if ($issuerid > 0) {
            // Core updates the row with the same scopehash in place, so the token just stored is the
            // most recently modified one, not necessarily the highest id.
            $rows = $DB->get_records_select('oauth2_refresh_token', 'userid = ? AND issuerid = ?',
                [$userid, $issuerid], 'timemodified DESC, id DESC', 'id');
            if (count($rows) > 1) {
                $ids = array_map('intval', array_keys($rows));
                array_shift($ids);
                [$insql, $params] = $DB->get_in_or_equal($ids);
                $DB->delete_records_select('oauth2_refresh_token', "id $insql", $params);
            }
        }

        if (self::site_enabled()) {
            set_user_preference(self::PREF_GRANTED, time(), $userid);
        }
    }

    /**
     * Forget the user's Google link so the next login asks for every current scope.
     *
     * @param int $userid User id (must be the current user for the session token to be cleared).
     * @return void
     */
    public static function reset_link(int $userid): void {
        global $DB, $SESSION, $USER;

        $issuerid = (int)get_config('googlemeet', 'issuerid');
        if ($issuerid > 0) {
            $DB->delete_records('oauth2_refresh_token', ['userid' => $userid, 'issuerid' => $issuerid]);
            if ((int)$USER->id === $userid) {
                unset($SESSION->{'oauth2-state-' . $issuerid});
            }
        }
        \mod_googlemeet\client::purge_userinfo_cache();
        unset_user_preference(self::PREF_GRANTED, $userid);
    }

    /**
     * Mark that Google rejected the Meet scope for this user (token without the scope).
     *
     * @param int $userid User id.
     * @return void
     */
    public static function mark_missing(int $userid): void {
        unset_user_preference(self::PREF_GRANTED, $userid);
    }

    /**
     * Whether $user is the organiser of the activity (creatoremail).
     *
     * @param \stdClass $googlemeet Activity record.
     * @param \stdClass $user User.
     * @return bool
     */
    public static function is_organiser(\stdClass $googlemeet, \stdClass $user): bool {
        if (empty($googlemeet->creatoremail) || empty($user->email)) {
            return false;
        }
        return \core_text::strtolower(trim((string)$googlemeet->creatoremail))
            === \core_text::strtolower(trim((string)$user->email));
    }

    /**
     * Whether the "link your Google account again" notice applies to this user and activity.
     *
     * Only the organiser can fix it (the fetch runs with the organiser's token), so only the
     * organiser gets the notice with the action; other teachers get organiser_relink_pending().
     *
     * @param \stdClass $googlemeet Activity record.
     * @param \context_module $context Module context.
     * @param \stdClass $user User.
     * @return bool
     */
    public static function needs_relink(\stdClass $googlemeet, \context_module $context, \stdClass $user): bool {
        global $DB;

        if (!self::activity_enabled($googlemeet) || !has_capability('mod/googlemeet:viewreports', $context, $user)
                || !self::is_organiser($googlemeet, $user)) {
            return false;
        }
        if (!self::granted((int)$user->id)) {
            return true;
        }
        return $DB->record_exists('googlemeet_attendance_sync', ['googlemeetid' => $googlemeet->id, 'status' => 'scope']);
    }

    /**
     * Whether a non-organiser teacher should be told that the organiser must link Google again.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param \context_module $context Module context.
     * @param \stdClass $user User.
     * @return bool
     */
    public static function organiser_relink_pending(\stdClass $googlemeet, \context_module $context, \stdClass $user): bool {
        global $DB;
        if (!self::activity_enabled($googlemeet) || !has_capability('mod/googlemeet:viewreports', $context, $user)
                || self::is_organiser($googlemeet, $user)) {
            return false;
        }
        return $DB->record_exists('googlemeet_attendance_sync', ['googlemeetid' => $googlemeet->id, 'status' => 'scope']);
    }

    /**
     * The relink notice HTML, or '' when it does not apply.
     *
     * @param \stdClass $googlemeet Activity record.
     * @param \context_module $context Module context.
     * @return string
     */
    public static function relink_notice(\stdClass $googlemeet, \context_module $context): string {
        global $OUTPUT, $USER;

        if (!self::needs_relink($googlemeet, $context, $USER)) {
            if (self::organiser_relink_pending($googlemeet, $context, $USER)) {
                // Informative only: unlinking this teacher's own account would not help.
                return $OUTPUT->notification(get_string('attendance_relink_organiser', 'googlemeet',
                    s((string)$googlemeet->creatoremail)), \core\output\notification::NOTIFY_INFO);
            }
            return '';
        }
        $url = new \moodle_url('/mod/googlemeet/attendance.php', ['id' => $context->instanceid, 'action' => 'relink',
            'sesskey' => sesskey()]);
        return $OUTPUT->notification(get_string('attendance_relink_notice', 'googlemeet', $url->out()),
            \core\output\notification::NOTIFY_WARNING);
    }
}
