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
 * Private googlemeet module utility functions
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_googlemeet\client;
use mod_googlemeet\helper;
use mod_googlemeet\question_service;

require_once("$CFG->dirroot/mod/googlemeet/lib.php");

/**
 * Print googlemeet header.
 * @param object $googlemeet
 * @param object $cm
 * @param object $course
 * @return void
 */
function googlemeet_print_header($googlemeet, $cm, $course) {
    global $PAGE, $OUTPUT;

    $PAGE->set_title($course->shortname . ': ' . $googlemeet->name);
    $PAGE->set_heading($course->fullname);
    $PAGE->set_activity_record($googlemeet);
    echo $OUTPUT->header();
}

/**
 * Handle the write actions triggered from the activity view (logout / sync).
 *
 * This centralises the action-dispatch logic that previously lived inline in
 * view.php, keeping that file focused on orchestration and presentation.
 *
 * Both actions require the `mod/googlemeet:editrecording` capability and a valid
 * session key. The `sync` action additionally requires the request to be a POST
 * (it performs DB writes and remote Google calls), so a plain GET to `?sync=1`
 * is ignored even if it carries a valid sesskey. The sync button rendered in
 * googlemeet_print_recordings() already submits via POST, so the user-facing
 * flow is unchanged. The `logout` action is left reachable via GET (sesskey
 * protected) so the OAuth account-management link keeps working as before.
 *
 * No redirect is performed: matching the previous behaviour, the page simply
 * continues rendering after the action runs.
 *
 * @param object $googlemeet the googlemeet instance record
 * @param object $cm the course module record
 * @param object $course the course record
 * @return void
 */
function googlemeet_handle_view_actions($googlemeet, $cm, $course) {
    $context = context_module::instance($cm->id);

    if (!has_capability('mod/googlemeet:editrecording', $context)) {
        return;
    }

    $client = new client();

    $logout = optional_param('logout', 0, PARAM_BOOL);
    if ($logout && confirm_sesskey()) {
        $client->logout();
    }

    $sync = optional_param('sync', 0, PARAM_BOOL);
    // Sync performs DB writes and remote Google calls: only run it for a POST
    // request (in addition to the sesskey check). Ignore plain GETs.
    if ($sync && confirm_sesskey() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $redirecturl = new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]);
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_googlemeet');
        $lock = $lockfactory->get_lock('sync_' . $googlemeet->id, 0);
        if (!$lock) {
            \core\notification::warning(get_string('sync_already_running', 'googlemeet'));
            redirect($redirecturl);
        }

        $stats = ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'trashed' => 0, 'restored' => 0, 'found' => 0];
        try {
            googlemeet_reset_exhausted_autosync_events((int)$googlemeet->id);
            $stats = $client->syncrecordings($googlemeet, true, true) ?: $stats;
        } finally {
            $lock->release();
        }

        $message = $client->build_sync_message($stats, (int)($stats['found'] ?? 0));
        if ((int)($stats['inserted'] ?? 0) > 0 || (int)($stats['restored'] ?? 0) > 0) {
            $message .= ' ' . get_string('sync_enrichment_queued', 'googlemeet');
        }
        $messagetype = ((int)($stats['inserted'] ?? 0) > 0 || (int)($stats['restored'] ?? 0) > 0)
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_INFO;
        redirect($redirecturl, $message, null, $messagetype);
    }
}

/**
 * Let auto-sync retry exhausted events after a teacher manually syncs the activity.
 *
 * @param int $googlemeetid Activity instance id.
 * @return int Number of events reset.
 */
function googlemeet_reset_exhausted_autosync_events(int $googlemeetid): int {
    global $DB;

    $max = (int)get_config('googlemeet', 'maxsyncattempts');
    if ($max < 1) {
        $max = 1;
    }

    // Exhausted events were closed by process_autosync::close_event(), which stamps
    // autosynced with a timestamp for BOTH success and give-up; only give-up leaves
    // syncattempts >= max (success can too on the final attempt, but reopening those
    // is harmless: the sync is idempotent and the event re-closes on the next tick).
    // Reopening requires autosynced = 0 or the due-events query never selects them.
    $eventids = $DB->get_fieldset_select(
        'googlemeet_events',
        'id',
        'googlemeetid = :googlemeetid AND autosynced <> 0 AND syncattempts >= :maxattempts',
        ['googlemeetid' => $googlemeetid, 'maxattempts' => $max]
    );

    if (empty($eventids)) {
        return 0;
    }

    list($insql, $inparams) = $DB->get_in_or_equal($eventids, SQL_PARAMS_NAMED);
    $DB->execute("UPDATE {googlemeet_events}
                     SET autosynced = 0,
                         syncattempts = 0,
                         nextsyncattempt = 0
                   WHERE id $insql", $inparams);

    return count($eventids);
}

/**
 * Handle the user's recording notification subscription toggle.
 *
 * @param object $googlemeet the googlemeet instance record
 * @param object $cm the course module record
 * @return void
 */
function googlemeet_handle_subscription_action($googlemeet, $cm) {
    global $DB, $USER;

    $subscribe = optional_param('subscribe', -1, PARAM_INT);
    if ($subscribe === -1) {
        return;
    }

    $context = context_module::instance($cm->id);
    require_capability('mod/googlemeet:subscriberecordings', $context);
    require_sesskey();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('invalidrequest', 'error');
    }

    if ($subscribe) {
        if (!$DB->record_exists('googlemeet_recording_subs', ['googlemeetid' => $googlemeet->id, 'userid' => $USER->id])) {
            $record = new stdClass();
            $record->googlemeetid = $googlemeet->id;
            $record->userid = $USER->id;
            $record->timecreated = time();
            $DB->insert_record('googlemeet_recording_subs', $record);
        }
    } else {
        $DB->delete_records('googlemeet_recording_subs', ['googlemeetid' => $googlemeet->id, 'userid' => $USER->id]);
    }

    redirect(new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]));
}

/**
 * Print googlemeet heading.
 * @param object $googlemeet
 * @param object $cm
 * @param object $course
 * @param bool $notused This variable is no longer used.
 * @return void
 */
function googlemeet_print_heading($googlemeet, $cm, $course, $notused = false) {
    global $OUTPUT;
    echo $OUTPUT->heading(format_string($googlemeet->name), 2);
}

/**
 * Print googlemeet introduction.
 * @param object $googlemeet
 * @param object $cm
 * @param object $course
 * @param bool $ignoresettings print even if not specified in modedit
 * @return void
 */
function googlemeet_print_intro($googlemeet, $cm, $course, $ignoresettings = false) {
    global $OUTPUT;

    $options = [];
    if (!empty($googlemeet->displayoptions)) {
        // Moodle 4.0+ uses JSON for displayoptions.
        $decoded = json_decode($googlemeet->displayoptions, true);
        if ($decoded !== null) {
            $options = $decoded;
        }
    }
    if ($ignoresettings || !empty($options['printintro'])) {
        if (trim(strip_tags($googlemeet->intro))) {
            echo $OUTPUT->box_start('mod_introbox', 'googlemeetintro');
            echo format_module_intro('googlemeet', $googlemeet, $cm->id);
            echo $OUTPUT->box_end();
        }
    }
}

/**
 * Get event data from the form.
 *
 * @param stdClass $googlemeet moodleform.
 * @return array list of events
 */
