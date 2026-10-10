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

use mod_googlemeet\client;
use mod_googlemeet\helper;
use mod_googlemeet\task\calendar_delete_event;
use mod_googlemeet\task\calendar_update_event;
use stdClass;

/**
 * Keep the Google Calendar event of an activity in step with Moodle edits and deletions (DAT-04).
 *
 * The event is created on the room creator's primary calendar when the activity is added. Edits
 * (name, date, times, recurrence) are pushed with events.patch and deleting the activity removes
 * the event with events.delete. Both run in adhoc tasks with the creator's stored token so saving
 * the form never waits on (or fails because of) Google. Failures are logged (sync log + mtrace)
 * and the teacher who made the change gets a notice; nothing is retried automatically.
 *
 * Caveat (documented): any manual change made directly in Google Calendar to the summary, times
 * or recurrence is overwritten by the next Moodle edit of those fields.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar_sync {

    /** @var string Calendar the events live on (same as creation). */
    public const CALENDAR_ID = 'primary';

    /** @var string[] Fields whose change must be pushed to Google. */
    private const SYNCED_FIELDS = ['name', 'eventdate', 'eventenddate', 'starthour', 'startminute', 'endhour',
        'endminute', 'addmultiply', 'days', 'period'];

    /** @var callable|null Test seam: fn(stdClass $user): ?object returning a rest-like service with call(). */
    private static $servicefactory = null;

    /**
     * Override how the authenticated REST service is built (PHPUnit only).
     *
     * @param callable|null $factory fn(stdClass $user): ?object, null restores the default.
     * @return void
     */
    public static function set_service_factory(?callable $factory): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('calendar_sync::set_service_factory() is for unit tests only.');
        }
        self::$servicefactory = $factory;
    }

    /**
     * Turn the stored "eventid" into a Calendar API event id.
     *
     * add_instance() stores the `eid` parameter of the event htmlLink, which is base64url of
     * "<eventId> <calendarEmail>". Older/other values that are not in that form are returned as-is.
     *
     * @param string|null $stored Value of googlemeet.eventid.
     * @return string Event id, '' when none.
     */
    public static function decode_event_id(?string $stored): string {
        $stored = trim((string)$stored);
        if ($stored === '') {
            return '';
        }
        $b64 = strtr($stored, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, true);
        if ($decoded !== false && preg_match('/^([A-Za-z0-9_]+) \S+$/', $decoded, $m)) {
            return self::series_id($m[1]);
        }
        return self::series_id($stored);
    }

    /**
     * Id of the recurring series for an instance id ("<id>_20260302T173000Z" or "<id>_20260302").
     *
     * Google's own ids only use base32hex characters, so an underscore can only start the instance
     * suffix that htmlLink carries for recurring events; patching or deleting that id would act on
     * the first session only instead of the series.
     *
     * @param string $eventid
     * @return string
     */
    public static function series_id(string $eventid): string {
        return preg_replace('/_\d{8}(T\d{6}Z?)?$/', '', $eventid);
    }

    /**
     * Normalise the days field (form array, JSON string or decoded object) to ['Mon' => 1, ...].
     *
     * @param mixed $days
     * @return array Day keys in Sun..Sat order.
     */
    public static function normalise_days($days): array {
        if (is_string($days)) {
            $days = json_decode($days, true);
        }
        $days = (array)($days ?? []);
        $ordered = [];
        foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day) {
            if (!empty($days[$day])) {
                $ordered[$day] = 1;
            }
        }
        return $ordered;
    }

    /**
     * Build the Calendar event resource (summary, start, end, recurrence) for an activity.
     *
     * Times are the instants Moodle uses for its own sessions (eventdate + HH:MM, see
     * googlemeet_construct_events_data_for_add()), formatted as wall-clock time in $timezone and
     * sent with that same timeZone, so Google never re-interprets them in another zone. The RRULE
     * UNTIL is the end of the last day in $timezone, expressed in UTC as RFC 5545 requires.
     *
     * @param stdClass $googlemeet Activity data (form object or DB record).
     * @param string $timezone IANA timezone, e.g. Europe/Madrid.
     * @return array Event resource; 'recurrence' is [] for a single session.
     */
    public static function build_event_body(stdClass $googlemeet, string $timezone): array {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            $tz = \core_date::get_server_timezone_object();
            $timezone = $tz->getName();
        }

        $base = (int)$googlemeet->eventdate;
        $start = $base + (int)$googlemeet->starthour * HOURSECS + (int)$googlemeet->startminute * MINSECS;
        $end = $base + (int)$googlemeet->endhour * HOURSECS + (int)$googlemeet->endminute * MINSECS;
        if ($end <= $start) {
            $end = $start + HOURSECS;
        }

        $recurrence = [];
        if (!empty($googlemeet->addmultiply)) {
            $byday = [];
            $map = ['Sun' => 'SU', 'Mon' => 'MO', 'Tue' => 'TU', 'Wed' => 'WE', 'Thu' => 'TH', 'Fri' => 'FR', 'Sat' => 'SA'];
            foreach (array_keys(self::normalise_days($googlemeet->days ?? [])) as $day) {
                $byday[] = $map[$day];
            }
            $interval = max(1, (int)($googlemeet->period ?? 1));
            $enddate = (int)($googlemeet->eventenddate ?? 0) ?: $base;
            // End of the last day, in the activity timezone, as UTC.
            $untillocal = (new \DateTime('@' . $enddate))->setTimezone($tz)->setTime(23, 59, 59);
            $until = $untillocal->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
            $rule = 'RRULE:FREQ=WEEKLY;INTERVAL=' . $interval . ';UNTIL=' . $until;
            if ($byday) {
                $rule .= ';BYDAY=' . implode(',', $byday);
            }
            $recurrence = [$rule];
        }

        return [
            'summary' => (string)$googlemeet->name,
            'start' => ['dateTime' => self::format_local($start, $tz), 'timeZone' => $timezone],
            'end' => ['dateTime' => self::format_local($end, $tz), 'timeZone' => $timezone],
            'recurrence' => $recurrence,
        ];
    }

    /**
     * Format an instant as local wall-clock time (no offset) in a timezone.
     *
     * @param int $timestamp
     * @param \DateTimeZone $tz
     * @return string Y-m-d\TH:i:s
     */
    private static function format_local(int $timestamp, \DateTimeZone $tz): string {
        return (new \DateTime('@' . $timestamp))->setTimezone($tz)->format('Y-m-d\TH:i:s');
    }

    /**
     * Whether an edit changed something the Calendar event mirrors.
     *
     * @param stdClass $old Record before the update.
     * @param stdClass $new Data being saved.
     * @return bool
     */
    public static function needs_patch(stdClass $old, stdClass $new): bool {
        foreach (self::SYNCED_FIELDS as $field) {
            if ($field === 'days') {
                if (self::normalise_days($old->days ?? null) !== self::normalise_days($new->days ?? null)) {
                    return true;
                }
                continue;
            }
            $multi = !empty($new->addmultiply);
            if (!$multi && in_array($field, ['eventenddate', 'period'], true)) {
                // Not part of a single-session event.
                if (!empty($old->addmultiply)) {
                    return true;
                }
                continue;
            }
            if ($field === 'name') {
                if ((string)($old->name ?? '') !== (string)($new->name ?? '')) {
                    return true;
                }
                continue;
            }
            if ((int)($old->$field ?? 0) !== (int)($new->$field ?? 0)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether another activity references the same Google event (duplicated/restored activity).
     *
     * @param string $storedeventid googlemeet.eventid value.
     * @param int $excludeid Activity to ignore.
     * @return bool
     */
    public static function is_event_shared(string $storedeventid, int $excludeid): bool {
        global $DB;
        if ($storedeventid === '') {
            return false;
        }
        return $DB->record_exists_select('googlemeet', 'eventid = :eventid AND id <> :id',
            ['eventid' => $storedeventid, 'id' => $excludeid]);
    }

    /**
     * Whether another activity (any course) still uses the same Google event or the same Meet room.
     *
     * Restored, duplicated and copied activities (DAT-03) keep the Meet URL but drop the eventid, so
     * comparing eventids alone misses them; the URL / meeting code is what they share.
     *
     * @param string $storedeventid googlemeet.eventid value ('' when unknown).
     * @param string $url Meet URL of the activity ('' when unknown).
     * @param int $excludeid Activity to ignore.
     * @return bool
     */
    public static function is_room_shared(string $storedeventid, string $url, int $excludeid): bool {
        global $DB;
        if (self::is_event_shared($storedeventid, $excludeid)) {
            return true;
        }
        $code = client::extract_meeting_code($url);
        if ($code === null || $code === '') {
            return false;
        }
        $like = $DB->sql_like('url', ':code', false);
        return $DB->record_exists_select('googlemeet', "id <> :id AND {$like}",
            ['id' => $excludeid, 'code' => '%' . $DB->sql_like_escape($code) . '%']);
    }

    /**
     * How the activity is being deleted, from the call stack.
     *
     * Only an explicit deletion of the activity (course_delete_module(), directly or through the
     * asynchronous deletion task) may remove the Google event. Course deletion, "delete existing
     * content" restores and any other path return 'course' / 'other' and leave Google alone: the
     * same room is very often still used by next year's copy of the course.
     *
     * @return string 'activity', 'course' or 'other'.
     */
    public static function deletion_context(): string {
        $functions = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
        if (array_intersect(['remove_course_contents', 'delete_course'], $functions)) {
            return 'course';
        }
        if (in_array('course_delete_module', $functions, true)) {
            return 'activity';
        }
        return 'other';
    }

    /**
     * Queue the events.patch for an edited activity.
     *
     * @param stdClass $googlemeet Activity record (needs id, eventid, creatoremail).
     * @param int $editorid User who saved the form (gets the failure notice).
     * @return bool True when a task was queued.
     */
    public static function queue_update(stdClass $googlemeet, int $editorid): bool {
        if (empty($googlemeet->eventid) || empty($googlemeet->creatoremail)) {
            return false;
        }
        $task = new calendar_update_event();
        $task->set_custom_data(['googlemeetid' => (int)$googlemeet->id, 'editorid' => $editorid]);
        $task->set_component('mod_googlemeet');
        // Two saves in a row by the same teacher collapse into one patch: the task reads the
        // current record when it runs.
        \core\task\manager::queue_adhoc_task($task, true);
        return true;
    }

    /**
     * Queue the events.delete for an activity that is being deleted.
     *
     * Safety rules (the event usually is a recurring series in the teacher's own calendar):
     *  - only when a teacher deletes this activity explicitly, never during course deletion or any
     *    other bulk path (see deletion_context());
     *  - never while another activity, in any course, shares the eventid or the Meet room (URL);
     *  - with the course recycle bin on, the task waits until the bin item expires and re-checks:
     *    restoring the activity from the bin brings back an activity with the same URL, which
     *    cancels the deletion. With a bin that never expires the event is never deleted.
     *
     * Everything the task needs is copied into the custom data because the record is gone by
     * the time it runs. Runs inside web requests too, so it never prints (no mtrace) when skipping.
     *
     * @param stdClass $googlemeet Activity record (still present).
     * @param int $editorid User deleting the activity.
     * @param string|null $context deletion_context() override (tests).
     * @return bool True when a task was queued.
     */
    public static function queue_delete(stdClass $googlemeet, int $editorid, ?string $context = null): bool {
        if (empty($googlemeet->eventid) || empty($googlemeet->creatoremail)) {
            return false;
        }
        $context = $context ?? self::deletion_context();
        if ($context !== 'activity') {
            return false;
        }
        if (self::is_room_shared((string)$googlemeet->eventid, (string)($googlemeet->url ?? ''), (int)$googlemeet->id)) {
            // A duplicate or a copy in another course still uses the same room/event.
            return false;
        }
        $delay = self::recyclebin_delay();
        if ($delay === null) {
            return false;
        }
        $task = new calendar_delete_event();
        $task->set_custom_data([
            'googlemeetid' => (int)$googlemeet->id,
            'eventid' => (string)$googlemeet->eventid,
            'url' => (string)($googlemeet->url ?? ''),
            'creatoremail' => (string)$googlemeet->creatoremail,
            'name' => (string)$googlemeet->name,
            'course' => (int)$googlemeet->course,
            'editorid' => $editorid,
        ]);
        $task->set_component('mod_googlemeet');
        if ($delay > 0) {
            $task->set_next_run_time(time() + $delay);
        }
        \core\task\manager::queue_adhoc_task($task, true);
        return true;
    }

    /**
     * Seconds to wait before deleting the event so a recycle bin restore can still cancel it.
     *
     * @return int|null 0 when the course recycle bin is off, null when its items never expire.
     */
    public static function recyclebin_delay(): ?int {
        if (!\core_component::get_component_directory('tool_recyclebin')
                || !get_config('tool_recyclebin', 'coursebinenable')) {
            return 0;
        }
        $expiry = (int)get_config('tool_recyclebin', 'coursebinexpiry');
        if ($expiry <= 0) {
            return null;
        }
        // One extra day so the bin cleanup (and a last-minute restore) has run first.
        return $expiry + DAYSECS;
    }

    /**
     * Run the patch for one activity (called by the adhoc task).
     *
     * @param int $googlemeetid
     * @param int $editorid
     * @return string Outcome: success, skipped or error.
     */
    public static function apply_update(int $googlemeetid, int $editorid): string {
        global $DB;

        $googlemeet = $DB->get_record('googlemeet', ['id' => $googlemeetid]);
        if (!$googlemeet) {
            self::trace($googlemeetid, 'update', 'skipped', 'activity no longer exists');
            return 'skipped';
        }
        $eventid = self::decode_event_id($googlemeet->eventid ?? '');
        if ($eventid === '' || empty($googlemeet->creatoremail)) {
            self::trace($googlemeetid, 'update', 'skipped', 'no Google event linked');
            return 'skipped';
        }
        if (self::is_event_shared((string)$googlemeet->eventid, $googlemeetid)) {
            // Legacy copies (before DAT-03) share the eventid: neither may drive the event. Logged
            // once per run without notifying the teacher on every save.
            self::trace($googlemeetid, 'update', 'skipped', get_string('calsync_reason_shared', 'googlemeet'));
            return 'skipped';
        }

        return self::with_creator($googlemeet, sync_log::KIND_CALENDAR_UPDATE, $editorid,
            function($service, stdClass $creator) use ($googlemeet, $eventid, $DB) {
                $body = self::build_event_body($googlemeet, \core_date::get_user_timezone($creator));
                helper::request($service, 'updateevent',
                    ['calendarid' => self::CALENDAR_ID, 'eventid' => $eventid], json_encode($body));
                // Drive names future recordings after the event summary.
                $DB->set_field('googlemeet', 'originalname', $googlemeet->name, ['id' => $googlemeet->id]);
            });
    }

    /**
     * Run the deletion of a deleted activity's event (called by the adhoc task).
     *
     * @param array $data Custom data captured by queue_delete().
     * @return string Outcome: success, skipped or error.
     */
    public static function apply_delete(array $data): string {
        $googlemeet = (object)[
            'id' => (int)($data['googlemeetid'] ?? 0),
            'eventid' => (string)($data['eventid'] ?? ''),
            'creatoremail' => (string)($data['creatoremail'] ?? ''),
            'name' => (string)($data['name'] ?? ''),
            'course' => (int)($data['course'] ?? 0),
        ];
        $eventid = self::decode_event_id($googlemeet->eventid);
        if ($eventid === '' || $googlemeet->creatoremail === '') {
            self::trace($googlemeet->id, 'delete', 'skipped', 'no Google event linked');
            return 'skipped';
        }
        if (self::is_room_shared($googlemeet->eventid, (string)($data['url'] ?? ''), $googlemeet->id)) {
            // Re-checked at run time: a duplicate, a course copy or a recycle bin restore may now use it.
            self::trace($googlemeet->id, 'delete', 'skipped', 'event or room shared with another activity');
            return 'skipped';
        }

        return self::with_creator($googlemeet, sync_log::KIND_CALENDAR_DELETE, (int)($data['editorid'] ?? 0),
            function($service) use ($eventid) {
                try {
                    helper::request($service, 'deleteevent', ['calendarid' => self::CALENDAR_ID, 'eventid' => $eventid]);
                } catch (\Exception $e) {
                    // Already deleted by hand in Google: nothing left to do.
                    if (preg_match('/\b(404|410)\s*:/', $e->getMessage())) {
                        return;
                    }
                    throw $e;
                }
            });
    }

    /**
     * Run $callback impersonating the room creator, with a REST service authenticated as them.
     *
     * @param stdClass $googlemeet Activity data (id, name, course, creatoremail).
     * @param string $kind sync_log kind.
     * @param int $editorid User to notify on failure.
     * @param callable $callback fn(object $service, stdClass $creator): void, throws on failure.
     * @return string Outcome: success or error.
     */
    private static function with_creator(stdClass $googlemeet, string $kind, int $editorid, callable $callback): string {
        $creator = self::find_creator((string)$googlemeet->creatoremail, $editorid);
        if (!$creator) {
            self::finish($googlemeet, $kind, 'error',
                get_string('calsync_reason_nocreator', 'googlemeet', s($googlemeet->creatoremail)), $editorid);
            return 'error';
        }

        $impersonation = impersonation::begin($creator);
        try {
            $service = self::make_service($creator, (string)$googlemeet->creatoremail);
            if (!$service) {
                $reason = get_string('calsync_reason_notlinked', 'googlemeet', s($googlemeet->creatoremail));
            } else {
                $callback($service, $creator);
                $reason = null;
            }
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        } finally {
            impersonation::end($impersonation);
        }

        if ($reason !== null) {
            self::finish($googlemeet, $kind, 'error', $reason, $editorid);
            return 'error';
        }
        self::finish($googlemeet, $kind, 'success', '', 0);
        return 'success';
    }

    /**
     * Build the authenticated service for the (already impersonated) creator.
     *
     * @param stdClass $creator
     * @param string $creatoremail Organiser email the token must belong to.
     * @return object|null rest-like service, null when the creator has no usable Google link.
     * @throws \moodle_exception isnotcreatoremail when the linked Google account is another one (a
     *     404 from someone else's calendar must never be reported as "already deleted").
     */
    private static function make_service(stdClass $creator, string $creatoremail): ?object {
        if (self::$servicefactory !== null) {
            return (self::$servicefactory)($creator);
        }
        $client = new client();
        if (!$client->enabled || !$client->check_login()) {
            return null;
        }
        sync_manager::require_creator_account((string)$client->get_email(), $creatoremail);
        return $client->get_rest_service();
    }

    /**
     * Moodle user owning the Google account that created the room.
     *
     * Prefers the editor when their email matches (shared emails are possible in Moodle).
     *
     * @param string $email
     * @param int $preferuserid
     * @return stdClass|null
     */
    public static function find_creator(string $email, int $preferuserid = 0): ?stdClass {
        global $DB;
        if ($email === '') {
            return null;
        }
        $users = $DB->get_records('user', ['email' => $email, 'deleted' => 0, 'suspended' => 0], 'id ASC');
        if (!$users) {
            return null;
        }
        return $users[$preferuserid] ?? reset($users);
    }

    /**
     * Record the outcome and notify the editor on failure.
     *
     * @param stdClass $googlemeet
     * @param string $kind
     * @param string $status success|error
     * @param string $reason Error text.
     * @param int $editorid
     * @return void
     */
    private static function finish(stdClass $googlemeet, string $kind, string $status, string $reason, int $editorid): void {
        $op = $kind === sync_log::KIND_CALENDAR_DELETE ? 'delete' : 'update';
        self::trace((int)$googlemeet->id, $op, $status, $reason);
        $logid = sync_log::start((int)$googlemeet->id, $kind, sync_log::STATUS_RUNNING);
        sync_log::finish($logid, $status === 'success' ? sync_log::STATUS_SUCCESS : sync_log::STATUS_ERROR, null, $reason);

        if ($status !== 'success' && $editorid > 0) {
            self::notify_editor($googlemeet, $op, $reason, $editorid);
        }
    }

    /**
     * Structured mtrace line (OPS-04).
     *
     * @param int $googlemeetid
     * @param string $op
     * @param string $result
     * @param string $detail
     * @return void
     */
    private static function trace(int $googlemeetid, string $op, string $result, string $detail = ''): void {
        mtrace("mod_googlemeet calsync: op={$op} activity={$googlemeetid} result={$result}"
            . ($detail !== '' ? ' detail="' . str_replace('"', "'", $detail) . '"' : ''));
    }

    /**
     * Tell the teacher who edited/deleted the activity that Google Calendar was not updated.
     *
     * @param stdClass $googlemeet
     * @param string $op update|delete
     * @param string $reason
     * @param int $editorid
     * @return void
     */
    private static function notify_editor(stdClass $googlemeet, string $op, string $reason, int $editorid): void {
        global $DB;
        $editor = $DB->get_record('user', ['id' => $editorid, 'deleted' => 0]);
        if (!$editor) {
            return;
        }
        $a = (object)['name' => format_string($googlemeet->name, true, ['escape' => false]), 'reason' => $reason];
        $message = new \core\message\message();
        $message->component = 'mod_googlemeet';
        $message->name = 'notification';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $editor;
        $message->subject = get_string('calsync_failed_subject_' . $op, 'googlemeet', $a);
        $message->fullmessage = get_string('calsync_failed_body_' . $op, 'googlemeet', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = text_to_html($message->fullmessage, false, false, true);
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        if (!empty($googlemeet->course)) {
            $message->courseid = (int)$googlemeet->course;
        }
        try {
            message_send($message);
        } catch (\Throwable $e) {
            mtrace('mod_googlemeet calsync: could not notify user ' . $editorid . ': ' . $e->getMessage());
        }
    }
}
