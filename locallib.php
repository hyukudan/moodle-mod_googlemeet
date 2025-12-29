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

    googlemeet_delete_events($events[0]->googlemeetid);

    foreach ($events as $event) {
        $event->id = $DB->insert_record('googlemeet_events', $event);
        helper::create_calendar_event($googlemeet, $event);
    }
}

/**
 * This creates new events given as timeopen and timeclose by googlemeet.
 *
 * @param object $googlemeet
 * @param object $cm
 * @param object $context
 * @param int $page Current page number (0-based).
 * @param string|null $orderoverride Optional order override from URL parameter.
 * @return void
 */
function googlemeet_print_recordings($googlemeet, $cm, $context, $page = 0, $orderoverride = null) {
    global $CFG, $PAGE, $OUTPUT;

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

    $html = '<div id="googlemeet_recordings" class="googlemeet_recordings">';

    // Check if AI features are enabled.
    $aienabled = (bool) get_config('googlemeet', 'enableai');
    $apikey = get_config('googlemeet', 'geminiapikey');
    $aienabled = $aienabled && !empty($apikey);
    $cangenerateai = $aienabled && has_capability('mod/googlemeet:generateai', $context);

    // Get pagination settings.
    $maxrecordings = isset($googlemeet->maxrecordings) ? (int) $googlemeet->maxrecordings : 5;
    $maxrecordings = max(1, min(20, $maxrecordings));

    // Get order - use override if provided, otherwise use instance setting.
    $order = $orderoverride !== null ? $orderoverride : ($googlemeet->recordingsorder ?? 'DESC');
    $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

    // Calculate offset.
    $page = max(0, (int) $page);
    $offset = $page * $maxrecordings;

    // Get total count for pagination.
    $totalrecordings = googlemeet_count_recordings($params);

    // Calculate pagination info.
    $totalpages = ceil($totalrecordings / $maxrecordings);
    $page = min($page, max(0, $totalpages - 1)); // Ensure page is within bounds.
    $offset = $page * $maxrecordings;

    // Include AI data when fetching recordings if AI is enabled.
    $recordings = googlemeet_list_recordings($params, $aienabled, $order, $maxrecordings, $offset);

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

    $html .= $OUTPUT->render_from_template('mod_googlemeet/recordingstable', [
        'recordings' => $recordings,
        'hasrecordings' => !empty($recordings),
        'coursemoduleid' => $cm->id,
        'hascapability' => $hascapability,
        'aienabled' => $aienabled,
        'cangenerateai' => $cangenerateai,
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
    ]);

    $PAGE->requires->js(new moodle_url($CFG->wwwroot . '/mod/googlemeet/assets/js/build/jstable.min.js'));

    if ($hascapability) {
        $lastsync = get_string('never', 'googlemeet');
        if ($googlemeet->lastsync) {
            $lastsync = userdate($googlemeet->lastsync, get_string('timedate', 'googlemeet'));
        }

        $redordingname = '"' . substr($googlemeet->url, 24, 12) . '" ';
        if ($googlemeet->originalname) {
            $redordingname .= get_string('or', 'googlemeet') . ' "' . $googlemeet->originalname . '"';
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
    return $DB->record_exists('googlemeet_recordings', ['googlemeetid' => $googlemeetid]);
}

/**
 * Generates a list of users who have not yet been notified.
 *
 * @param int $eventid the event ID
 * @return stdClass list of users
 */
function googlemeet_get_users_to_notify($eventid) {
    global $DB;

    // Get the student role ID dynamically instead of hardcoding.
    $studentrole = $DB->get_record('role', ['shortname' => 'student'], 'id');
    $studentroleid = $studentrole ? $studentrole->id : 5;

    $sql = "SELECT DISTINCT
                   u.*
              FROM {googlemeet_events} me
        INNER JOIN {googlemeet} m
                ON m.id = me.googlemeetid
        INNER JOIN {course_modules} cm
                ON (cm.instance = m.id AND cm.visible = 1 AND cm.deletioninprogress = 0)
        INNER JOIN {course} c
                ON (c.id = cm.course AND c.visible = 1)
        INNER JOIN {modules} md
                ON (md.id = cm.module AND md.name = 'googlemeet')
        INNER JOIN {context} ctx
                ON ctx.instanceid = c.id
        INNER JOIN {role_assignments} ra
                ON (ra.contextid = ctx.id AND ra.roleid = :roleid)
        INNER JOIN {user} u
                ON u.id = ra.userid
             WHERE me.id = :eventid
               AND (SELECT count(*) = 0
                      FROM {googlemeet_notify_done} nd
                     WHERE nd.eventid = me.id AND nd.userid = u.id)";

    return $DB->get_records_sql($sql, ['eventid' => $eventid, 'roleid' => $studentroleid]);
}

/**
 * Returns a list of future events
 */
function googlemeet_get_future_events() {
    global $DB;

    $now = time();

    $sql = "SELECT DISTINCT
                   me.id,
                   me.eventdate,
                   me.duration,
                   m.id AS googlemeetid,
                   m.name AS googlemeetname,
                   m.url,
                   cm.id AS cmid,
                   c.id AS courseid,
                   c.fullname AS coursename
              FROM {googlemeet_events} me
        INNER JOIN {googlemeet} m
                ON m.id = me.googlemeetid
        INNER JOIN {course_modules} cm
                ON (cm.instance = m.id AND cm.visible = 1 AND cm.deletioninprogress = 0)
        INNER JOIN {course} c
                ON (c.id = cm.course AND c.visible = 1)
        INNER JOIN {modules} md
                ON (md.id = cm.module AND md.name = 'googlemeet')
             WHERE :now BETWEEN me.eventdate - m.minutesbefore * 60 AND me.eventdate
               AND m.notify = 1";

    return $DB->get_records_sql($sql, ['now' => $now]);
}

/**
 * Send a notification to students in the class about the event.
 *
 * @param object $user
 * @param object $event
 * @return void
 */
function googlemeet_send_notification($user, $event) {
    global $CFG;

    $startdate = userdate($event->eventdate, get_string('strftimedmy', 'googlemeet'), $user->timezone);
    $starttime = userdate($event->eventdate, get_string('strftimehm', 'googlemeet'), $user->timezone);
    $endtime = userdate($event->eventdate + $event->duration, get_string('strftimehm', 'googlemeet'), $user->timezone);
    $usertimezone = usertimezone($user->timezone);
    $notificationstr = get_string('notification', 'googlemeet');
    $subject = "{$notificationstr}: {$event->googlemeetname} - {$startdate} {$starttime} - {$endtime} ($usertimezone)";
    $url = $CFG->wwwroot . '/mod/googlemeet/view.php?id=' . $event->cmid;

    $message = new \core\message\message();
    $message->component = 'mod_googlemeet';
    $message->name = 'notification';
    $message->userfrom = core_user::get_noreply_user();
    $message->userto = $user;
    $message->subject = $subject;
    $message->fullmessage = googlemeet_get_messagehtml($user, $event);
    $message->fullmessageformat = FORMAT_MARKDOWN;
    $message->fullmessagehtml = googlemeet_get_messagehtml($user, $event);
    $message->smallmessage = $subject;
    $message->notification = 1;
    $message->contexturl = $url;
    $message->contexturlname = $event->googlemeetname;
    $message->courseid = $event->courseid;

    message_send($message);
}

/**
 * Records the sending of the notification to not send repeated.
 *
 * @param int $userid
 * @param int $eventid
 */
function googlemeet_notify_done($userid, $eventid) {
    global $DB;

    $notifydone = new stdClass();
    $notifydone->userid = $userid;
    $notifydone->eventid = $eventid;
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

    $templatevars = [
        '/%userfirstname%/' => $user->firstname,
        '/%userlastname%/' => $user->lastname,
        '/%coursename%/' => $event->coursename,
        '/%googlemeetname%/' => $event->googlemeetname,
        '/%eventdate%/' => $startdate,
        '/%duration%/' => $starttime . ' – ' . $endtime,
        '/%timezone%/' => usertimezone($user->timezone),
        '/%url%/' => $url,
        '/%cmid%/' => $event->cmid,
    ];

    $patterns = array_keys($templatevars); // The placeholders which are to be replaced.

    $replacements = array_values($templatevars); // The values which are to be templated in for the placeholders.

    // Replace %variable% with relevant value everywhere it occurs.
    $emailcontent = preg_replace($patterns, $replacements, $config->emailcontent);

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
 * @param int $maxevents Maximum number of events to return (default 3)
 */
function googlemeet_get_upcoming_events($googlemeetid, $maxevents = 3) {
    global $DB, $USER;

    $now = time();

    // Get cancelled dates for this instance.
    $cancelleddates = googlemeet_get_cancelled($googlemeetid);

    // Ensure maxevents is within bounds.
    $maxevents = max(1, min(10, (int)$maxevents));

    // Get events that are upcoming or currently in progress (started less than duration ago).
    $sql = "SELECT id, eventdate, duration
              FROM {googlemeet_events}
             WHERE googlemeetid = :googlemeetid
               AND (eventdate + duration) > :now
          ORDER BY eventdate ASC
             LIMIT " . $maxevents;

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
                    $upcomingevent->timeinfo = get_string('event_starts_in', 'googlemeet', googlemeet_format_time_diff($timediff));
                } else {
                    // Event is scheduled for later.
                    $upcomingevent->status = 'scheduled';
                    $upcomingevent->islive = false;
                    $upcomingevent->issoon = false;
                    $upcomingevent->isscheduled = true;
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