function googlemeet_construct_events_data_for_add($googlemeet) {
    global $CFG;

    $eventstarttime = $googlemeet->starthour * HOURSECS + $googlemeet->startminute * MINSECS;
    $eventendtime = $googlemeet->endhour * HOURSECS + $googlemeet->endminute * MINSECS;
    $eventdate = $googlemeet->eventdate + $eventstarttime;
    $duration = $eventendtime - $eventstarttime;

    // Get holiday/exclusion periods for this instance.
    $holidays = googlemeet_get_holidays($googlemeet->id);

    $events = [];

    // Add the first event only if it's not during a holiday period.
    if (!googlemeet_is_holiday($eventdate, $holidays)) {
        $event = new stdClass();
        $event->googlemeetid = $googlemeet->id;
        $event->eventdate = $eventdate;
        $event->duration = $duration;
        $event->timemodified = time();
        $events[] = $event;
    }

    if (isset($googlemeet->addmultiply)) {
        $startdate = $eventdate + DAYSECS;
        $enddate = $googlemeet->eventenddate + $eventendtime;

        // Getting first day of week.
        $sdate = $startdate;
        $dayinfo = usergetdate($sdate);
        if ($CFG->calendar_startwday === '0') { // Week start from sunday.
            $startweek = $sdate - $dayinfo['wday'] * DAYSECS; // Call new variable.
        } else {
            $wday = $dayinfo['wday'] === 0 ? 7 : $dayinfo['wday'];
            $startweek = $sdate - ($wday - 1) * DAYSECS;
        }

        $wdaydesc = [0 => 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        while ($sdate < $enddate) {
            if ($sdate < $startweek + WEEKSECS) {
                $dayinfo = usergetdate($sdate);
                if (isset($googlemeet->days) && property_exists((object)$googlemeet->days, $wdaydesc[$dayinfo['wday']])) {
                    $eventtime = make_timestamp(
                        $dayinfo['year'],
                        $dayinfo['mon'],
                        $dayinfo['mday'],
                        $googlemeet->starthour,
                        $googlemeet->startminute
                    );

                    // Only add event if it's not during a holiday period.
                    if (!googlemeet_is_holiday($eventtime, $holidays)) {
                        $event = new stdClass();
                        $event->googlemeetid = $googlemeet->id;
                        $event->eventdate = $eventtime;
                        $event->duration = $duration;
                        $event->timemodified = time();

                        $events[] = $event;
                    }
                }
                $sdate += DAYSECS;
            } else {
                $startweek += WEEKSECS * $googlemeet->period;
                $sdate = $startweek;
            }
        }
    }

    return $events;
}

/**
 * This excludes all Google Meet events.
 * @param int $googlemeetid
 * @return void
 */
function googlemeet_delete_events($googlemeetid) {
    global $DB;

    // Get event IDs for bulk delete instead of N+1 queries.
    $eventids = $DB->get_fieldset_select('googlemeet_events', 'id', 'googlemeetid = ?', [$googlemeetid]);

    if (!empty($eventids)) {
        // Bulk delete notify_done records in a single query.
        list($insql, $params) = $DB->get_in_or_equal($eventids);
        $DB->delete_records_select('googlemeet_notify_done', "eventid $insql", $params);
    }

    $DB->delete_records('googlemeet_events', ['googlemeetid' => $googlemeetid]);

    // Delete Calendar Events.
    $DB->delete_records('event', [
        'modulename' => 'googlemeet',
        'instance' => $googlemeetid,
        'eventtype' => helper::GOOGLEMEET_EVENT_START
    ]);
}

/**
 * This creates new events given as timeopen and timeclose by $googlemeet.
 *
 * @param stdClass $googlemeet moodleform
 * @param array $events list of events
 * @return void
 */
function googlemeet_set_events($googlemeet, $events) {
    global $DB;

    googlemeet_delete_events($googlemeet->id);

    if (empty($events)) {
        return;
    }

    foreach ($events as $event) {
        $event->id = $DB->insert_record('googlemeet_events', $event);
        helper::create_calendar_event($googlemeet, $event);
    }

    googlemeet_reconcile_cancelled_calendar_events($googlemeet);
}

/**
 * Reconcile core calendar mirrors with the instance cancelled-date list.
 *
 * The session identity is the scheduled start timestamp stored in googlemeet_events.eventdate.
 * Cancelled sessions are detected with googlemeet_is_cancelled(), the same day-level helper used
 * by the hero schedule. Core calendar mirrors are matched by instance + eventtype + timestart.
 *
 * @param stdClass $googlemeet Activity record.
 * @return void
 */
function googlemeet_reconcile_cancelled_calendar_events(stdClass $googlemeet): void {
    global $DB;

    $googlemeetid = (int)$googlemeet->id;
    $events = $DB->get_records('googlemeet_events', ['googlemeetid' => $googlemeetid], 'eventdate ASC',
        'id,eventdate,duration');
    if (empty($events)) {
        return;
    }

    $cancelleddates = googlemeet_get_cancelled($googlemeetid);
    foreach ($events as $event) {
        $match = [
            'modulename' => 'googlemeet',
            'instance' => $googlemeetid,
            'eventtype' => helper::GOOGLEMEET_EVENT_START,
            'timestart' => $event->eventdate,
        ];

        if (googlemeet_is_cancelled((int)$event->eventdate, $cancelleddates) !== false) {
            $DB->delete_records('event', $match);
            continue;
        }

        if (!$DB->record_exists('event', $match)) {
            helper::create_calendar_event($googlemeet, $event);
        }
    }
}

/**
 * Incrementally merge the regenerated events with the existing ones.
 *
 * Unlike googlemeet_set_events() (used on add), which wipes and recreates every event row, this
 * function preserves rows whose eventdate still matches one of the regenerated events. Preserving
 * the row keeps its id intact, which in turn preserves:
 *   - the `autosynced` timestamp (so an already auto-synced session is not re-processed), and
 *   - the related googlemeet_notify_done rows (so already-sent notifications are not re-sent).
 *
 * Only genuinely new dates are inserted, and only dates that no longer exist are deleted. Events
 * are matched by eventdate (the start timestamp), which uniquely identifies a session for an
 * instance. When a persisting event's duration changes, the row and its calendar mirror are
 * updated in place without touching its id, autosynced or notify_done.
 *
 * @param stdClass $googlemeet moodleform
 * @param array $events list of regenerated events (output of googlemeet_construct_events_data_for_add)
 * @return void
 */
function googlemeet_merge_events($googlemeet, $events) {
    global $DB;

    $googlemeetid = $googlemeet->id;

    // Load existing events keyed by eventdate for O(1) matching.
    $existingevents = $DB->get_records('googlemeet_events', ['googlemeetid' => $googlemeetid]);
    $existingbydate = [];
    foreach ($existingevents as $existing) {
        // In the unlikely case of duplicate dates, keep the first; extras are treated as stale.
        if (!isset($existingbydate[$existing->eventdate])) {
            $existingbydate[$existing->eventdate] = $existing;
        }
    }

    // Track which existing event ids are still wanted, so the rest can be removed.
    $keptids = [];

    foreach ($events as $event) {
        if (isset($existingbydate[$event->eventdate])) {
            // Same date already exists: preserve the row (id, autosynced, notify_done).
            $existing = $existingbydate[$event->eventdate];
            $keptids[$existing->id] = true;

            // Update duration in place only if it actually changed; keep autosynced untouched.
            if ((int) $existing->duration !== (int) $event->duration) {
                $update = new stdClass();
                $update->id = $existing->id;
                $update->duration = $event->duration;
                $update->timemodified = time();
                $DB->update_record('googlemeet_events', $update);

                // Refresh the calendar mirror for this date so its duration stays in sync.
                $DB->delete_records('event', [
                    'modulename' => 'googlemeet',
                    'instance' => $googlemeetid,
                    'eventtype' => helper::GOOGLEMEET_EVENT_START,
                    'timestart' => $event->eventdate,
                ]);
                $calendarevent = clone $event;
                $calendarevent->id = $existing->id;
                helper::create_calendar_event($googlemeet, $calendarevent);
            }
        } else {
            // Brand new date: insert event + calendar mirror.
            $event->id = $DB->insert_record('googlemeet_events', $event);
            helper::create_calendar_event($googlemeet, $event);
        }
    }

    // Delete existing events whose date is no longer scheduled, along with their dependents.
    $deleteids = [];
    foreach ($existingevents as $existing) {
        if (empty($keptids[$existing->id])) {
            $deleteids[] = $existing->id;
        }
    }

    if (!empty($deleteids)) {
        list($insql, $params) = $DB->get_in_or_equal($deleteids);

        // Remove notify_done rows for removed events.
        $DB->delete_records_select('googlemeet_notify_done', "eventid $insql", $params);

        // Remove the calendar mirrors for the removed dates.
        foreach ($existingevents as $existing) {
            if (empty($keptids[$existing->id])) {
                $DB->delete_records('event', [
                    'modulename' => 'googlemeet',
                    'instance' => $googlemeetid,
                    'eventtype' => helper::GOOGLEMEET_EVENT_START,
                    'timestart' => $existing->eventdate,
                ]);
            }
        }

        // Remove the event rows themselves.
        $DB->delete_records_select('googlemeet_events', "id $insql", $params);
    }

    googlemeet_reconcile_cancelled_calendar_events($googlemeet);
}

/**
 * Build the redesigned classroom hero context.
 *
 * @param object $googlemeet Activity record.
 * @param object $cm Course-module record.
 * @param context_module $context Module context.
 * @param array $upcomingeventscontext Context returned by googlemeet_get_upcoming_events().
 * @param bool $hasvalidmeeturl Whether the activity has a valid Meet URL.
 * @param array $scheduleeventscontext Full schedule context returned by googlemeet_get_upcoming_events().
 * @return array Template context.
 */
function googlemeet_get_classroom_hero_context($googlemeet, $cm, context_module $context,
        array $upcomingeventscontext, bool $hasvalidmeeturl, array $scheduleeventscontext = []): array {
    $nextevent = null;
    $upcomingevents = [];
    $sourceevents = !empty($scheduleeventscontext['upcomingevents'])
        ? $scheduleeventscontext['upcomingevents']
        : ($upcomingeventscontext['upcomingevents'] ?? []);
    $maxupcomingevents = max(1, min(10, (int)($googlemeet->maxupcomingevents ?? 3)));
    foreach ($sourceevents as $event) {
        if (!empty($event->iscancelled)) {
            continue;
        }
        $upcomingevents[] = $event;
        if (!$nextevent) {
            $nextevent = $event;
        }
        if (count($upcomingevents) >= $maxupcomingevents) {
            break;
        }
    }

    $eventcontext = [];
    if ($nextevent) {
        $statuslabel = get_string('event_status_scheduled', 'googlemeet');
        if (!empty($nextevent->islive)) {
            $statuslabel = get_string('event_status_live', 'googlemeet');
        } else if (!empty($nextevent->issoon)) {
            $statuslabel = get_string('event_status_soon', 'googlemeet');
        }
        $eventcontext = [
            'hasnextevent' => true,
            'nexteventislive' => !empty($nextevent->islive),
            'nexteventissoon' => !empty($nextevent->issoon),
            'nexteventisscheduled' => !empty($nextevent->isscheduled),
            'nexteventstatus' => $nextevent->status,
            'nexteventstatuslabel' => $statuslabel,
            'nexteventtoday' => !empty($nextevent->today),
            'nexteventdate' => $nextevent->startdate,
            'nexteventstarttime' => $nextevent->starttime,
            'nexteventendtime' => $nextevent->endtime,
            'nexteventtimeinfo' => $nextevent->timeinfo,
            'nexteventtimestamp' => $nextevent->timestamp,
            'nexteventduration' => $nextevent->durationformatted,
            'nexteventhascountdown' => empty($nextevent->islive),
            'countdownprefix' => $nextevent->countdownprefix ?? get_string('event_countdown_starts_in_prefix', 'googlemeet'),
            'countdownexpired' => $nextevent->countdownexpired ?? get_string('event_countdown_started', 'googlemeet'),
        ];
    }

    $nextclassevents = [];
    foreach (array_slice($upcomingevents, 1) as $event) {
        $nextclassevents[] = [
            'date' => $event->compactdate,
            'starttime' => $event->starttime,
            'endtime' => $event->endtime,
            'duration' => $event->durationformatted,
        ];
    }

    $scheduleevents = [];
    $nextscheduledmarked = false;
    foreach (($scheduleeventscontext['upcomingevents'] ?? []) as $event) {
        $isnext = empty($event->iscancelled) && !$nextscheduledmarked;
        if ($isnext) {
            $nextscheduledmarked = true;
        }
        $scheduleevents[] = [
            'isnext' => $isnext,
            'iscancelled' => !empty($event->iscancelled),
            'cancelledreason' => $event->cancelledreason ?? '',
            'date' => $event->startdate,
            'starttime' => $event->starttime,
            'endtime' => $event->endtime,
            'duration' => $event->durationformatted,
        ];
    }

    $progresssummary = googlemeet_get_progress_summary($googlemeet, $context);
    $isstudentview = !has_capability('mod/googlemeet:editrecording', $context);

    return array_merge([
        'activityname' => format_string($googlemeet->name),
        // UX-01: without upcoming sessions the hero collapses instead of leaving a tall empty card.
        'herocompact' => !$nextevent,
        'showheroprogress' => $isstudentview && $progresssummary['total'] > 0,
        'hasrecordedcount' => $progresssummary['total'] > 0,
        'recordedcountlabel' => get_string('hero_recorded_count', 'googlemeet', $progresssummary['total']),
        'hasnextevent' => false,
        'hasnextclassevents' => !empty($nextclassevents),
        'nextclassevents' => $nextclassevents,
        'hasscheduleevents' => !empty($scheduleevents),
        'scheduleevents' => $scheduleevents,
        'courseid' => (int)$cm->course,
        'hascontinue' => false,
        'hasroomcta' => false,
        // Track analytics: activity-wide practice entry point (students only; null hides it).
        'practicecta' => \mod_googlemeet\local\practice_attempts::hero_cta_context($googlemeet, $cm, $context,
            (int)$GLOBALS['USER']->id),
    ], $eventcontext,
        googlemeet_get_room_cta_context($googlemeet, $context, $nextevent, $hasvalidmeeturl),
        googlemeet_get_continue_watching_context($googlemeet, $cm, $context),
        googlemeet_progress_summary_context($progresssummary));
}

/**
 * Build the Meet room CTA context for the classroom hero.
 *
 * @param object $googlemeet Activity record.
 * @param context_module $context Module context.
 * @param stdClass|null $nextevent First non-cancelled upcoming/current event.
 * @param bool $hasvalidmeeturl Whether the activity has a valid Meet URL.
 * @return array Template context.
 */
function googlemeet_get_room_cta_context($googlemeet, context_module $context, ?stdClass $nextevent,
        bool $hasvalidmeeturl): array {
    if (!$hasvalidmeeturl) {
        return ['hasroomcta' => false];
    }

    $caneditrecording = has_capability('mod/googlemeet:editrecording', $context);
    $roomactive = false;
    $roomclasses = 'btn googlemeet-room-cta';
    $roomlabel = '';
    $roomnote = '';
    $roomhascountdown = false;

    if ($caneditrecording) {
        $roomactive = true;
        $roomclasses .= ' btn-primary';
        $roomlabel = get_string('room_enter_teacher', 'googlemeet');
        $roomnote = get_string('room_teacher_note', 'googlemeet');
        if ($nextevent && !empty($nextevent->islive)) {
            $roomclasses .= ' googlemeet-room-cta-live';
            $roomlabel = get_string('room_enter_live', 'googlemeet');
        } else if ($nextevent && !empty($nextevent->issoon)) {
            $roomclasses .= ' googlemeet-room-cta-soon';
            $roomlabel = get_string('room_enter_soon', 'googlemeet');
            $roomhascountdown = true;
        }
    } else if ($nextevent && !empty($nextevent->islive)) {
        $roomactive = true;
        $roomclasses .= ' btn-primary googlemeet-room-cta-live';
        $roomlabel = get_string('room_enter_live', 'googlemeet');
    } else if ($nextevent && !empty($nextevent->issoon)) {
        // "Soon" is currently 30 minutes, satisfying the owner's T-10 minimum.
        $roomactive = true;
        $roomclasses .= ' btn-primary googlemeet-room-cta-soon';
        $roomlabel = get_string('room_enter_soon', 'googlemeet');
        $roomhascountdown = true;
    } else if ($nextevent) {
        $roomclasses .= ' btn-outline-secondary disabled googlemeet-room-cta-disabled';
        $roomdate = !empty($nextevent->today)
            ? get_string('today', 'googlemeet') . ' ' . $nextevent->starttime
            : $nextevent->startdate . ' ' . $nextevent->starttime;
        $roomlabel = get_string('room_next_class', 'googlemeet', $roomdate);
    }

    if ($roomlabel === '') {
        return ['hasroomcta' => false];
    }

    return [
        'hasroomcta' => true,
        'roomactive' => $roomactive,
        'roomdisabled' => !$roomactive,
        'roomclasses' => $roomclasses,
        'roomurl' => $googlemeet->url,
        'roomlabel' => $roomlabel,
        'roomnote' => $roomnote,
        'roomhascountdown' => $roomhascountdown,
        'roomtargetts' => $nextevent ? $nextevent->timestamp : 0,
        'roomcountdownprefix' => get_string('event_countdown_starts_in_prefix', 'googlemeet'),
        'roomcountdownexpired' => get_string('event_countdown_started', 'googlemeet'),
    ];
}

/**
 * Build the "continue watching" card context for the current user.
 *
 * @param object $googlemeet Activity record.
 * @param object $cm Course-module record.
 * @param context_module $context Module context.
 * @return array Template context.
 */
function googlemeet_get_continue_watching_context($googlemeet, $cm, context_module $context): array {
    global $DB, $USER;

    $caneditrecording = has_capability('mod/googlemeet:editrecording', $context);
    $visiblewhere = $caneditrecording ? '' : ' AND r.visible = 1';
    $params = [
        'googlemeetid' => $googlemeet->id,
        'userid' => $USER->id,
    ];

    $sql = "SELECT r.id,
                   r.name,
                   r.createdtime,
                   r.duration,
                   r.webviewlink,
                   grp.watchedseconds AS userwatchedseconds,
                   grp.completed AS usercompleted,
                   a.summary,
                   a.reviewed AS aireviewed
              FROM {googlemeet_recordings} r
              JOIN {googlemeet_recording_progress} grp ON grp.recordingid = r.id
         LEFT JOIN {googlemeet_ai_analysis} a ON a.recordingid = r.id AND a.status = 'completed'
             WHERE r.googlemeetid = :googlemeetid
               AND r.deleted = 0
               {$visiblewhere}
               AND grp.userid = :userid
               AND grp.completed = 0
               AND grp.watchedseconds > 0
          ORDER BY grp.timemodified DESC, r.createdtime DESC";
    $records = $DB->get_records_sql($sql, $params, 0, 1);
    $recording = reset($records);
    $fallbackrecording = false;

    if (!$recording) {
        $sql = "SELECT r.id,
                       r.name,
                       r.createdtime,
                       r.duration,
                       r.webviewlink,
                       grp.watchedseconds AS userwatchedseconds,
                       grp.completed AS usercompleted,
                       a.summary,
                       a.reviewed AS aireviewed
                  FROM {googlemeet_recordings} r
             LEFT JOIN {googlemeet_recording_progress} grp
                    ON grp.recordingid = r.id AND grp.userid = :userid
             LEFT JOIN {googlemeet_ai_analysis} a ON a.recordingid = r.id AND a.status = 'completed'
                 WHERE r.googlemeetid = :googlemeetid
                   AND r.deleted = 0
                   {$visiblewhere}
                   AND (grp.id IS NULL OR grp.completed = 0)
              ORDER BY r.createdtime DESC, r.id DESC";
        $records = $DB->get_records_sql($sql, $params, 0, 1);
        $recording = reset($records);
        $fallbackrecording = true;
    }

    if (!$recording) {
        return ['hascontinue' => false];
    }
    // IA-04: no unreviewed summary snippet for students.
    if (!empty($recording->summary) && !$caneditrecording && \mod_googlemeet\local\ai_review::is_pending_review(
            (object)['status' => 'completed', 'reviewed' => $recording->aireviewed])) {
        $recording->summary = '';
    }

    $progress = null;
    if (isset($recording->userwatchedseconds) || isset($recording->usercompleted)) {
        $progress = (object) [
            'watchedseconds' => (int)($recording->userwatchedseconds ?? 0),
            'completed' => (int)($recording->usercompleted ?? 0),
        ];
    }
    $progressstate = googlemeet_recording_progress_state($recording, $progress);
    $latestclassfallback = $fallbackrecording && empty($progressstate['progresswatchedseconds']);

    return [
        'hascontinue' => true,
        'continueurl' => (new moodle_url('/mod/googlemeet/view.php',
            ['id' => $cm->id, 'recording' => $recording->id]))->out(false),
        'continuetitle' => format_string(googlemeet_get_lesson_titles($googlemeet, $caneditrecording)[$recording->id]['title']
            ?? googlemeet_display_name((string)$recording->name)),
        'continueoriginaltitle' => $recording->name,
        'continuedate' => userdate($recording->createdtime, get_string('strftimedmy', 'googlemeet')),
        'continueduration' => $recording->duration,
        'continuesummary' => !empty($recording->summary) ? googlemeet_truncate_summary((string)$recording->summary, 180) : '',
        'continuelabel' => get_string($latestclassfallback ? 'continue_latest_label' : 'continue_watching_label', 'googlemeet'),
        'continuectalabel' => get_string($latestclassfallback ? 'continue_latest_cta' : 'continue_watching_cta', 'googlemeet'),
        'continueprogresslabel' => $progressstate['progressstatuslabel'],
        'continueprogressarialabel' => $progressstate['progressarialabel'],
        'continueprogresspct' => $progressstate['progresspct'],
        'continueprogresspcttext' => $progressstate['progresspcttext'],
        'continueprogressbarstyle' => $progressstate['progressbarstyle'],
        'continueshowprogressbar' => $progressstate['showprogressbar'],
        'continueprogresscompleted' => $progressstate['progresscompleted'],
        'continueprogresspartial' => $progressstate['progresspartial'],
        'continueprogressunseen' => $progressstate['progressunseen'],
        'continueprogressbadgeclass' => $progressstate['progresscompleted'] ? 'googlemeet-progress-badge-completed'
            : ($progressstate['progresspartial'] ? 'googlemeet-progress-badge-partial' : 'googlemeet-progress-badge-unseen'),
    ];
}

/**
 * The current user's viewing progress over the visible recordings of an activity (cached per request).
 *
 * @param stdClass $googlemeet Activity record.
 * @param context_module $context Module context.
 * "watched" matches the list's "Vista" badge (completed) and "started" its "Empezada" badge (some viewing
 * time, not completed), so the hero copy never contradicts the per-lesson badges.
 *
 * @return array ['total' => int, 'watched' => int, 'started' => int, 'pending' => int, 'pct' => int,
 *     'startedpct' => int, 'completedids' => int[]]
 */
function googlemeet_get_progress_summary(stdClass $googlemeet, context_module $context): array {
    global $DB, $USER;
    static $cache = [];
    $key = (int)$googlemeet->id . ':' . (int)$USER->id;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $visiblewhere = has_capability('mod/googlemeet:editrecording', $context) ? '' : 'AND r.visible = 1';
    $params = ['googlemeetid' => (int)$googlemeet->id, 'userid' => (int)$USER->id];
    $total = (int)$DB->count_records_sql(
        "SELECT COUNT(1) FROM {googlemeet_recordings} r
          WHERE r.googlemeetid = :googlemeetid AND r.deleted = 0 {$visiblewhere}", $params);
    $completedids = array_map('intval', $DB->get_fieldset_sql(
        "SELECT r.id
           FROM {googlemeet_recordings} r
           JOIN {googlemeet_recording_progress} p ON p.recordingid = r.id AND p.userid = :userid AND p.completed = 1
          WHERE r.googlemeetid = :googlemeetid AND r.deleted = 0 {$visiblewhere}", $params));
    $watched = count($completedids);
    $started = (int)$DB->count_records_sql(
        "SELECT COUNT(1)
           FROM {googlemeet_recordings} r
           JOIN {googlemeet_recording_progress} p ON p.recordingid = r.id AND p.userid = :userid
                AND p.completed = 0 AND p.watchedseconds > 0
          WHERE r.googlemeetid = :googlemeetid AND r.deleted = 0 {$visiblewhere}", $params);
    $pct = $total > 0 ? (int)round($watched * 100 / $total) : 0;

    $cache[$key] = [
        'total' => $total,
        'watched' => $watched,
        'started' => $started,
        'pending' => max(0, $total - $watched),
        'pct' => $pct,
        // Kept so that both segments of the stacked bar never add up to more than 100%.
        'startedpct' => $total > 0 ? min(100 - $pct, (int)round($started * 100 / $total)) : 0,
        'completedids' => $completedids,
    ];
    return $cache[$key];
}

/**
 * Template fields for the "N vistas · M empezadas de T clases" summary.
 *
 * @param array $summary Result of googlemeet_get_progress_summary().
 * @return array
 */
function googlemeet_progress_summary_context(array $summary): array {
    $started = (int)($summary['started'] ?? 0);
    $startedpct = (int)($summary['startedpct'] ?? 0);
    $watchedpart = get_string($summary['watched'] === 1 ? 'progress_count_watched_one' : 'progress_count_watched',
        'googlemeet', $summary['watched']);
    if ($started > 0) {
        $startedpart = get_string($started === 1 ? 'progress_count_started_one' : 'progress_count_started',
            'googlemeet', $started);
        $label = get_string('progress_summary_detail', 'googlemeet',
            ['watched' => $watchedpart, 'started' => $startedpart, 'total' => $summary['total']]);
    } else {
        $label = get_string('progress_summary_watchedonly', 'googlemeet',
            ['watched' => $watchedpart, 'total' => $summary['total']]);
    }
    return [
        'hasprogresssummary' => $summary['total'] > 0,
        'watchedcount' => $summary['watched'],
        'totalcount' => $summary['total'],
        'pendingcount' => $summary['pending'],
        'progresssummarypct' => $summary['pct'],
        'progresssummarybarstyle' => 'width: ' . $summary['pct'] . '%;',
        'startedcount' => $started,
        'progressstartedpct' => $startedpct,
        'progressstartedbarstyle' => 'width: ' . $startedpct . '%;',
        'hasprogressstarted' => $started > 0,
        'progresssummarylabel' => $label,
        'progresssummarydone' => $summary['total'] > 0 && $summary['pending'] === 0,
    ];
}

/**
 * Per-recording count of students who opened / completed each recording (teacher list view).
 *
 * "Opened" means a googlemeet_recording_progress row exists (some viewing time was recorded). Users who can
 * edit recordings (teachers) are left out so the numbers describe students only. One grouped query per page.
 *
 * @param context_module $context Module context.
 * @param int[] $recordingids Recordings on the current page.
 * @return stdClass[] Keyed by recording id: {recordingid, opened, completedcount}.
 */
function googlemeet_get_recordings_student_stats(context_module $context, array $recordingids): array {
    global $DB;

    if (empty($recordingids)) {
        return [];
    }
    list($insql, $params) = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED, 'statsrid');
    $where = "p.recordingid {$insql}";
    $editorids = array_keys(get_users_by_capability($context, 'mod/googlemeet:editrecording', 'u.id'));
    if ($editorids) {
        list($notinsql, $notinparams) = $DB->get_in_or_equal($editorids, SQL_PARAMS_NAMED, 'statsuid', false);
        $where .= " AND p.userid {$notinsql}";
        $params += $notinparams;
    }
    return $DB->get_records_sql(
        "SELECT p.recordingid, COUNT(1) AS opened, SUM(p.completed) AS completedcount
           FROM {googlemeet_recording_progress} p
          WHERE {$where}
       GROUP BY p.recordingid", $params);
}

/**
 * This creates new events given as timeopen and timeclose by googlemeet.
 *
 * @param object $googlemeet
 * @param object $cm
 * @param object $context
 * @param int $page Current page number (0-based).
 * @param string|null $orderoverride Optional order override from URL parameter.
 * @param string $query Free-text search.
 * @param string $topic Topic filter.
 * @param bool $pendingonly Only recordings the current user has not marked as viewed (ANA-06).
 * @return void
 */
function googlemeet_print_recordings($googlemeet, $cm, $context, $page = 0, $orderoverride = null, $query = '', $topic = '',
        bool $pendingonly = false) {
    global $CFG, $DB, $PAGE, $OUTPUT, $USER;

    $config = get_config('googlemeet');

    $client = new client();
    if (!$client->enabled) {
        return;
    }

    $params = ['googlemeetid' => $googlemeet->id];
    $hascapability = has_capability('mod/googlemeet:editrecording', $context);
    if (!$hascapability) {
        $params['visible'] = true;
    }

    // Recordings view preference (cards|list). Used both for the view modifier class on the
    // outer wrapper (so list view can use the full content width) and for the template branch.
    $recordingsview = get_user_preferences('mod_googlemeet_recordings_view', 'list');
    if ($recordingsview !== 'cards' && $recordingsview !== 'list') {
        $recordingsview = 'list';
    }

    $html = '<div id="googlemeet_recordings" class="googlemeet_recordings googlemeet-view-' . $recordingsview . '">';

    // Check if AI features are enabled (single config fetch).
    $aiconfig = get_config('googlemeet');
    $aienabled = !empty($aiconfig->enableai) && !empty($aiconfig->geminiapikey);
    $cangenerateai = $aienabled && has_capability('mod/googlemeet:generateai', $context);

    // Get pagination settings.
    $maxrecordings = isset($googlemeet->maxrecordings) ? (int) $googlemeet->maxrecordings : 12;
    $maxrecordings = max(1, min(20, $maxrecordings));

    // Get order - use override if provided, otherwise use instance setting.
    $order = $orderoverride !== null ? $orderoverride : ($googlemeet->recordingsorder ?? 'DESC');
    $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

    $page = max(0, (int) $page);

    // Fetch ALL recordings (no SQL limit) so search/topic filters apply before pagination.
    $allrecordings = googlemeet_list_recordings($params, $aienabled, $order, 0, 0);

    // Distinct topics for the filter chips come from the UNFILTERED set.
    $alltopics = googlemeet_collect_topics($allrecordings);

    // Distinguishable lesson titles (computed over the whole activity so duplicates are detected).
    $titleitems = [];
    foreach ($allrecordings as $recording) {
        $titleitems[$recording->id] = ['name' => (string)$recording->name, 'topics' => $recording->aitopics ?? []];
    }
    $lessontitles = googlemeet_assign_lesson_titles($titleitems,
        [(string)$googlemeet->name, (string)($googlemeet->originalname ?? '')]);
    foreach ($allrecordings as $recording) {
        if (isset($lessontitles[$recording->id])) {
            $recording->displayname = $lessontitles[$recording->id]['title'];
        }
    }

    // Apply filters in PHP (topics are stored as JSON; this stays DB-portable).
    if (trim((string)$query) !== '') {
        if ($hascapability) {
            $allrecordings = googlemeet_load_recording_search_content($allrecordings, true);
        }
        $allrecordings = googlemeet_filter_recordings_by_query($allrecordings, (string)$query, $hascapability);
    }
    if (trim((string)$topic) !== '') {
        $allrecordings = googlemeet_filter_recordings_by_topic($allrecordings, (string)$topic);
    }
    $progresssummary = googlemeet_get_progress_summary($googlemeet, $context);
    $showprogresssummary = !$hascapability && $progresssummary['total'] > 0;
    $pendingonly = $pendingonly && $showprogresssummary;
    if ($pendingonly) {
        $completed = array_flip($progresssummary['completedids']);
        $allrecordings = array_values(array_filter($allrecordings, static function($recording) use ($completed) {
            return !isset($completed[(int)$recording->id]);
        }));
    }

    $totalrecordings = count($allrecordings);
    $totalpages = max(1, (int)ceil($totalrecordings / $maxrecordings));
    $page = min($page, max(0, $totalpages - 1)); // Ensure page is within bounds.
    $offset = $page * $maxrecordings;
    $recordings = array_slice($allrecordings, $offset, $maxrecordings);

    $progressbyrecording = [];
    if (!empty($recordings)) {
        $recordingids = array_map(static function($recording) {
            return (int)$recording->id;
        }, $recordings);
        list($insql, $inparams) = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED, 'progressid');
        $progressbyrecording = $DB->get_records_sql(
            "SELECT recordingid, watchedseconds, completed
               FROM {googlemeet_recording_progress}
              WHERE userid = :userid
                AND recordingid {$insql}",
            ['userid' => $USER->id] + $inparams
        );
    }

    // Teachers see their students' activity per lesson instead of their own viewing progress.
    $teacherstats = ($hascapability && !empty($recordings))
        ? googlemeet_get_recordings_student_stats($context, $recordingids) : [];

    $questionservice = new question_service();
    foreach ($recordings as $recording) {
        foreach (googlemeet_recording_progress_state($recording, $progressbyrecording[$recording->id] ?? null) as $key => $value) {
            $recording->$key = $value;
        }
        if ($hascapability) {
            $stats = $teacherstats[$recording->id] ?? null;
            $opened = $stats ? (int)$stats->opened : 0;
            $completedcount = $stats ? (int)$stats->completedcount : 0;
            $recording->teacherstatsopened = $opened;
            $recording->teacherstatslabel = $opened === 0 ? get_string('list_teacherstats_none', 'googlemeet')
                : get_string($opened === 1 ? 'list_teacherstats_opened_one' : 'list_teacherstats_opened', 'googlemeet', $opened);
            $recording->teacherstatshascompleted = $completedcount > 0;
            $recording->teacherstatscompletedlabel = $completedcount > 0
                ? get_string('list_teacherstats_completed', 'googlemeet', $completedcount) : '';
        }
        $publishedquestions = $questionservice->get_questions($googlemeet, $cm, $context, (int)$recording->id, true);
        $recording->haspublishedquestions = !empty($publishedquestions);
        $recording->publishedquestioncount = count($publishedquestions);
        $urlparams = ['id' => $cm->id, 'recording' => $recording->id];
        if ($page > 0) {
            $urlparams['rpage'] = $page;
        }
        $urlparams['rorder'] = $order;
        $recording->huburl = (new moodle_url('/mod/googlemeet/view.php', $urlparams))->out(false);
        $materialsurlparams = $urlparams;
        $materialsurlparams['tab'] = 'materials';
        $recording->materialshuburl = (new moodle_url('/mod/googlemeet/view.php', $materialsurlparams))->out(false);
        // Surface attached materials directly in the recordings list so teachers can
        // upload/manage from here and students can download without entering the hub.
        $materials = googlemeet_get_recording_materials($context, $recording->id);
        $recording->materials = $materials;
        $recording->hasmaterials = !empty($materials);
        $recording->materialcount = count($materials);
        $recording->cardmaterials = array_slice($materials, 0, 2);
        $recording->cardmaterialoverflow = max(0, count($materials) - count($recording->cardmaterials));
        $recording->cardhasmaterialoverflow = $recording->cardmaterialoverflow > 0;
        $recording->managematerialsurl = (new moodle_url('/mod/googlemeet/material.php',
            ['id' => $cm->id, 'recording' => $recording->id]))->out(false);
    }
    $prevgroup = null;
    foreach ($recordings as $recording) {
        $recording->showdategroup = ($recording->dategroup !== $prevgroup);
        $prevgroup = $recording->dategroup;
    }
    $topicchips = [];
    foreach ($alltopics as $t) {
        $topicchips[] = [
            'text' => $t,
            'url' => (new moodle_url('/mod/googlemeet/view.php',
                ['id' => $cm->id, 'topic' => $t, 'rorder' => $order]))->out(false),
            'active' => (googlemeet_fold($t) === googlemeet_fold((string)$topic)),
        ];
    }
    $cansubscriberecordings = has_capability('mod/googlemeet:subscriberecordings', $context);
    $issubscribed = $cansubscriberecordings && $DB->record_exists(
        'googlemeet_recording_subs',
        ['googlemeetid' => $googlemeet->id, 'userid' => $USER->id]
    );
    $canpurge = has_capability('mod/googlemeet:removerecording', $context);
    $deletedrecordings = [];
    if ($hascapability) {
        $deletedrecords = $DB->get_records(
            'googlemeet_recordings',
            ['googlemeetid' => $googlemeet->id, 'deleted' => 1],
            'timedeleted DESC, createdtime DESC',
            'id,name,createdtime,timedeleted'
        );
        $retentiondays = \mod_googlemeet\local\recording_cleanup::get_retention_days();
        foreach ($deletedrecords as $deletedrecording) {
            $deletedrecordings[] = [
                'id' => $deletedrecording->id,
                'name' => $deletedrecording->name,
                'createdtimeformatted' => userdate($deletedrecording->createdtime),
                'timedeletedformatted' => !empty($deletedrecording->timedeleted)
                    ? userdate($deletedrecording->timedeleted)
                    : get_string('never', 'googlemeet'),
                // OPS-03: when the retention task will purge it ('' when automatic purge is off).
                'purgenotice' => \mod_googlemeet\local\recording_cleanup::purge_notice(
                    (int)$deletedrecording->timedeleted, $retentiondays),
            ];
        }
    }

    // Pagination data.
    $haspagination = $totalpages > 1;
    $hasprevious = $page > 0;
    $hasnext = $page < ($totalpages - 1);

    // Build page numbers for pagination.
    $pages = [];
    for ($i = 0; $i < $totalpages; $i++) {
        $pages[] = [
            'number' => $i + 1,
            'page' => $i,
            'active' => ($i === $page),
            'currentorder' => $order,
            'coursemoduleid' => $cm->id,
        ];
    }

    // Calculate display range.
    $start = $totalrecordings > 0 ? $offset + 1 : 0;
    $end = min($offset + $maxrecordings, $totalrecordings);

    // Per-user recordings view preference (cards|list), with toggle URLs that
    // preserve the current content state (page/order/query/topic) and carry the
    // rview action param. view.php consumes rview, sets the preference and
    // redirects to a clean URL.
    $view = $recordingsview;
    $viewurlparams = ['id' => $cm->id, 'rorder' => $order];
    if ($page > 0) {
        $viewurlparams['rpage'] = $page;
    }
    if (trim((string)$query) !== '') {
        $viewurlparams['rq'] = $query;
    }
    if (trim((string)$topic) !== '') {
        $viewurlparams['topic'] = $topic;
    }
    if ($pendingonly) {
        $viewurlparams['rpending'] = 1;
    }
    $filterbaseparams = $viewurlparams;
    unset($filterbaseparams['rpage'], $filterbaseparams['rpending']);
    $viewcardsurl = (new moodle_url('/mod/googlemeet/view.php',
        $viewurlparams + ['rview' => 'cards']))->out(false);
    $viewlisturl = (new moodle_url('/mod/googlemeet/view.php',
        $viewurlparams + ['rview' => 'list']))->out(false);

    $hasactivefilters = trim((string)$query) !== '' || trim((string)$topic) !== '' || $pendingonly;
    $html .= \mod_googlemeet\local\ai_review::render_bulk_banner($googlemeet, $cm, $context); // IA-04.
    $html .= $OUTPUT->render_from_template('mod_googlemeet/recordingstable', [
        'recordings' => $recordings,
        'hasrecordings' => !empty($recordings),
        'coursemoduleid' => $cm->id,
        'hascapability' => $hascapability,
        'aienabled' => $aienabled,
        'cangenerateai' => $cangenerateai,
        'cansubscriberecordings' => $cansubscriberecordings,
        'issubscribed' => $issubscribed,
        'canpurge' => $canpurge,
        'deletedrecordings' => $deletedrecordings,
        'hasdeletedrecordings' => !empty($deletedrecordings),
        'deletedrecordingcount' => count($deletedrecordings),
        'sesskey' => sesskey(),
        // Pagination data.
        'haspagination' => $haspagination,
        'hasprevious' => $hasprevious,
        'hasnext' => $hasnext,
        'currentpage' => $page,
        'previouspage' => $page - 1,
        'nextpage' => $page + 1,
        'pages' => $pages,
        'totalrecordings' => $totalrecordings,
        'start' => $start,
        'end' => $end,
        'totalpages' => $totalpages,
        // Order data.
        'currentorder' => $order,
        'isorderdesc' => ($order === 'DESC'),
        'isorderasc' => ($order === 'ASC'),
        // Filter data.
        // Not pre-escaped: Mustache {{ }} HTML-escapes these on output, so s() here would double-encode.
        'recordingquery' => $query,
        'selectedtopic' => $topic,
        'alltopics' => $topicchips,
        'hasactivefilters' => $hasactivefilters,
        // Keep the filter bar while a search returns nothing, so the query can still be edited.
        'showfilterbar' => !empty($recordings) || $hasactivefilters,
        // Filters folded into the "Filtros" panel on phones: topic and a non-default order.
        'activefiltercount' => (trim((string)$topic) !== '' ? 1 : 0)
            + ($order !== (strtoupper((string)($googlemeet->recordingsorder ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC') ? 1 : 0),
        'showprogresssummary' => $showprogresssummary,
        'ispendingfilter' => $pendingonly,
        'pendingparam' => $pendingonly ? 1 : 0,
        'pendingfilterurl' => (new moodle_url('/mod/googlemeet/view.php', $filterbaseparams + ['rpending' => 1]))->out(false),
        'allfilterurl' => (new moodle_url('/mod/googlemeet/view.php', $filterbaseparams))->out(false),
        'clearfiltersurl' => (new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]))->out(false),
        // View toggle (cards|list).
        'isviewcards' => ($view === 'cards'),
        'isviewlist' => ($view === 'list'),
        'viewcardsurl' => $viewcardsurl,
        'viewlisturl' => $viewlisturl,
    ] + googlemeet_progress_summary_context($progresssummary));

    $PAGE->requires->js(new moodle_url($CFG->wwwroot . '/mod/googlemeet/assets/js/build/jstable.min.js'));

    if ($hascapability) {
        $lastsync = get_string('never', 'googlemeet');
        if ($googlemeet->lastsync) {
            $lastsync = userdate($googlemeet->lastsync, get_string('timedate', 'googlemeet'));
        }

        $meetingcode = client::extract_meeting_code((string)($googlemeet->url ?? ''));
        $redordingname = $meetingcode ? '"' . $meetingcode . '" ' : '';
        if ($googlemeet->originalname) {
            $redordingname .= ($redordingname !== '' ? get_string('or', 'googlemeet') . ' ' : '') .
                '"' . $googlemeet->originalname . '"';
        }

        $loginhtml = '';
        $syncbutton = '';
        $islogged = false;
        $isloggedcreatoremail = $client->get_email() === $googlemeet->creatoremail;
        if (!$client->check_login()) {
            $loginhtml = $client->print_login_popup();
        } else {
            $islogged = true;
            $loginhtml = $client->print_user_info('drive');

            $url = new moodle_url($PAGE->url);
            $url->param('sync', true);
            $syncbutton = new single_button($url, get_string('syncwithgoogledrive', 'googlemeet'), 'post', true);
            $syncbutton = $OUTPUT->render($syncbutton);
        }

        $html .= $OUTPUT->render_from_template('mod_googlemeet/syncbutton', [
            'lastsync' => $lastsync,
            'creatoremail' => $googlemeet->creatoremail,
            'redordingname' => $redordingname,
            'login' => $loginhtml,
            'islogged' => $islogged,
            'syncbutton' => $syncbutton,
            'isloggedcreatoremail' => $isloggedcreatoremail
        ]);
    }

    $html .= '</div>';

    echo $html;
}

/**
 * Transcript shown in the teacher-only hub tab.
 *
 * Prefers the AI analysis transcript and falls back to the recording's own (Meet or subtitle) transcript,
 * as the AI web services and chapter/question generation already do: analyses built from an existing
 * transcript store an empty analysis transcript.
 *
 * @param stdClass|false|null $analysis googlemeet_ai_analysis row.
 * @param stdClass $recording googlemeet_recordings row.
 * @return string Plain-text transcript ('' when none).
 */
function googlemeet_hub_transcript($analysis, $recording): string {
    $transcript = ($analysis && ($analysis->status ?? '') === 'completed') ? (string)($analysis->transcript ?? '') : '';
    if (trim($transcript) === '') {
        $transcript = (string)($recording->transcripttext ?? '');
    }
    return trim($transcript) === '' ? '' : $transcript;
}

/**
 * Returns a recording the current user may open, or false.
 *
 * Trashed recordings, recordings of another activity and hidden recordings (for
 * users without editrecording) are all reported identically as "not available".
 *
 * @param object $googlemeet Activity record.
 * @param context_module $context Activity context.
 * @param int $recordingid Recording id.
 * @return object|false
 */
function googlemeet_get_accessible_recording($googlemeet, $context, int $recordingid) {
    global $DB;

    $recording = $DB->get_record('googlemeet_recordings',
        ['id' => $recordingid, 'googlemeetid' => $googlemeet->id, 'deleted' => 0]);
    if (!$recording) {
        return false;
    }
    if (empty($recording->visible) && !has_capability('mod/googlemeet:editrecording', $context)) {
        return false;
    }
    return $recording;
}

/**
 * Print the per-recording hub.
 *
 * @param object $googlemeet Activity record.
 * @param object $cm Course-module record.
 * @param context_module $context Module context.
 * @param object $recording Recording record scoped by the caller.
 * @return void
 */
function googlemeet_print_recording_hub($googlemeet, $cm, $context, $recording) {
    global $CFG, $DB, $OUTPUT, $PAGE, $USER;

    $caneditrecording = has_capability('mod/googlemeet:editrecording', $context);
    $canmanagequestions = has_capability('mod/googlemeet:managequestions', $context);
    $aiconfig = get_config('googlemeet');
    $aienabled = !empty($aiconfig->enableai) && !empty($aiconfig->geminiapikey);
    $questionservice = new question_service();
    $questions = $questionservice->get_questions($googlemeet, $cm, $context, $recording->id, !$canmanagequestions);
    $draftcount = 0;
    $publishedcount = 0;
    foreach ($questions as $question) {
        if ($question['isready']) {
            $publishedcount++;
        } else if ($question['isdraft']) {
            $draftcount++;
        }
    }
    if ($canmanagequestions && $questions) {
        // Teacher view: how students did on each question in the practice (ANA-05 attempts).
        $practicestats = \mod_googlemeet\local\practice_attempts::get_question_stats((int)$googlemeet->id,
            (int)$recording->id);
        foreach ($questions as &$question) {
            $stat = $practicestats[(int)$question['id']] ?? null;
            $question['haspracticestats'] = !empty($stat['attempts']);
            $question['practicestatslabel'] = $question['haspracticestats']
                ? get_string('question_practice_stats', 'googlemeet', (object)[
                    'pct' => $stat['correctpct'], 'attempts' => $stat['attempts'], 'users' => $stat['users']])
                : '';
            $question['practicestatslow'] = $question['haspracticestats'] && $stat['correctpct'] < 50;
        }
        unset($question);
    }

    $analysis = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recording->id]);
    // IA-04: an unreviewed analysis is hidden from students (teachers see it with a review banner).
    $aireview = \mod_googlemeet\local\ai_review::hub_context($analysis, $context, (int)$cm->id,
        $aienabled && has_capability('mod/googlemeet:generateai', $context));
    $analysiscompleted = $analysis && $analysis->status === 'completed' && $aireview['aicontentvisible'];
    $statusflags = googlemeet_ai_status_flags($analysis->status ?? null);
    // Teacher-only tab: never render the transcript into a student's page.
    $hubtranscript = $caneditrecording ? googlemeet_hub_transcript($analysis, $recording) : '';
    $keypoints = [];
    $topics = [];
    $chapters = [];
    if ($analysiscompleted) {
        $keypoints = json_decode($analysis->keypoints) ?: [];
        $topics = json_decode($analysis->topics) ?: [];
        $chapters = googlemeet_normalise_chapters($analysis->chapters ?? '');
    }

    $rpage = optional_param('rpage', 0, PARAM_INT);
    $rorder = optional_param('rorder', null, PARAM_ALPHA);
    $recordingsorder = strtoupper($rorder ?: ($googlemeet->recordingsorder ?? 'DESC'));
    $recordingsorder = $recordingsorder === 'ASC' ? 'ASC' : 'DESC';
    $backparams = ['id' => $cm->id];
    if ($rpage > 0) {
        $backparams['rpage'] = $rpage;
    }
    if ($rorder) {
        $backparams['rorder'] = $rorder;
    }
    $recordingnavparams = ['googlemeetid' => $googlemeet->id];
    if (!$caneditrecording) {
        $recordingnavparams['visible'] = true;
    }
    $recordingnavitems = array_values(googlemeet_list_recordings($recordingnavparams, false, $recordingsorder, 0, 0));
    $previousrecording = null;
    $nextrecording = null;
    foreach ($recordingnavitems as $index => $navrecording) {
        if ((int)$navrecording->id !== (int)$recording->id) {
            continue;
        }
        if ($index > 0) {
            $previousrecording = $recordingnavitems[$index - 1];
        }
        if ($index < count($recordingnavitems) - 1) {
            $nextrecording = $recordingnavitems[$index + 1];
        }
        break;
    }
    // Students think of "previous/next class" chronologically, whatever the list order is.
    if ($recordingsorder === 'DESC') {
        [$previousrecording, $nextrecording] = [$nextrecording, $previousrecording];
    }
    $previousparams = $backparams;
    $nextparams = $backparams;
    if ($previousrecording) {
        $previousparams['recording'] = $previousrecording->id;
    }
    if ($nextrecording) {
        $nextparams['recording'] = $nextrecording->id;
    }
    $activetab = optional_param('tab', '', PARAM_ALPHA);

    $materials = googlemeet_get_recording_materials($context, $recording->id);
    $hasmaterials = !empty($materials);
    $showmaterials = $hasmaterials || $caneditrecording;

    $materialsactive = $showmaterials && $activetab === 'materials';
    $summaryactive = !$materialsactive;
    $progress = $DB->get_record('googlemeet_recording_progress',
        ['recordingid' => $recording->id, 'userid' => $USER->id],
        'watchedseconds,completed');
    $progressstate = googlemeet_recording_progress_state($recording, $progress ?: null);
    $canembed = googlemeet_recording_can_embed((string)$recording->webviewlink);
    $embedurl = $canembed ? googlemeet_get_recording_embed_url((string)$recording->webviewlink) : '';
    $durationseconds = googlemeet_recording_duration_to_seconds((string)$recording->duration);
    // Deep link: view.php?id=..&recording=..&t=<seconds> opens the Drive player at that second.
    $starttime = max(0, optional_param('t', 0, PARAM_INT));
    if ($durationseconds > 0) {
        $starttime = min($starttime, $durationseconds);
    }
    $playerurl = $canembed ? googlemeet_get_recording_seek_url((string)$recording->webviewlink, $starttime) : '';
    // Approximate "continue where you left off": the last chapter/timestamp this user jumped to.
    // Drive's iframe does not expose its playback position, so this is not the real position.
    $lastjump = 0;
    if ($canembed && $starttime === 0 && isloggedin() && !isguestuser()) {
        $lastjump = max(0, (int)get_user_preferences(googlemeet_lastjump_preference_name((int)$recording->id), 0));
        if ($durationseconds > 0 && $lastjump > $durationseconds) {
            $lastjump = 0;
        }
    }

    $lessontitles = googlemeet_get_lesson_titles($googlemeet, $caneditrecording);
    $lessontitle = $lessontitles[$recording->id] ?? [
        'title' => googlemeet_display_name((string)$recording->name), 'subtitle' => '', 'hassubtitle' => false,
    ];
    // The page header already shows the activity name: do not repeat it as the lesson subtitle.
    if (!empty($lessontitle['hassubtitle'])
            && core_text::strtolower(trim(format_string($lessontitle['subtitle'])))
                === core_text::strtolower(trim(format_string($googlemeet->name)))) {
        $lessontitle['subtitle'] = '';
        $lessontitle['hassubtitle'] = false;
    }
    $navtitle = static function(?stdClass $navrecording) use ($lessontitles): string {
        if (!$navrecording) {
            return '';
        }
        return format_string($lessontitles[$navrecording->id]['title'] ?? googlemeet_display_name((string)$navrecording->name));
    };
    $navdate = static function(?stdClass $navrecording): string {
        return $navrecording ? userdate((int)$navrecording->createdtime, get_string('strftimedmy', 'googlemeet')) : '';
    };

    $templatecontext = array_merge([
        'cmid' => $cm->id,
        'recordingid' => $recording->id,
        'name' => format_string($lessontitle['title']),
        'hassubtitle' => $lessontitle['hassubtitle'],
        'subtitle' => format_string($lessontitle['subtitle']),
        'datelabel' => userdate((int)$recording->createdtime, get_string('strftimedaydate', 'langconfig')),
        'datetimeiso' => date('c', (int)$recording->createdtime),
        'previousrecordingtitle' => $navtitle($previousrecording),
        'previousrecordingdate' => $navdate($previousrecording),
        'nextrecordingtitle' => $navtitle($nextrecording),
        'nextrecordingdate' => $navdate($nextrecording),
        'originalname' => $recording->name,
        'duration' => s($recording->duration),
        'durationseconds' => $durationseconds,
        'starttime' => $canembed ? $starttime : 0,
        'playerurl' => $playerurl,
        'hasresume' => $lastjump > 0,
        'resumeseconds' => $lastjump,
        'resumelabel' => $lastjump > 0 ? googlemeet_hub_resume_label($lastjump, $chapters) : '',
        'huburl' => (new moodle_url('/mod/googlemeet/view.php',
            ['id' => $cm->id, 'recording' => $recording->id]))->out(false),
        'webviewlink' => $recording->webviewlink,
        'canembed' => $canembed,
        'embedurl' => $embedurl,
        'backurl' => (new moodle_url('/mod/googlemeet/view.php', $backparams))->out(false),
        'hasrecordingnav' => !empty($previousrecording) || !empty($nextrecording),
        'haspreviousrecording' => !empty($previousrecording),
        'previousrecordingurl' => $previousrecording
            ? (new moodle_url('/mod/googlemeet/view.php', $previousparams))->out(false)
            : '',
        'hasnextrecording' => !empty($nextrecording),
        'nextrecordingurl' => $nextrecording
            ? (new moodle_url('/mod/googlemeet/view.php', $nextparams))->out(false)
            : '',
        'caneditrecording' => $caneditrecording,
        'canmanagequestions' => $canmanagequestions,
        'visibilitybuttonlabel' => get_string(
            !empty($recording->visible) ? 'recording_hide_from_students_button' : 'recording_show_to_students_button',
            'googlemeet'
        ),
        'aienabled' => $aienabled,
        'sesskey' => sesskey(),
        'hasanalysis' => $analysiscompleted,
        'aistatusisprocessing' => $statusflags['aistatusisprocessing'],
        'aistatusispending' => $statusflags['aistatusispending'],
        'aistatusisfailed' => $statusflags['aistatusisfailed'],
        'aierror' => ($caneditrecording && $analysis && !empty($analysis->error)) ? s($analysis->error) : '',
        'summary' => $analysiscompleted ? implode('', array_map(static function(string $paragraph) use ($context) {
            return '<p>' . format_text($paragraph, FORMAT_PLAIN, ['context' => $context, 'para' => false]) . '</p>';
        }, googlemeet_summary_paragraphs((string)$analysis->summary))) : '',
        'keypoints' => array_map(static function($point, $index) {
            return ['text' => (string)$point, 'number' => $index + 1];
        }, array_values($keypoints), array_keys(array_values($keypoints))),
        'keypointcount' => count($keypoints),
        'keypointshash' => googlemeet_keypoints_hash($keypoints),
        'keypointsstate' => (isloggedin() && !isguestuser())
            ? googlemeet_keypoints_state(
                (string)get_user_preferences(googlemeet_keypoints_preference_name((int)$recording->id), ''),
                googlemeet_keypoints_hash($keypoints), count($keypoints))
            : '',
        'topics' => array_map(static function($topic) use ($cm) {
            return [
                'text' => (string)$topic,
                'url' => (new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id, 'topic' => (string)$topic]))->out(false),
            ];
        }, $topics),
        'chapters' => $chapters,
        'haschapters' => !empty($chapters),
        'chaptercount' => count($chapters),
        'transcript' => $hubtranscript !== '' ? format_text($hubtranscript, FORMAT_PLAIN, ['context' => $context]) : '',
        'hastranscript' => $hubtranscript !== '',
        'hasnotes' => !empty($recording->notestext),
        'notes' => !empty($recording->notestext)
            ? format_text($recording->notestext, FORMAT_HTML, ['context' => $context])
            : '',
        'questions' => $questions,
        'hasquestions' => !empty($questions),
        // Students only get the Questions tab when there is something to practise (published questions).
        'showquestionstab' => $canmanagequestions || !empty($questions),
        'draftcount' => $draftcount,
        'publishedcount' => $publishedcount,
        'questioncount' => count($questions),
        'hasdrafts' => $draftcount > 0,
        'activitydraftcount' => $canmanagequestions
            ? $questionservice->count_activity_drafts($googlemeet, $cm, $context) : 0,
        'generationqueued' => $questionservice->is_generation_queued($recording->id),
        'summaryactive' => $summaryactive,
        // Preguntas is never the initially-active tab (default is Resumen, or Materiales); always false.
        'questionsactive' => false,
        'showmaterials' => $showmaterials,
        'materialsactive' => $materialsactive,
        'materials' => $materials,
        'hasmaterials' => $hasmaterials,
        'materialcount' => count($materials),
        'managematerialsurl' => (new moodle_url('/mod/googlemeet/material.php',
            ['id' => $cm->id, 'recording' => $recording->id]))->out(false),
    ], $progressstate, $aireview);

    $PAGE->requires->js(new moodle_url($CFG->wwwroot . '/mod/googlemeet/assets/js/build/jstable.min.js'));
    echo $OUTPUT->render_from_template('mod_googlemeet/recording_hub', $templatecontext);
}

/**
 * Student-facing label for the hub "continue" button.
 *
 * "Continue with chapter «X» (14:02)" when the remembered second is a chapter start, else "Continue at 14:02".
 *
 * @param int $seconds Remembered offset (last jump), > 0.
 * @param array $chapters Normalised chapters (googlemeet_normalise_chapters()).
 * @return string
 */
function googlemeet_hub_resume_label(int $seconds, array $chapters): string {
    $time = googlemeet_format_seconds_timestamp($seconds);
    foreach ($chapters as $chapter) {
        if (abs((int)$chapter['startseconds'] - $seconds) <= 2) {
            return get_string('hub_resume_chapter', 'googlemeet', (object)[
                'title' => format_string($chapter['title']),
                'time' => $time,
            ]);
        }
    }
    return get_string('hub_resume_time', 'googlemeet', $time);
}

/**
 * Split an AI summary into paragraphs (blank-line separated), dropping empty ones.
 *
 * Single line breaks inside a paragraph are kept (format_text turns them into <br>),
 * so list-like summaries still read correctly.
 *
 * @param string $summary Plain-text summary.
 * @return string[] Paragraphs, trimmed.
 */
function googlemeet_summary_paragraphs(string $summary): array {
    $summary = str_replace(["\r\n", "\r"], "\n", $summary);
    $paragraphs = preg_split('/\n\s*\n/', $summary) ?: [];
    return array_values(array_filter(array_map('trim', $paragraphs), static function(string $paragraph) {
        return $paragraph !== '';
    }));
}

/**
 * Get downloadable material files for a recording.
 *
 * @param context_module $context Module context.
 * @param int $recordingid Recording ID.
 * @return array
 */
function googlemeet_get_recording_materials(context_module $context, int $recordingid): array {
    global $OUTPUT;

    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_googlemeet', 'recordingmaterial', $recordingid, 'filename', false);
    $materials = [];

    foreach ($files as $file) {
        if ($file->is_directory()) {
            continue;
        }

        $filepath = $file->get_filepath();
        $materials[] = [
            'name' => $file->get_filename(),
            'icon' => $OUTPUT->image_url(file_file_icon($file), 'moodle')->out(false),
            'size' => display_size($file->get_filesize()),
            'modified' => userdate($file->get_timemodified(), get_string('strftimedatetimeshort')),
            'filepath' => ($filepath !== '/' && $filepath !== '') ? trim($filepath, '/') : '',
            'url' => moodle_url::make_pluginfile_url(
                $context->id,
                'mod_googlemeet',
                'recordingmaterial',
                $recordingid,
                $filepath,
                $file->get_filename(),
                true
            )->out(false),
        ];
    }

    return $materials;
}

/**
 * Save the teacher attachment files submitted through the mod form draft area.
 *
 * Files are stored in the module context under the 'attachment' file area (itemid 0).
 * No DB schema is needed: they live in mdl_files keyed by context + file area.
 *
 * @param object $googlemeet Instance object from the form (needs ->coursemodule and ->attachments).
 * @return void
 */
function googlemeet_save_attachments($googlemeet): void {
    if (empty($googlemeet->coursemodule) || !isset($googlemeet->attachments)) {
        return;
    }
    $context = context_module::instance($googlemeet->coursemodule);
    file_save_draft_area_files(
        $googlemeet->attachments,
        $context->id,
        'mod_googlemeet',
        'attachment',
        0,
        ['subdirs' => 0]
    );
}

/**
 * Print the teacher-provided attachment files as a download list for students.
 *
 * Does nothing if the activity has no attachments.
 *
 * @param context_module $context Module context.
 * @return void
 */
function googlemeet_print_attachments(context_module $context): void {
    global $OUTPUT;

    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_googlemeet', 'attachment', 0, 'filename', false);
    if (!$files) {
        return;
    }

    echo html_writer::start_div('googlemeet-attachments mt-4');
    echo $OUTPUT->heading(get_string('attachmentsheader', 'googlemeet'), 4);
    echo html_writer::start_tag('ul', ['class' => 'list-unstyled mb-0']);

    foreach ($files as $file) {
        if ($file->is_directory()) {
            continue;
        }
        $filename = $file->get_filename();
        $url = moodle_url::make_pluginfile_url(
            $context->id,
            'mod_googlemeet',
            'attachment',
            0,
            $file->get_filepath(),
            $filename,
            true
        );
        $icon = $OUTPUT->pix_icon(file_file_icon($file), '', 'moodle', ['class' => 'me-2']);
        $size = html_writer::tag('span', ' (' . display_size($file->get_filesize()) . ')',
            ['class' => 'text-muted small']);
        echo html_writer::tag('li',
            html_writer::link($url, $icon . s($filename) . $size),
            ['class' => 'mb-1']);
    }

    echo html_writer::end_tag('ul');
    echo html_writer::end_div();
}

/**
 * Whether a Google Drive URL can be embedded with the preview player.
 *
 * @param string $webviewlink Drive web view URL.
 * @return bool
 */
function googlemeet_recording_can_embed(string $webviewlink): bool {
    return preg_match('~/file/d/([^/]+)~', $webviewlink) === 1;
}

/**
 * Convert a Google Drive view URL to an embeddable preview URL when possible.
 *
 * @param string $webviewlink Drive web view URL.
 * @return string
 */
function googlemeet_get_recording_embed_url(string $webviewlink): string {
    if (preg_match('~/file/d/([^/?#]+)~', $webviewlink, $matches)) {
        return 'https://drive.google.com/file/d/' . rawurlencode($matches[1]) . '/preview';
    }
    return $webviewlink;
}

/**
 * Build the Drive preview URL that starts playback at a given second.
 *
 * Google Drive's /preview player honours a `t` query parameter (seconds), so seeking is done by
 * reloading the iframe with `?t=<seconds>`. Returns the plain embed URL when no offset is needed,
 * and the original link untouched when it is not an embeddable Drive file.
 *
 * @param string $webviewlink Drive web view URL.
 * @param int $seconds Start offset in seconds (negative values are clamped to 0).
 * @return string
 */
function googlemeet_get_recording_seek_url(string $webviewlink, int $seconds): string {
    if (!googlemeet_recording_can_embed($webviewlink)) {
        return $webviewlink;
    }
    $url = googlemeet_get_recording_embed_url($webviewlink);
    $seconds = max(0, $seconds);
    return $seconds > 0 ? $url . '?t=' . $seconds : $url;
}

/**
 * This clears the url.
 *
 * @param string $url
 * @return mixed The url if valid or false if invalid
 */
function googlemeet_clear_url($url) {
    $pattern = "/meet.google.com\/[a-zA-Z0-9]{3}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{3}/";
    preg_match($pattern, $url, $matches, PREG_OFFSET_CAPTURE);

    if ($matches) {
        return 'https://' . $matches[0][0];
    }

    return null;
}

/**
 * This checks if have recordings from the googlemeet.
 *
 * @param int $googlemeetid
 * @return boolean
 */
function googlemeet_has_recording($googlemeetid) {
    global $DB;

    // Use record_exists() instead of get_records() for efficiency.
    // record_exists() stops at first match, while get_records() loads all data.
    return $DB->record_exists('googlemeet_recordings', ['googlemeetid' => $googlemeetid, 'deleted' => 0]);
}

/**
 * Generates the list of users who should receive the reminder for an event and have not yet been notified.
 *
 * Reminders are for students only. Recipients are users who hold a role with the 'student'
 * archetype in the module context or any parent context (course, category, system), have an
 * active enrolment in the course, can view the activity (mod/googlemeet:view), are not suspended,
 * satisfy the activity's access restrictions and do not manage activities in the course.
 * Users already recorded in googlemeet_notify_done for the event and reminder kind are excluded.
 *
 * @param int $eventid the event ID
 * @param int $kind reminder kind (\mod_googlemeet\local\reminders::KIND_*), deduplicated separately
 * @return stdClass[] users keyed by id (full user records)
 */
function googlemeet_get_users_to_notify($eventid, int $kind = 0) {
    global $DB;

    $event = $DB->get_record('googlemeet_events', ['id' => $eventid], 'id, googlemeetid');
    if (!$event) {
        return [];
    }

    $cm = get_coursemodule_from_instance('googlemeet', $event->googlemeetid, 0, false, IGNORE_MISSING);
    if (!$cm || empty($cm->visible) || !empty($cm->deletioninprogress)) {
        return [];
    }

    $course = $DB->get_record('course', ['id' => $cm->course], 'id, visible');
    if (!$course || empty($course->visible)) {
        return [];
    }

    $context = context_module::instance($cm->id, IGNORE_MISSING);
    if (!$context) {
        return [];
    }

    // Active enrolments only (enrolment status, enrol instance status and time window).
    $candidates = get_enrolled_users($context, 'mod/googlemeet:view', 0, 'u.*', null, 0, 0, true);
    if (!$candidates) {
        return [];
    }

    // Students only: users with a 'student'-archetype role in the module context or its parents.
    $studentroleids = array_keys(get_archetype_roles('student'));
    if (!$studentroleids) {
        return [];
    }
    [$rolesql, $roleparams] = $DB->get_in_or_equal($studentroleids, SQL_PARAMS_NAMED, 'role');
    [$ctxsql, $ctxparams] = $DB->get_in_or_equal($context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'ctx');
    $students = array_flip($DB->get_fieldset_sql(
        "SELECT DISTINCT ra.userid
           FROM {role_assignments} ra
          WHERE ra.roleid $rolesql AND ra.contextid $ctxsql",
        $roleparams + $ctxparams
    ));

    $notified = array_flip($DB->get_fieldset_select('googlemeet_notify_done', 'userid', 'eventid = ? AND kind = ?',
        [$eventid, $kind]));

    $users = [];
    foreach ($candidates as $user) {
        if (!isset($students[$user->id]) || isset($notified[$user->id])
                || !empty($user->suspended) || !empty($user->deleted)) {
            continue;
        }
        // A student who also manages activities here (e.g. also editing teacher) is not reminded.
        if (has_capability('moodle/course:manageactivities', $context, $user)) {
            continue;
        }
        // Respect visibility and access restrictions (availability conditions) for this user.
        if (!\core_availability\info_module::is_user_visible($cm, $user->id, false)) {
            continue;
        }
        $users[$user->id] = $user;
    }

    return $users;
}

/**
 * Returns the sessions with a reminder due now (one entry per session and reminder kind).
 *
 * @return stdClass[]
 */
function googlemeet_get_future_events() {
    return \mod_googlemeet\local\reminders::get_due_events(time());
}

/**
 * Find googlemeet instances whose recurrence looks abandoned: they still schedule future
 * sessions and have recorded at least once, but have had no recording in the last $weeks weeks.
 *
 * Read-only. Uses a recordset (not get_records_sql) to avoid first-column indexing pitfalls.
 *
 * @param int $weeks Staleness threshold in weeks.
 * @param int|null $now Reference timestamp (defaults to time()); injectable for tests.
 * @return array Array of stdClass {id, name, course, coursename, cmid, lastrecording, futurecount}.
 */
function googlemeet_get_stale_recurrences(int $weeks, ?int $now = null): array {
    global $DB;

    $now = $now ?? time();
    $moduleid = $DB->get_field('modules', 'id', ['name' => 'googlemeet'], MUST_EXIST);

    $sql = "SELECT g.id, g.name, g.course, c.fullname AS coursename, cm.id AS cmid,
                   (SELECT MAX(r.createdtime)
                      FROM {googlemeet_recordings} r
                     WHERE r.googlemeetid = g.id AND r.deleted = 0) AS lastrecording,
                   (SELECT COUNT(1)
                      FROM {googlemeet_events} e
                     WHERE e.googlemeetid = g.id AND e.eventdate > :now1) AS futurecount
              FROM {googlemeet} g
              JOIN {course_modules} cm ON cm.instance = g.id AND cm.module = :moduleid
              JOIN {course} c ON c.id = g.course
             WHERE EXISTS (SELECT 1 FROM {googlemeet_events} e2
                            WHERE e2.googlemeetid = g.id AND e2.eventdate > :now2)
               AND EXISTS (SELECT 1 FROM {googlemeet_recordings} r2
                            WHERE r2.googlemeetid = g.id AND r2.deleted = 0)";
    $params = ['now1' => $now, 'now2' => $now, 'moduleid' => $moduleid];

    $threshold = $weeks * 7 * DAYSECS;
    $stale = [];
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach ($rs as $row) {
        if ($row->lastrecording !== null && ($now - (int) $row->lastrecording) > $threshold) {
            $stale[] = $row;
        }
    }
    $rs->close();

    return $stale;
}

/**
 * Send the abandoned-recurrence alert to every site admin.
 *
 * @param stdClass $info A row returned by googlemeet_get_stale_recurrences().
 * @return void
 */
function googlemeet_send_stale_alert(stdClass $info): void {
    $editurl = new moodle_url('/course/modedit.php', ['update' => $info->cmid]);
    $lastrec = $info->lastrecording
        ? userdate((int) $info->lastrecording, get_string('strftimedate', 'langconfig'))
        : '-';

    $a = (object) [
        'activity' => format_string($info->name),
        'course' => format_string($info->coursename),
        'lastrecording' => $lastrec,
        'futurecount' => (int) $info->futurecount,
        'editurl' => $editurl->out(false),
    ];

    $subject = get_string('stalerecurrence_subject', 'mod_googlemeet', $a);

    foreach (get_admins() as $admin) {
        $message = new \core\message\message();
        $message->component = 'mod_googlemeet';
        $message->name = 'stalerecurrence';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $admin;
        $message->subject = $subject;
        $message->fullmessage = get_string('stalerecurrence_body', 'mod_googlemeet', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = get_string('stalerecurrence_body_html', 'mod_googlemeet', $a);
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $editurl->out(false);
        $message->contexturlname = get_string('stalerecurrence_editlink', 'mod_googlemeet');
        message_send($message);
    }
}

/**
 * Send a reminder to a student about the event (see \mod_googlemeet\local\reminders).
 *
 * @param object $user
 * @param object $event due reminder row (optionally with ->kind)
 * @return void
 */
function googlemeet_send_notification($user, $event) {
    \mod_googlemeet\local\reminders::send($user, $event);
}

/**
 * Records the sending of the notification to not send repeated.
 *
 * @param int $userid
 * @param int $eventid
 * @param int $kind reminder kind (\mod_googlemeet\local\reminders::KIND_*)
 */
function googlemeet_notify_done($userid, $eventid, int $kind = 0) {
    global $DB;

    $notifydone = new stdClass();
    $notifydone->userid = $userid;
    $notifydone->eventid = $eventid;
    $notifydone->kind = $kind;
    $notifydone->timesent = time();

    return $DB->insert_record('googlemeet_notify_done', $notifydone);
}

/**
 * Removes records of past event notification notifications.
 */
function googlemeet_remove_notify_done_from_old_events() {
    global $DB;

    $now = time();

    // Bulk delete using subquery instead of N+1 queries.
    // Delete all notify_done records for events that have already passed.
    $sql = "DELETE FROM {googlemeet_notify_done}
             WHERE eventid IN (SELECT id FROM {googlemeet_events} WHERE eventdate < :now)";

    $DB->execute($sql, ['now' => $now]);
}

/**
 * Mount the body content of the notification.
 *
 * @param object $user db record of user
 * @param object $event db record of event
 * @return string - the content of the notification after assembly.
 */
function googlemeet_get_messagehtml($user, $event) {
    global $CFG;

    $config = get_config('googlemeet');

    $startdate = userdate($event->eventdate, get_string('strftimedmy', 'googlemeet'), $user->timezone);
    $starttime = userdate($event->eventdate, get_string('strftimehm', 'googlemeet'), $user->timezone);
    $endtime = userdate($event->eventdate + $event->duration, get_string('strftimehm', 'googlemeet'), $user->timezone);
    $url = "<a href=\"{$CFG->wwwroot}/mod/googlemeet/view.php?id={$event->cmid}\">
        {$CFG->wwwroot}/mod/googlemeet/view.php?id={$event->cmid}</a>";

    // User- and teacher-controlled values (names, course/activity titles) are escaped with s()
    // before being spliced into the admin's HTML email template, otherwise a crafted value
    // injects HTML/script into the notification body. $url is built by us (cmid is an int) and is
    // deliberately HTML; the date/time/timezone values come from Moodle formatters and are safe.
    $templatevars = [
        '%userfirstname%' => s($user->firstname),
        '%userlastname%' => s($user->lastname),
        '%coursename%' => s($event->coursename),
        '%googlemeetname%' => s($event->googlemeetname),
        '%eventdate%' => $startdate,
        '%duration%' => $starttime . ' – ' . $endtime,
        '%timezone%' => usertimezone($user->timezone),
        '%url%' => $url,
        '%cmid%' => (int) $event->cmid,
    ];

    // Use str_replace (literal), not preg_replace: replacement values can legitimately contain
    // regex backreference sequences such as $0 or \1 (e.g. in a user's name), which preg_replace
    // would interpret and corrupt.
    $template = !empty($config->emailcontent) ? $config->emailcontent : get_string('emailcontent_default', 'googlemeet');
    $emailcontent = str_replace(array_keys($templatevars), array_values($templatevars), $template);

    return $emailcontent;
}

/**
 * Format time difference for display.
 *
 * @param int $seconds Time difference in seconds
 * @return string Formatted time string
 */
function googlemeet_format_time_diff($seconds) {
    $seconds = abs($seconds);

    if ($seconds < 60) {
        return get_string('event_time_minutes', 'googlemeet', 1);
    } else if ($seconds < 3600) {
        $minutes = round($seconds / 60);
        return get_string('event_time_minutes', 'googlemeet', $minutes);
    } else if ($seconds < 86400) {
        $hours = round($seconds / 3600);
        return get_string('event_time_hours', 'googlemeet', $hours);
    } else {
        $days = round($seconds / 86400);
        return get_string('event_time_days', 'googlemeet', $days);
    }
}

/**
 * upcoming googlemeet events.
 *
 * @param int $googlemeetid db record of user
 * @param int $maxevents Maximum number of events to return (default 3). Use 0 with $nolimit for all events.
 * @param bool $nolimit Whether to skip the display limit.
 */
function googlemeet_get_upcoming_events($googlemeetid, $maxevents = 3, bool $nolimit = false) {
    global $DB, $USER;

    $now = time();

    // Get cancelled dates for this instance.
    $cancelleddates = googlemeet_get_cancelled($googlemeetid);

    // Ensure maxevents is within bounds unless the full schedule explicitly requests all rows.
    $maxevents = max(1, min(10, (int)$maxevents));
    $limitclause = $nolimit ? '' : ' LIMIT ' . $maxevents;

    // Get events that are upcoming or currently in progress (started less than duration ago).
    $sql = "SELECT id, eventdate, duration
              FROM {googlemeet_events}
             WHERE googlemeetid = :googlemeetid
               AND (eventdate + duration) > :now
          ORDER BY eventdate ASC" . $limitclause;

    $events = $DB->get_records_sql($sql, ['googlemeetid' => $googlemeetid, 'now' => $now]);
    $upcomingevents = [];

    if ($events) {
        foreach ($events as $event) {
            $start = $event->eventdate;
            $end = $event->eventdate + $event->duration;
            $duration = $event->duration;

            $datetime = new DateTime();
            $datetime->setTimestamp($now);
            $nowdate = $datetime->format('Y-m-d');

            $datetime->setTimestamp($start);
            $startdate = $datetime->format('Y-m-d');

            $upcomingevent = new stdClass();
            $upcomingevent->today = ($nowdate === $startdate);
            $upcomingevent->startdate = userdate($start, get_string('strftimedmy', 'googlemeet'), $USER->timezone);
            $upcomingevent->compactdate = googlemeet_format_date_chip(
                userdate($start, get_string('strftimedmweekday', 'googlemeet'), $USER->timezone)
            );
            $upcomingevent->starttime = userdate($start, get_string('strftimehm', 'googlemeet'), $USER->timezone);
            $upcomingevent->endtime = userdate($end, get_string('strftimehm', 'googlemeet'), $USER->timezone);
            $upcomingevent->timestamp = $start;
            $upcomingevent->duration = $duration;
            $upcomingevent->durationformatted = googlemeet_format_time_diff($duration);

            // Check if this event is cancelled.
            $cancelled = googlemeet_is_cancelled($start, $cancelleddates);
            if ($cancelled !== false) {
                $upcomingevent->status = 'cancelled';
                $upcomingevent->islive = false;
                $upcomingevent->issoon = false;
                $upcomingevent->isscheduled = false;
                $upcomingevent->iscancelled = true;
                $upcomingevent->cancelledreason = $cancelled->reason ?? '';
                $upcomingevent->timeinfo = '';
            } else {
                $upcomingevent->iscancelled = false;
                $upcomingevent->cancelledreason = '';

                // Calculate status.
                $timediff = $start - $now;

                if ($now >= $start && $now < $end) {
                    // Event is currently in progress.
                    $upcomingevent->status = 'live';
                    $upcomingevent->islive = true;
                    $upcomingevent->issoon = false;
                    $upcomingevent->isscheduled = false;
                    $elapsed = $now - $start;
                    $upcomingevent->timeinfo = get_string('event_started_ago', 'googlemeet', googlemeet_format_time_diff($elapsed));
                } else if ($timediff > 0 && $timediff <= 1800) {
                    // Event starts within 30 minutes.
                    $upcomingevent->status = 'soon';
                    $upcomingevent->islive = false;
                    $upcomingevent->issoon = true;
                    $upcomingevent->isscheduled = false;
                    $upcomingevent->countdownprefix = get_string('event_countdown_starts_in_prefix', 'googlemeet');
                    $upcomingevent->countdownexpired = get_string('event_countdown_started', 'googlemeet');
                    $upcomingevent->timeinfo = get_string('event_starts_in', 'googlemeet', googlemeet_format_time_diff($timediff));
                } else {
                    // Event is scheduled for later.
                    $upcomingevent->status = 'scheduled';
                    $upcomingevent->islive = false;
                    $upcomingevent->issoon = false;
                    $upcomingevent->isscheduled = true;
                    $upcomingevent->countdownprefix = get_string('event_countdown_starts_in_prefix', 'googlemeet');
                    $upcomingevent->countdownexpired = get_string('event_countdown_started', 'googlemeet');
                    $upcomingevent->timeinfo = get_string('event_starts_in', 'googlemeet', googlemeet_format_time_diff($timediff));
                }
            }

            $upcomingevents[] = $upcomingevent;
        }

        // Get first event for backward compatibility.
        $firstevent = reset($upcomingevents);

        return [
            'hasupcomingevents' => true,
            'upcomingevents' => $upcomingevents,
            'starttime' => $firstevent->starttime,
            'endtime' => $firstevent->endtime,
            'duration' => $firstevent->duration,
            'hasliveevent' => $firstevent->islive ?? false,
        ];
    }

    return [
        'hasupcomingevents' => false,
        'upcomingevents' => [],
    ];
}
