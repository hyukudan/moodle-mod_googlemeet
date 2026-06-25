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
 * The main mod_googlemeet configuration form.
 *
 * @package     mod_googlemeet
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_googlemeet\client;

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Module instance settings form.
 *
 * @package    mod_googlemeet
 * @copyright  2020 Rone Santos <ronefel@hotmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_googlemeet_mod_form extends moodleform_mod {
    /** @var array options to be used with date_time_selector fields in the quiz. */
    public static $datefieldoptions = array('optional' => true);

    /**
     * Defines forms elements
     */
    public function definition() {
        global $CFG, $OUTPUT;

        $config = get_config('googlemeet');
        $mform = $this->_form;
        $client = new client();

        $logout = optional_param('logout', 0, PARAM_BOOL);
        if ($logout) {
            $client->logout();
        }

        if (empty($this->current->instance)) {
            $clientislogged = optional_param('client_islogged', false, PARAM_BOOL);

            // Was logged in before submitting the form and the google session expired after submitting the form.
            if ($clientislogged && !$client->check_login()) {
                $mform->addElement('html', html_writer::div(get_string('sessionexpired', 'googlemeet') .
                    $client->print_login_popup(), 'mdl-align alert alert-danger googlemeet_loginbutton'
                ));

                // Whether the customer is enabled and if not logged in to the Google account.
            } else if ($client->enabled && !$client->check_login()) {
                $mform->addElement('html', html_writer::div(get_string('logintoyourgoogleaccount', 'googlemeet') .
                    $client->print_login_popup(), 'mdl-align alert alert-info googlemeet_loginbutton'
                ));
            }

            // If is logged in, shows Google account information.
            if ($client->check_login()) {
                $mform->addElement('html', $client->print_user_info('calendar'));
                $mform->addElement('hidden', 'client_islogged', true);
            }

        } else {
            $mform->addElement('hidden', 'client_islogged', false);
        }
        $mform->setType('client_islogged', PARAM_BOOL);

        // Adding the "general" fieldset, where all the common settings are shown.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Adding the standard "name" field.
        $mform->addElement('text', 'name', get_string('roomname', 'googlemeet'), array('size' => '50'));

        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }

        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();
        $element = $mform->getElement('introeditor');
        $attributes = $element->getAttributes();
        $attributes['rows'] = 5;
        $element->setAttributes($attributes);

        $hours = [];
        $minutes = [];
        for ($i = 0; $i <= 23; $i++) {
            $hours[$i] = sprintf("%02d", $i);
        }
        for ($i = 0; $i < 60; $i++) {
            $minutes[$i] = sprintf("%02d", $i);
        }

        $eventtime = [
            $mform->createElement('date_selector', 'eventdate', ''),
            $mform->createElement('html', '<div style="width: 100%;"></div>'),
            $mform->createElement('html', '<div class="items-center">' . get_string('from', 'googlemeet') . '</div>'),
            $mform->createElement('select', 'starthour', get_string('hour', 'form'), $hours, false, true),
            $mform->createElement('select', 'startminute', get_string('minute', 'form'), $minutes, false, true),
            $mform->createElement('html', '<div class="items-center">' . get_string('to', 'googlemeet') . '</div>'),
            $mform->createElement('select', 'endhour', get_string('hour', 'form'), $hours, false, true),
            $mform->createElement('select', 'endminute', get_string('minute', 'form'), $minutes, false, true),
            $mform->createElement('html',
                '<div id="id_googlemeet_eventtime_error" class="form-control-feedback invalid-feedback"></div>'
            ),
        ];
        $mform->addGroup($eventtime, 'eventtime', get_string('eventdate', 'googlemeet'), [''], false);

        // Maximum upcoming events to display.
        $maxevents = [];
        for ($i = 1; $i <= 10; $i++) {
            $maxevents[$i] = $i;
        }
        $mform->addElement('select', 'maxupcomingevents', get_string('maxupcomingevents', 'googlemeet'), $maxevents);
        $mform->setDefault('maxupcomingevents', 3);
        $mform->addHelpButton('maxupcomingevents', 'maxupcomingevents', 'googlemeet');

        // Recordings display settings header.
        $mform->addElement('header', 'headerrecordingssettings', get_string('recordingssettings', 'googlemeet'));

        // Maximum recordings per page.
        $maxrecordings = [];
        for ($i = 1; $i <= 20; $i++) {
            $maxrecordings[$i] = $i;
        }
        $mform->addElement('select', 'maxrecordings', get_string('maxrecordings', 'googlemeet'), $maxrecordings);
        $mform->setDefault('maxrecordings', 5);
        $mform->addHelpButton('maxrecordings', 'maxrecordings', 'googlemeet');

        // Recordings order.
        $orderoptions = [
            'DESC' => get_string('recordingsorder_desc', 'googlemeet'),
            'ASC' => get_string('recordingsorder_asc', 'googlemeet'),
        ];
        $mform->addElement('select', 'recordingsorder', get_string('recordingsorder', 'googlemeet'), $orderoptions);
        $mform->setDefault('recordingsorder', 'DESC');
        $mform->addHelpButton('recordingsorder', 'recordingsorder', 'googlemeet');

        // Recording filter - custom name pattern to filter recordings from Google Drive.
        $mform->addElement('text', 'recordingfilter', get_string('recordingfilter', 'googlemeet'),
            ['size' => '50', 'placeholder' => get_string('recordingfilter_placeholder', 'googlemeet')]);
        $mform->setType('recordingfilter', PARAM_TEXT);
        $mform->addHelpButton('recordingfilter', 'recordingfilter', 'googlemeet');

        // Auto-sync hours (0 = disabled).
        $mform->addElement('text', 'autosynchours', get_string('autosynchours', 'googlemeet'), ['size' => '4']);
        $mform->setType('autosynchours', PARAM_INT);
        $mform->setDefault('autosynchours', (int) ($config->autosynchours_default ?? 4));
        $mform->addHelpButton('autosynchours', 'autosynchours', 'googlemeet');

        // For multiple dates.
        $mform->addElement('header', 'headeraddmultipleeventdates', get_string('recurrenceeventdate', 'googlemeet'));
        if (!empty($config->multieventdateexpanded) || !empty($this->current->addmultiply)) {
            $mform->setExpanded('headeraddmultipleeventdates');
        }

        $mform->addElement('checkbox', 'addmultiply', '', get_string('repeatasfollows', 'googlemeet'));
        $mform->addHelpButton('addmultiply', 'recurrenceeventdate', 'googlemeet');

        $days = [
            $mform->createElement('checkbox', 'days[Mon]', '', get_string('monday', 'calendar')),
            $mform->createElement('checkbox', 'days[Tue]', '', get_string('tuesday', 'calendar')),
            $mform->createElement('checkbox', 'days[Wed]', '', get_string('wednesday', 'calendar')),
            $mform->createElement('checkbox', 'days[Thu]', '', get_string('thursday', 'calendar')),
            $mform->createElement('checkbox', 'days[Fri]', '', get_string('friday', 'calendar')),
            $mform->createElement('checkbox', 'days[Sat]', '', get_string('saturday', 'calendar')),
        ];

        if ($CFG->calendar_startwday === '0') { // Week start from sunday.
            array_unshift($days, $mform->createElement('checkbox', 'days[Sun]', '', get_string('sunday', 'calendar')));
        } else {
            array_push($days, $mform->createElement('checkbox', 'days[Sun]', '', get_string('sunday', 'calendar')));
        }

        array_push($days,
            $mform->createElement('html',
                '<div id="id_googlemeet_days_error" class="form-control-feedback invalid-feedback"></div>'
            )
        );

        $mform->addGroup($days, 'days', get_string('repeaton', 'googlemeet'), ['&nbsp;&nbsp;&nbsp;'], false);
        $mform->disabledIf('days', 'addmultiply', 'notchecked');

        $period = array(
            1 => 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
            21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36
        );
        $periodgroup = [
            $mform->createElement('select', 'period', '', $period, false, true),
            $mform->createElement('html', '<div class="items-center">' . get_string('week', 'googlemeet') . '</div>'),
            $mform->createElement('html',
                '<div id="id_googlemeet_periodgroup_error" class="form-control-feedback invalid-feedback"></div>'
            ),
        ];
        $mform->addGroup($periodgroup, 'periodgroup', get_string('repeatevery', 'googlemeet'), [''], false);
        $mform->disabledIf('periodgroup', 'addmultiply', 'notchecked');

        $eventenddategroup = [
            $mform->createElement('date_selector', 'eventenddate', ''),
            $mform->createElement('html',
                '<div id="id_googlemeet_eventenddategroup_error" class="form-control-feedback invalid-feedback"></div>'
            ),
        ];
        $mform->addGroup($eventenddategroup, 'eventenddategroup', get_string('repeatuntil', 'googlemeet'), [''], false);
        $mform->disabledIf('eventenddategroup', 'addmultiply', 'notchecked');

        // Holiday/exclusion periods section.
        $mform->addElement('header', 'headerholidayperiods', get_string('holidayperiods', 'googlemeet'));
        $mform->addHelpButton('headerholidayperiods', 'holidayperiods', 'googlemeet');

        // Define the elements for a single holiday period.
        $holidayelements = [];
        $holidayelements[] = $mform->createElement('text', 'holidayname', get_string('holidayname', 'googlemeet'),
            ['size' => '30', 'placeholder' => get_string('holidayname_placeholder', 'googlemeet')]);
        $holidayelements[] = $mform->createElement('date_selector', 'holidaystartdate',
            get_string('holidaystartdate', 'googlemeet'));
        $holidayelements[] = $mform->createElement('date_selector', 'holidayenddate',
            get_string('holidayenddate', 'googlemeet'));

        // Determine the number of existing holiday periods.
        $repeatno = 0;
        if (!empty($this->current->instance)) {
            global $DB;
            $repeatno = $DB->count_records('googlemeet_holidays', ['googlemeetid' => $this->current->instance]);
        }

        // Use repeat_elements for dynamic holiday periods.
        $this->repeat_elements(
            $holidayelements,
            $repeatno,
            [
                'holidayname' => ['type' => PARAM_TEXT],
            ],
            'holiday_repeats',
            'holiday_add_fields',
            1,
            get_string('addholidayperiod', 'googlemeet'),
            true,
            get_string('removeholidayperiod', 'googlemeet')
        );

        // Disable holiday fields if recurrence is not enabled.
        for ($i = 0; $i < max($repeatno, 1); $i++) {
            $mform->disabledIf("holidayname[$i]", 'addmultiply', 'notchecked');
            $mform->disabledIf("holidaystartdate[$i]", 'addmultiply', 'notchecked');
            $mform->disabledIf("holidayenddate[$i]", 'addmultiply', 'notchecked');
        }

        // Cancelled dates section.
        $mform->addElement('header', 'headercancelleddates', get_string('cancelleddates', 'googlemeet'));
        $mform->addHelpButton('headercancelleddates', 'cancelleddates', 'googlemeet');

        // Define the elements for a single cancelled date.
        $cancelledelements = [];
        $cancelledelements[] = $mform->createElement('date_selector', 'cancelleddate',
            get_string('cancelleddate', 'googlemeet'));
        $cancelledelements[] = $mform->createElement('text', 'cancelledreason', get_string('cancelledreason', 'googlemeet'),
            ['size' => '30', 'placeholder' => get_string('cancelledreason_placeholder', 'googlemeet')]);

        // Determine the number of existing cancelled dates.
        $cancelledrepeatno = 0;
        if (!empty($this->current->instance)) {
            global $DB;
            $cancelledrepeatno = $DB->count_records('googlemeet_cancelled', ['googlemeetid' => $this->current->instance]);
        }

        // Use repeat_elements for dynamic cancelled dates.
        $this->repeat_elements(
            $cancelledelements,
            $cancelledrepeatno,
            [
                'cancelledreason' => ['type' => PARAM_TEXT],
            ],
            'cancelled_repeats',
            'cancelled_add_fields',
            1,
            get_string('addcancelleddate', 'googlemeet'),
            true,
            get_string('removecancelleddate', 'googlemeet')
        );

        // Disable cancelled date fields if recurrence is not enabled.
        for ($i = 0; $i < max($cancelledrepeatno, 1); $i++) {
            $mform->disabledIf("cancelleddate[$i]", 'addmultiply', 'notchecked');
            $mform->disabledIf("cancelledreason[$i]", 'addmultiply', 'notchecked');
        }

        $mform->addElement('header', 'headerroomurl', get_string('roomurl', 'googlemeet'));
        if (!empty($config->roomurlexpanded)) {
            $mform->setExpanded('headerroomurl');
        }

        if (!empty($this->current->instance) && $client->enabled) {
            $mform->addElement('static', 'url_caution', '',
                $OUTPUT->notification(get_string('roomurl_caution', 'googlemeet'), 'warning')
            );
        }

        if ($client->check_login() && empty($this->current->instance)) {
            $mform->addElement('static', 'url_desc', '', $OUTPUT->notification(get_string('roomurl_desc', 'googlemeet'), 'info'));
            $mform->addElement('text', 'url', get_string('roomurl', 'googlemeet'), ['size' => '50', 'readonly' => true]);
            $mform->setType('url', PARAM_RAW);
            $mform->addHelpButton('url', 'url', 'googlemeet');

            $mform->addElement('text', 'creatoremail', get_string('creatoremail', 'googlemeet'),
                ['size' => '50', 'readonly' => true]
            );
            $mform->setType('creatoremail', PARAM_EMAIL);
            $mform->addHelpButton('creatoremail', 'creatoremail', 'googlemeet');
        } else {
            $mform->addElement('text', 'url', get_string('roomurl', 'googlemeet'), ['size' => '50']);
            $mform->setType('url', PARAM_URL);
            $mform->addHelpButton('url', 'url', 'googlemeet');

            $mform->addElement('text', 'creatoremail', get_string('creatoremail', 'googlemeet'), ['size' => '50']);
            $mform->setType('creatoremail', PARAM_EMAIL);
            $mform->addHelpButton('creatoremail', 'creatoremail', 'googlemeet');
        }

        $mform->addElement('header', 'headernotification', get_string('notification', 'googlemeet'));
        if (!empty($config->notificationexpanded)) {
            $mform->setExpanded('headernotification');
        }

        $mform->addElement('checkbox', 'notify', '', get_string('notify', 'googlemeet'));
        $mform->setDefault('notify', $config->notify);
        $mform->addHelpButton('notify', 'notify', 'googlemeet');

        $minutes = [];
        for ($i = 0; $i <= 120; $i = $i + 5) {
            $minutes[$i] = $i;
        }
        $minutesbefore = $mform->addElement('select',
            'minutesbefore', get_string('minutesbefore', 'googlemeet'), $minutes, false, true
        );
        $minutesbefore->setSelected($config->minutesbefore);
        $mform->addHelpButton('minutesbefore', 'minutesbefore', 'googlemeet');

        // Attachments for students to download.
        $mform->addElement('header', 'headerattachments', get_string('attachmentsheader', 'googlemeet'));
        $mform->addElement('filemanager', 'attachments', get_string('attachments', 'googlemeet'), null, [
            'subdirs' => 0,
            'maxbytes' => $CFG->maxbytes,
            'maxfiles' => -1,
            'accepted_types' => '*',
        ]);
        $mform->addHelpButton('attachments', 'attachments', 'googlemeet');

        // Add standard elements.
        $this->standard_coursemodule_elements();

        // Add standard buttons.
        $this->add_action_buttons();

    }

    /**
     * Decode json format from the database
     *
     * @param array $defaultvalues Form defaults
     * @return void
     */
    public function data_preprocessing(&$defaultvalues) {
        // Prepare the draft file area for the teacher attachments (works for new and existing instances).
        $draftitemid = file_get_submitted_draft_itemid('attachments');
        file_prepare_draft_area($draftitemid, $this->context->id, 'mod_googlemeet', 'attachment', 0,
            ['subdirs' => 0]);
        $defaultvalues['attachments'] = $draftitemid;

        if ($this->current->instance) {
            $defaultvalues['days'] = json_decode($defaultvalues['days'], true);

            // Load holiday periods from database.
            global $DB;
            $holidays = $DB->get_records('googlemeet_holidays',
                ['googlemeetid' => $this->current->instance], 'id ASC');

            $i = 0;
            foreach ($holidays as $holiday) {
                $defaultvalues["holidayname[$i]"] = $holiday->name;
                $defaultvalues["holidaystartdate[$i]"] = $holiday->startdate;
                $defaultvalues["holidayenddate[$i]"] = $holiday->enddate;
                $i++;
            }

            // Load cancelled dates from database.
            $cancelleddates = $DB->get_records('googlemeet_cancelled',
                ['googlemeetid' => $this->current->instance], 'cancelleddate ASC');

            $i = 0;
            foreach ($cancelleddates as $cancelled) {
                $defaultvalues["cancelleddate[$i]"] = $cancelled->cancelleddate;
                $defaultvalues["cancelledreason[$i]"] = $cancelled->reason;
                $i++;
            }
        }
    }

    /**
     * Enforce validation rules here
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @return array
     **/
    public function validation($data, $files) {
        global $COURSE;

        $errors = parent::validation($data, $files);

        $starttime = $data['starthour'] * HOURSECS + $data['startminute'] * MINSECS;
        $endtime = $data['endhour'] * HOURSECS + $data['endminute'] * MINSECS;

        if ($endtime < $starttime) {
            $errors['eventtime'] = get_string('invalideventendtime', 'googlemeet');
        }

        if (!empty($data['addmultiply']) &&
            $data['eventdate'] !== 0 &&
            $data['eventenddate'] !== 0 &&
            $data['eventenddate'] < $data['eventdate']
        ) {
            $errors['eventenddategroup'] = get_string('invalideventenddate', 'googlemeet');
        }

        $addmulti = isset($data['addmultiply']) ? (int)$data['addmultiply'] : 0;
        $days = isset($data['days']);

        if ($addmulti && !$days) {
            $errors['days'] = get_string('checkweekdays', 'googlemeet');
        } else if ($addmulti && !$this->has_events_with_period($data)) {
            // No events would be generated with the chosen days, period and date range.
            $errors['days'] = get_string('noeventswithperiod', 'googlemeet');
        }

        if ($addmulti && ceil(($data['eventenddate'] - $data['eventdate']) / YEARSECS) > 1) {
            $errors['eventenddate'] = get_string('timeahead', 'googlemeet');
        }

        // Validate holiday periods.
        if ($addmulti && !empty($data['holiday_repeats'])) {
            for ($i = 0; $i < $data['holiday_repeats']; $i++) {
                $startkey = "holidaystartdate[$i]";
                $endkey = "holidayenddate[$i]";

                if (isset($data[$startkey]) && isset($data[$endkey])) {
                    if ($data[$endkey] < $data[$startkey]) {
                        $errors[$endkey] = get_string('invalidholidayenddate', 'googlemeet');
                    }
                }
            }
        }

        $startdate = $data['eventdate'] + $starttime;
        if ($startdate < $COURSE->startdate) {
            $errors['eventtime'] = get_string('earlierto', 'googlemeet',
                userdate($COURSE->startdate, get_string('strftimedmyhm', 'googlemeet'))
            );
        }

        $client = new client();
        $clientislogged = optional_param('client_islogged', false, PARAM_BOOL);

        if (empty($this->current->instance)) {
            // Validates the url field only if not logged into Google account.
            if (!$client->check_login() && !$clientislogged) {
                $errors = $this->validate_url($data['url'], $errors);
                if (!validate_email($data['creatoremail'])) {
                    $errors['creatoremail'] = get_string('creatoremail_error', 'googlemeet');
                }
            }

            // Forces an error if the Google session expired after submitting the form.
            if (!$client->check_login() && $clientislogged) {
                $errors['client_islogged'] = '';
            }
        } else {
            // Validates url field if updating instance.
            $errors = $this->validate_url($data['url'], $errors);
            if (!validate_email($data['creatoremail'])) {
                $errors['creatoremail'] = get_string('creatoremail_error', 'googlemeet');
            }
        }

        return $errors;
    }

    /**
     * Simulate the same event-generation loop used by
     * googlemeet_construct_events_data_for_add() and return true if at least
     * one event would be produced with the submitted configuration (respecting
     * period, selected weekdays, and holiday exclusions built from the form data).
     *
     * This replaces the old checkweekdays() helper which iterated every day in
     * the range without honouring the "repeat every N weeks" period, causing
     * false positives when period > (enddate - startdate) / WEEKSECS.
     *
     * @param array $data Validated form data.
     * @return bool True if at least one event would be created.
     */
    private function has_events_with_period(array $data): bool {
        global $CFG;

        if (empty($data['days'])) {
            return false;
        }

        $starthour   = (int)($data['starthour']   ?? 0);
        $startminute = (int)($data['startminute'] ?? 0);
        $endhour     = (int)($data['endhour']     ?? 0);
        $endminute   = (int)($data['endminute']   ?? 0);
        $period      = (int)($data['period']      ?? 1);
        if ($period < 1) {
            $period = 1;
        }

        $eventstarttime = $starthour * HOURSECS + $startminute * MINSECS;
        $eventendtime   = $endhour   * HOURSECS + $endminute   * MINSECS;
        $eventdate      = $data['eventdate'] + $eventstarttime;
        $enddate        = $data['eventenddate'] + $eventendtime;

        // Build a lightweight in-memory holiday list from the submitted form data
        // (mirrors what googlemeet_get_holidays() would return from the DB).
        $holidays = [];
        if (!empty($data['holiday_repeats'])) {
            for ($i = 0; $i < $data['holiday_repeats']; $i++) {
                $hstart = $data["holidaystartdate[$i]"] ?? 0;
                $hend   = $data["holidayenddate[$i]"]   ?? 0;
                if ($hstart && $hend && $hend >= $hstart) {
                    $h            = new stdClass();
                    $h->startdate = (int)$hstart;
                    $h->enddate   = (int)$hend;
                    $holidays[]   = $h;
                }
            }
        }

        // --- Check the first event (eventdate itself) ---
        if (!googlemeet_is_holiday($eventdate, $holidays)) {
            return true;
        }

        // --- Mirror the recurrence loop from googlemeet_construct_events_data_for_add() ---
        $wdaydesc = [0 => 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        $sdate   = $eventdate + DAYSECS;
        $dayinfo = usergetdate($sdate);
        if ($CFG->calendar_startwday === '0') {
            $startweek = $sdate - $dayinfo['wday'] * DAYSECS;
        } else {
            $wday      = $dayinfo['wday'] === 0 ? 7 : $dayinfo['wday'];
            $startweek = $sdate - ($wday - 1) * DAYSECS;
        }

        // Convert the submitted days array to an object for property_exists() checks
        // (same access pattern used in googlemeet_construct_events_data_for_add).
        $daysobj = (object)$data['days'];

        while ($sdate < $enddate) {
            if ($sdate < $startweek + WEEKSECS) {
                $dayinfo = usergetdate($sdate);
                if (property_exists($daysobj, $wdaydesc[$dayinfo['wday']])) {
                    $eventtime = make_timestamp(
                        $dayinfo['year'],
                        $dayinfo['mon'],
                        $dayinfo['mday'],
                        $starthour,
                        $startminute
                    );
                    if (!googlemeet_is_holiday($eventtime, $holidays)) {
                        return true;
                    }
                }
                $sdate += DAYSECS;
            } else {
                $startweek += WEEKSECS * $period;
                $sdate = $startweek;
            }
        }

        return false;
    }

    /**
     * Validate the provided url
     * @param string $url Url to validate.
     * @param array $errors Form errors.
     *
     * @return array Form errors.
     */
    private function validate_url(string $url, array $errors) {
        if (googlemeet_clear_url($url) == null) {
            $errors['generateurlgroup'] = get_string('url_failed', 'googlemeet');
            $errors['url'] = get_string('url_failed', 'googlemeet');
        }
        return $errors;
    }
}
