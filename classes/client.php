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

use DateTime;
use html_writer;
use moodle_url;
use dml_missing_record_exception;
use moodle_exception;
use stdClass;

/**
 * Oauth Client for mod_googlemeet.
 *
 * @package     mod_googlemeet
 * @copyright   2023 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {

    /**
     * OAuth 2 client
     * @var \core\oauth2\client
     */
    private $client = null;

    /**
     * OAuth 2 Issuer
     * @var \core\oauth2\issuer
     */
    private $issuer = null;

    /** @var bool informs if the client is enabled */
    public $enabled = true;

    /**
     * Additional scopes required for drive.
     */
    const SCOPES = 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/calendar.events';

    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct() {
        try {
            $this->issuer = \core\oauth2\api::get_issuer(get_config('googlemeet', 'issuerid'));
        } catch (dml_missing_record_exception $e) {
            $this->enabled = false;
        }

        if ($this->issuer && (!$this->issuer->get('enabled') || $this->issuer->get('id') == 0)) {
            $this->enabled = false;
        }

        // Only build the OAuth client (and validate the issuer host) when the plugin is
        // actually configured. Calling get_user_oauth_client() with a null issuer — e.g. in a
        // cron context where issuerid is unset — would otherwise error.
        if ($this->enabled) {
            $client = $this->get_user_oauth_client();
            if ($client->get_login_url()->get_host() !== 'accounts.google.com') {
                throw new moodle_exception('invalidissuerid', 'googlemeet');
            }
        }
    }

    /**
     * Get a cached user authenticated oauth client.
     *
     * @return \core\oauth2\client
     */
    protected function get_user_oauth_client() {
        if ($this->client) {
            return $this->client;
        }

        $returnurl = new moodle_url('/mod/googlemeet/callback.php');
        $returnurl->param('callback', 'yes');
        $returnurl->param('sesskey', sesskey());

        $this->client = \core\oauth2\api::get_user_oauth_client($this->issuer, $returnurl, self::SCOPES, true);

        return $this->client;
    }

    /**
     * Escape a value for safe interpolation inside a Google Drive query string literal.
     *
     * Drive query terms such as `name contains "..."` or `name = "..."` use double-quoted
     * string literals. Values controlled by the teacher (activity name, recording filter,
     * meeting code, recording filename) must have any backslash and double-quote escaped,
     * otherwise a `"` would break out of the literal and let the value alter the query
     * (query injection). See https://developers.google.com/drive/api/guides/ref-search-terms.
     *
     * @param string $value The raw value to embed inside a double-quoted Drive query literal.
     * @return string The escaped value (without surrounding quotes).
     */
    private function drive_quote($value) {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
    }

    /**
     * Print the login in a popup.
     *
     * @param array|null $attr Custom attributes to be applied to popup div.
     *
     * @return string HTML code
     */
    public function print_login_popup($attr = null) {
        global $OUTPUT, $PAGE;

        $client = $this->get_user_oauth_client();
        $loginurl = $client->get_login_url();

        // SECURITY: open the OAuth login in a popup without an inline onClick handler.
        // The URL is carried in a data-* attribute (HTML-escaped by html_writer) and the
        // popup is opened from a small AMD inline listener, so no user-influenced value is
        // interpolated into an executable inline-JS attribute. We must keep using a popup
        // window (window.open) because the OAuth callback relies on it (callback.php closes
        // the popup and reloads the parent).
        $buttonid = html_writer::random_id('googlemeet_login_');
        $button = html_writer::tag('button', get_string('logintoaccount', 'googlemeet'), [
            'type' => 'button',
            'class' => 'btn btn-primary',
            'id' => $buttonid,
            'data-loginurl' => $loginurl->out(false),
        ]);

        $PAGE->requires->js_amd_inline("
            require(['jquery'], function(\$) {
                \$('#" . $buttonid . "').on('click', function(e) {
                    e.preventDefault();
                    var url = \$(this).attr('data-loginurl');
                    window.open(url, 'Login',
                        'height=600,width=599,top=0,left=0,menubar=0,location=0,directories=0,fullscreen=0');
                });
            });
        ");

        return html_writer::div($button, 'mt-2');
    }

    /**
     * Print user info.
     *
     * @param string|null $scope 'calendar' or 'drive' Defines which link will be used.
     *
     * @return string HTML code
     */
    public function print_user_info($scope = null) {
        global $OUTPUT, $PAGE;

        if (!$this->check_login()) {
            return '';
        }

        $userauth = $this->get_user_oauth_client();
        $userinfo = $userauth->get_userinfo();

        $username = $userinfo['username'];
        $name = $userinfo['firstname'].' '.$userinfo['lastname'];
        $userpicture = base64_encode($userinfo['picture']);

        $userurl = '#';
        if ($scope == 'calendar') {
            $userurl = new moodle_url('https://calendar.google.com/');
        }
        if ($scope == 'drive') {
            $userurl = new moodle_url('https://drive.google.com/');
        }

        $logouturl = new moodle_url($PAGE->url);
        $logouturl->param('logout', true);
        $logouturl->param('sesskey', sesskey());

        $img = html_writer::img('data:image/jpeg;base64,'.$userpicture, '');
        $out = html_writer::start_div('', ['id' => 'googlemeet_auth-info']);
        $out .= html_writer::link($userurl, $img,
            ['id' => 'googlemeet_picture-user', 'target' => '_blank', 'title' => get_string('manage', 'googlemeet')]
        );
        $out .= html_writer::start_div('', ['id' => 'googlemeet_user-name']);
        $out .= html_writer::span(get_string('loggedinaccount', 'googlemeet'), '');
        $out .= html_writer::span($name);
        $out .= html_writer::span($username);
        $out .= html_writer::end_div();
        $out .= html_writer::link($logouturl,
            $OUTPUT->pix_icon('logout', '', 'googlemeet', ['class' => 'm-0']),
            ['class' => 'btn btn-secondary btn-sm', 'title' => get_string('logout', 'googlemeet')]
        );

        $out .= html_writer::end_div();

        return $out;
    }

    /**
     * Checks whether the user is authenticate or not.
     *
     * @return bool true when logged in.
     */
    public function check_login() {
        $client = $this->get_user_oauth_client();
        return $client->is_logged_in();
    }

    /**
     * Logout.
     *
     * @return void
     */
    public function logout() {
        global $PAGE;

        if ($this->check_login()) {
            $url = new moodle_url($PAGE->url);
            $client = $this->get_user_oauth_client();
            $client->log_out();

            // SECURITY: redirect the browser back to the activity after logout. The URL is a
            // moodle_url built from $PAGE->url, and out(false) returns it without HTML entity
            // encoding so it is safe to place inside a JS string literal (json_encode further
            // guards against quote/script breakout). We must emit this tiny HTML page and stop
            // execution here because logout is also reachable from inside the OAuth popup flow,
            // where a normal redirect() would not reliably reload the originating page.
            $jsurl = json_encode($url->out(false));
            $html = '<!DOCTYPE html><html><head><script type="text/javascript">' .
                    'window.location = ' . $jsurl . ';' .
                    '</script></head><body></body></html>';
            die($html);
        }
    }

    /**
     * Store the access token.
     *
     * @return void
     */
    public function callback() {
        $client = $this->get_user_oauth_client();
        // This will upgrade to an access token if we have an authorization code and save the access token in the session.
        $client->is_logged_in();
    }

    /**
     * Create a meeting event in Google Calendar
     *
     * @param object $googlemeet An object from the form.
     *
     * @return object Google Calendar event
     */
    public function create_meeting_event($googlemeet) {
        global $USER;

        $calendarid = 'primary';
        $starthour = str_pad($googlemeet->starthour , 2 , '0' , STR_PAD_LEFT);
        $startminute = str_pad($googlemeet->startminute , 2 , '0' , STR_PAD_LEFT);
        $endhour = str_pad($googlemeet->endhour , 2 , '0' , STR_PAD_LEFT);
        $endminute = str_pad($googlemeet->endminute , 2 , '0' , STR_PAD_LEFT);

        $starttime = $starthour . ':' . $startminute . ':00';
        $endtime = $endhour . ':' . $endminute . ':00';

        $startdatetime = date('Y-m-d', $googlemeet->eventdate) . 'T' . $starttime;
        $enddatetime = date('Y-m-d', $googlemeet->eventdate) . 'T' . $endtime;

        $timezone = get_user_timezone($USER->timezone);

        $daysofweek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        $recurrence = '';

        if (isset($googlemeet->addmultiply)) {
            $interval = 'INTERVAL=' . $googlemeet->period;
            $until = 'UNTIL=' . date('Ymd', $googlemeet->eventenddate) . 'T235959Z';
            $byday = 'BYDAY=';

            $daysofweek = new stdClass;
            $daysofweek->Sun = 'SU';
            $daysofweek->Mon = 'MO';
            $daysofweek->Tue = 'TU';
            $daysofweek->Wed = 'WE';
            $daysofweek->Thu = 'TH';
            $daysofweek->Fri = 'FR';
            $daysofweek->Sat = 'SA';

            foreach ((array) $googlemeet->days as $day => $val) {
                $byday .= $daysofweek->$day . ',';
            }

            $recurrence = ['RRULE:FREQ=WEEKLY;' . $interval . ';' . $until . ';' . $byday];
        }

        $eventrawpost = [
            'summary' => $googlemeet->name .' ('. rand(1000, 9999) .')',
            'start' => [
                'dateTime' => $startdatetime,
                'timeZone' => $timezone
            ],
            'end' => [
                'dateTime' => $enddatetime,
                'timeZone' => $timezone
            ],
            'recurrence' => $recurrence
        ];

        $service = new rest($this->get_user_oauth_client());

        $eventparams = [
            'calendarid' => $calendarid
        ];

        $eventresponse = helper::request($service, 'insertevent', $eventparams, json_encode($eventrawpost));

        $conferenceparams = [
            'calendarid' => $calendarid,
            'eventid' => $eventresponse->id,
            'conferenceDataVersion' => 1
        ];

        $conferencerawpost = [
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => $eventresponse->id
                ]
            ]
        ];

        $conferenceresponse = helper::request($service, 'createconference', $conferenceparams, json_encode($conferencerawpost));

        return $conferenceresponse;

    }

    /**
     * Get recordings from Google Drive and sync with database.
     *
     * @param object $googlemeet An object instance.
     * @param bool $noredirect When true, skip the final redirect() call and return stats.
     *                         Required for background/cron contexts (no $PAGE, no HTTP).
     *
     * @return array|void Stats array ['inserted','updated','deleted','found'] when $noredirect=true.
     */
    public function syncrecordings($googlemeet, $noredirect = false) {
        global $PAGE, $DB, $CFG;
        require_once($CFG->dirroot . '/mod/googlemeet/lib.php');

        if ($this->check_login()) {
            $service = new rest($this->get_user_oauth_client());

            // Search for Meet Recordings folder in multiple languages.
            // Google localises the auto-created folder name to the account language.
            $folderparams = [
                'q' => '(name = "Meet Recordings" or name contains "Registros de reuniones") and
                        trashed = false and
                        mimeType = "application/vnd.google-apps.folder" and
                        "me" in owners',
                'pageSize' => 1000,
                'fields' => 'nextPageToken, files(id,owners)'
            ];

            $folderresponse = helper::request($service, 'list', $folderparams, false);

            $folders = $folderresponse->files;
            $parents = '';
            $folderscount = count($folders);
            for ($i = 0; $i < $folderscount; $i++) {
                $parents .= 'parents="'.$this->drive_quote($folders[$i]->id).'"';
                if ($i + 1 < $folderscount) {
                    $parents .= ' or ';
                }
            }

            $meetingcode = substr($googlemeet->url, 24, 12);
            $name = $googlemeet->name;
            $customfilter = trim($googlemeet->recordingfilter ?? '');

            // Build name filter: always include meetingcode + name as fallbacks,
            // plus custom filter if set. This avoids the problem where a custom
            // filter is an incorrect substring that doesn't match the actual filename.
            $conditions = [];
            $conditions[] = 'name contains "' . $this->drive_quote($meetingcode) . '"';
            $conditions[] = 'name contains "' . $this->drive_quote($name) . '"';
            if (!empty($customfilter) && $customfilter !== $name) {
                $conditions[] = 'name contains "' . $this->drive_quote($customfilter) . '"';
            }
            $namefilter = '(' . implode(' or ', $conditions) . ')';

            // If no folders found, try searching ALL of Drive (no parent filter).
            if (empty($parents)) {
                $recordingparams = [
                    'q' => 'trashed = false and
                            mimeType = "video/mp4" and
                            "me" in owners and
                            ' . $namefilter,
                    'pageSize' => 100,
                    'fields' => 'files(id,name,permissionIds,createdTime,videoMediaMetadata,webViewLink)'
                ];
            } else {
                $recordingparams = [
                    'q' => '(' . $parents . ') and
                            trashed = false and
                            mimeType = "video/mp4" and
                            "me" in owners and
                            ' . $namefilter,
                    'pageSize' => 1000,
                    'fields' => 'files(id,name,permissionIds,createdTime,videoMediaMetadata,webViewLink)'
                ];
            }

            $recordingresponse = helper::request($service, 'list', $recordingparams, false);

            $recordings = $recordingresponse->files;

            // Additional filtering for duplicate check across activities.
            $recordings = $this->filter_recordings_for_activity($recordings, $meetingcode, $name, $googlemeet->id, $customfilter);

            // Remove sync param to avoid redirect loop (skipped when running in cron).
            $url = null;
            if (!$noredirect) {
                $url = new moodle_url($PAGE->url);
                $url->remove_params(['sync']);
            }
            $stats = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];

            $recordingscount = $recordings ? count($recordings) : 0;
            if ($recordingscount > 0) {
                // Pre-fetch the recording ids we already have so we can skip transcript fetching
                // and yt-dlp extraction for them. sync_recordings() will ignore them anyway.
                $existingids = $DB->get_fieldset_select('googlemeet_recordings', 'recordingid',
                    'googlemeetid = ?', [$googlemeet->id]);
                $existingids = array_flip($existingids);
                // Map recordingid => notestext so we know which existing recordings
                // still lack notes (Gemini notes are often generated later).
                $existingnotes = $DB->get_records_menu('googlemeet_recordings',
                    ['googlemeetid' => $googlemeet->id], '', 'recordingid, notestext');

                for ($i = 0; $i < $recordingscount; $i++) {
                    $recording = $recordings[$i];

                    // If the recording has already been processed.
                    if (isset($recording->videoMediaMetadata)) {
                        // SECURITY: granting "anyone with the link" is what makes the recording
                        // playable for non-owner students through the embedded player. The
                        // 'makerecordingspublic' setting defaults to 1 to preserve that behaviour;
                        // an admin can disable it to keep recordings private, but that breaks
                        // playback for everyone except the Drive owner (a deliberate privacy/
                        // playability trade-off).
                        if (get_config('googlemeet', 'makerecordingspublic')
                                && !in_array('anyoneWithLink', $recording->permissionIds)) {
                            $permissionparams = [
                                'fileid' => $recording->id,
                                'fields' => 'id'
                            ];
                            $permissionrawpost = [
                                "role" => "reader",
                                "type" => "anyone"
                            ];
                            helper::request($service, 'create_permission', $permissionparams, json_encode($permissionrawpost));
                        }

                        // Format it into a human-readable time.
                        $duration = $this->formatseconds((int)$recording->videoMediaMetadata->durationMillis);

                        $createdtime = new DateTime($recording->createdTime);

                        $recordings[$i]->recordingId = $recording->id;
                        $recordings[$i]->duration = $duration;
                        $recordings[$i]->createdTime = $createdtime->getTimestamp();

                        if (!isset($existingids[$recording->id])) {
                            // Only fetch the transcript and run yt-dlp for recordings we are
                            // about to insert. Existing rows are left untouched by sync_recordings().
                            $transcriptdata = $this->find_transcript_for_recording($service, $parents, $recording->name);
                            if ($transcriptdata) {
                                $recordings[$i]->transcriptfileid = $transcriptdata['fileid'];
                                $recordings[$i]->transcripttext = $transcriptdata['content'];
                            }

                            if (empty($recordings[$i]->transcripttext) && !empty($recording->webViewLink)) {
                                $extractor = new subtitle_extractor(get_config('googlemeet', 'subtitlelanguage') ?: 'es');
                                if ($extractor->is_available()) {
                                    $cctext = $extractor->extract($recording->webViewLink);
                                    if (!empty($cctext)) {
                                        $recordings[$i]->transcripttext = $cctext;
                                    }
                                }
                            }
                        }

                        // Notes are fetched for new recordings AND for existing ones
                        // that still have no notes, because Gemini notes are often
                        // published after the recording first appears.
                        $existingnotestext = $existingnotes[$recording->id] ?? null;
                        if (!isset($existingids[$recording->id]) || empty($existingnotestext)) {
                            $notesdata = $this->find_notes_for_recording($service, $parents, $recording->name);
                            if ($notesdata) {
                                $recordings[$i]->notestext = $notesdata['content'];
                                $recordings[$i]->notesdocid = $notesdata['docid'];
                            }
                        }

                        unset($recordings[$i]->id);
                        unset($recordings[$i]->permissionIds);
                        unset($recordings[$i]->videoMediaMetadata);
                    } else {
                        $recordings[$i]->unprocessed = true;
                    }
                }

                $result = sync_recordings($googlemeet->id, $recordings);
                $stats = $result['stats'];
            } else {
                // No recordings found, but still update lastsync time.
                $DB->set_field('googlemeet', 'lastsync', time(), ['id' => $googlemeet->id]);
            }

            if ($noredirect) {
                $stats['found'] = $recordingscount;
                return $stats;
            }

            // Build feedback message.
            $message = $this->build_sync_message($stats, $recordingscount);
            $messagetype = ($stats['inserted'] > 0) ? \core\output\notification::NOTIFY_SUCCESS
                                                    : \core\output\notification::NOTIFY_INFO;

            redirect($url, $message, null, $messagetype);
        }
    }

    /**
     * Build a user-friendly sync feedback message.
     *
     * @param array $stats Array with inserted, updated, deleted counts.
     * @param int $totalfound Total recordings found in Drive.
     * @return string The feedback message.
     */
    private function build_sync_message(array $stats, int $totalfound): string {
        $parts = [];

        if ($stats['inserted'] > 0) {
            $parts[] = get_string('sync_new_recordings', 'googlemeet', $stats['inserted']);
        }
        if ($stats['updated'] > 0) {
            $parts[] = get_string('sync_updated_recordings', 'googlemeet', $stats['updated']);
        }
        if ($stats['deleted'] > 0) {
            $parts[] = get_string('sync_deleted_recordings', 'googlemeet', $stats['deleted']);
        }

        if (empty($parts)) {
            if ($totalfound > 0) {
                return get_string('sync_no_changes', 'googlemeet', $totalfound);
            } else {
                return get_string('sync_no_recordings_found', 'googlemeet');
            }
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * Filter recordings to match only those belonging to this specific activity.
     *
     * The Drive API "contains" filter is broad and may return recordings from
     * other activities with similar names. This method applies stricter filtering:
     * 1. If custom filter is set, use it (case-insensitive contains)
     * 2. Recording name must start with the activity name (case-insensitive)
     * 3. Or recording name must contain the exact meeting code
     * 4. Recording must not already exist in another activity (avoid duplicates)
     *
     * @param array $recordings Array of recording objects from Drive API
     * @param string $meetingcode The meeting code (e.g., "abc-defg-hij")
     * @param string $activityname The activity name in Moodle
     * @param int $googlemeetid The current activity ID
     * @param string $customfilter Custom filter pattern set by user
     * @return array Filtered array of recordings
     */
    private function filter_recordings_for_activity($recordings, $meetingcode, $activityname, $googlemeetid, $customfilter = '') {
        global $DB;

        if (empty($recordings)) {
            return [];
        }

        // Batch query: get all existing recordings by their IDs to avoid N+1 queries.
        $recordingids = array_filter(array_map(function($r) {
            return $r->id ?? null;
        }, $recordings));

        $existingrecordings = [];
        if (!empty($recordingids)) {
            list($insql, $params) = $DB->get_in_or_equal($recordingids, SQL_PARAMS_NAMED);
            $records = $DB->get_records_select('googlemeet_recordings', "recordingid $insql", $params, '', 'recordingid, googlemeetid');
            foreach ($records as $rec) {
                $existingrecordings[$rec->recordingid] = $rec->googlemeetid;
            }
        }

        $filtered = [];
        $activitynamelower = \core_text::strtolower(trim($activityname));
        $customfilterlower = \core_text::strtolower(trim($customfilter));

        foreach ($recordings as $recording) {
            $recordingname = $recording->name ?? '';
            $recordingnamelower = \core_text::strtolower($recordingname);
            $recordingid = $recording->id ?? null;

            // Check if this recording already exists using our batch-loaded map.
            $existinggooglemeetid = $existingrecordings[$recordingid] ?? null;

            if ($existinggooglemeetid !== null && $existinggooglemeetid != $googlemeetid) {
                // Skip this recording - it's already associated with another activity.
                continue;
            }

            // If recording already exists in this activity, include it (for updates).
            if ($existinggooglemeetid !== null && $existinggooglemeetid == $googlemeetid) {
                $filtered[] = $recording;
                continue;
            }

            // For new recordings, apply name-based filtering:

            // Priority 1: If custom filter is set, check it first (case-insensitive contains).
            if (!empty($customfilterlower)) {
                if (strpos($recordingnamelower, $customfilterlower) !== false) {
                    $filtered[] = $recording;
                    continue;
                }
                // Custom filter didn't match — fall through to meetingcode/name checks
                // instead of skipping. The custom filter is a bonus, not exclusive.
            }

            // Check 2: Recording name contains the exact meeting code.
            if (!empty($meetingcode) && strpos($recordingnamelower, \core_text::strtolower($meetingcode)) !== false) {
                $filtered[] = $recording;
                continue;
            }

            // Check 3: Recording name starts with the activity name.
            // This handles filenames like "Activity Name (2024-01-15 10:00).mp4".
            if (!empty($activitynamelower) && strpos($recordingnamelower, $activitynamelower) === 0) {
                $filtered[] = $recording;
                continue;
            }

            // Check 4: Recording name contains the full activity name followed by space or parenthesis.
            // This handles cases where the name might have a prefix or different format.
            $pattern = preg_quote($activitynamelower, '/');
            if (preg_match('/\b' . $pattern . '\s*[\(\-]/i', $recordingnamelower)) {
                $filtered[] = $recording;
                continue;
            }
        }

        return $filtered;
    }

    /**
     * Find and fetch transcript for a recording.
     *
     * @param rest $service The REST service
     * @param string $parents The parent folders query
     * @param string $videoname The video filename
     * @return array|null Array with 'fileid' and 'content' or null if not found
     */
    private function find_transcript_for_recording($service, $parents, $videoname) {
        // Get the base name without extension.
        $basename = pathinfo($videoname, PATHINFO_FILENAME);

        // Search for transcript files (sbv, vtt, txt) with similar name. When no Meet Recordings
        // folder was found ($parents empty), search all of Drive instead of emitting an invalid
        // "() and ..." parent clause (which silently returns nothing), mirroring the all-Drive
        // fallback used for the recording search.
        $parentclause = !empty($parents) ? '(' . $parents . ') and ' : '';
        $transcriptparams = [
            'q' => $parentclause . 'trashed = false and
                    "me" in owners and
                    (mimeType = "text/plain" or mimeType = "text/vtt" or mimeType = "application/x-subrip") and
                    name contains "'.$this->drive_quote($basename).'"',
            'pageSize' => 10,
            'fields' => 'files(id,name,mimeType)'
        ];

        try {
            $response = helper::request($service, 'list', $transcriptparams, false);

            if (!empty($response->files)) {
                // Prefer .sbv or .vtt files, then .txt.
                $transcriptfile = null;
                foreach ($response->files as $file) {
                    $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
                    if ($ext === 'sbv' || $ext === 'vtt') {
                        $transcriptfile = $file;
                        break;
                    }
                    if ($ext === 'txt' && !$transcriptfile) {
                        $transcriptfile = $file;
                    }
                }

                if ($transcriptfile) {
                    // Download the transcript content.
                    $content = $this->download_file_content($service, $transcriptfile->id);
                    if ($content) {
                        // Parse the transcript to extract just the text.
                        $parsedcontent = $this->parse_transcript($content, pathinfo($transcriptfile->name, PATHINFO_EXTENSION));
                        return [
                            'fileid' => $transcriptfile->id,
                            'content' => $parsedcontent,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Transcript not found or error, continue without it.
            debugging("Failed to fetch transcript: " . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return null;
    }

    /**
     * Find and fetch the Gemini meeting notes (a Google Doc) for a recording.
     *
     * Notes are optional and often generated AFTER the recording appears, so this
     * is called both for new recordings and for existing ones still missing notes.
     *
     * @param rest $service The REST service
     * @param string $parents The parent folders query (may be empty)
     * @param string $videoname The video filename
     * @return array|null ['docid' => string, 'content' => string] or null
     */
    private function find_notes_for_recording($service, $parents, $videoname) {
        $prefix = preg_replace('/\s*[-–—]\s*Recording\s*$/iu', '', $videoname);
        $prefix = trim($prefix);
        if ($prefix === '' || $prefix === trim($videoname)) {
            $prefix = preg_replace('/\.[A-Za-z0-9]{2,4}$/', '', trim($videoname));
        }
        if ($prefix === '') {
            return null;
        }
        $notesparams = [
            'q' => 'trashed = false and "me" in owners and mimeType = "application/vnd.google-apps.document"',
            'orderBy' => 'createdTime desc',
            'pageSize' => 200,
            'fields' => 'files(id,name)'
        ];

        try {
            $response = helper::request($service, 'list', $notesparams, false);
            if (empty($response->files)) {
                return null;
            }
            $candidate = null;
            foreach ($response->files as $file) {
                if (stripos($file->name, $prefix) === false) {
                    continue;
                }
                if (preg_match('/Gemini|Notas|Notes/iu', $file->name)) {
                    $candidate = $file;
                    break;
                }
                if ($candidate === null) {
                    $candidate = $file;
                }
            }
            if (!$candidate) {
                return null;
            }
            $html = $this->export_doc_html($service, $candidate->id);
            if (empty($html)) {
                return null;
            }
            $clean = $this->sanitize_notes_html($html);
            if (trim($clean) === '') {
                return null;
            }
            return [
                'docid' => $candidate->id,
                'content' => $clean,
            ];
        } catch (\Exception $e) {
            debugging('Failed to fetch notes: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Export a Google Doc to HTML.
     *
     * @param rest $service The REST service
     * @param string $docid The Google Doc file ID
     * @return string|null The HTML body or null on failure
     */
    private function export_doc_html($service, $docid) {
        try {
            $params = ['fileid' => $docid, 'mimeType' => 'text/html'];
            return helper::request($service, 'export', $params, false);
        } catch (\Exception $e) {
            debugging("Failed to export notes doc {$docid}: " . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Sanitize the HTML exported from a Google Doc into safe, structure-preserving HTML.
     *
     * Google Docs HTML wraps content in <html><head><style>...</style></head><body>...</body>.
     * We keep only the body's inner HTML and run it through Moodle's clean_text() with
     * FORMAT_HTML, which strips scripts/styles/event handlers but preserves headings/lists.
     *
     * @param string $html Raw exported HTML
     * @return string Safe HTML (may be empty string)
     */
    private function sanitize_notes_html($html) {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // Extract inner <body> if present; otherwise use the whole string.
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m)) {
            $html = $m[1];
        }

        // Drop <style> blocks Google Docs inlines in the body.
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        // Strip Google Docs' inline presentation (style/class/id attributes) so the
        // plugin's own CSS governs appearance — otherwise the 32pt Roboto styling from
        // the export overrides the notes panel. Semantic tags (h1-h6, ul/li, p, strong)
        // survive and carry the structure.
        $html = preg_replace('/\s(?:style|class|id|dir|lang)="[^"]*"/i', '', $html);

        $html = clean_text($html, FORMAT_HTML);

        // The Gemini Doc adds chrome we don't want in the panel: a header, the full
        // transcript appended at the end (shown in its own tab), and promo/disclaimer
        // paragraphs. Keep only the notes (Resumen/Summary/Resumo …). Markers are
        // localized (es/en/pt); a language-agnostic fallback also removes the transcript.
        // (a) Drop everything before the first notes heading.
        $html = preg_replace('/^.*?(?=<h[23]\b[^>]*>(?:(?!<\/h[23]>).)*?(?:Resumen|Summary|Resumo)\b)/isu', '', $html);
        // (b) Drop the transcript appendix: localized markers first…
        $html = preg_replace('/<p\b[^>]*>(?:(?!<\/p>).)*?📖\s*(?:Transcripci[oó]n|Transcript|Transcri[çc][aã]o).*$/isu', '', $html);
        $html = preg_replace('/<h2\b[^>]*>(?:(?!<\/h2>).)*?(?:Transcripci[oó]n|Transcript|Transcri[çc][aã]o).*$/isu', '', $html);
        // …then language-agnostic: cut from the first timestamp heading (<h3>HH:MM:SS</h3>), used only by the transcript.
        $html = preg_replace('/<h3\b[^>]*>(?:(?!<\/h3>).)*?\b\d{1,2}:\d{2}:\d{2}\b.*$/isu', '', $html);
        // (c) Drop Gemini promo/disclaimer paragraphs (es/en/pt).
        $html = preg_replace('/<p\b[^>]*>(?:(?!<\/p>).)*?(?:Revisa las notas de Gemini|Check Gemini.?s notes|Revise as notas do Gemini|Gemini (?:toma|tom[oó]|takes) notas|Obt[eé]n sugerencias|Get tips|Responde una breve encuesta|Take a .{0,14}survey|Responda .{0,14}pesquisa)(?:(?!<\/p>).)*?<\/p>/isu', '', $html);
        // (d) Timestamp references point to the (removed) transcript anchors; flatten to plain text.
        $html = preg_replace('/<a\b[^>]*href="#[^"]*"[^>]*>(.*?)<\/a>/is', '$1', $html);

        return trim($html);
    }

    /**
     * Download file content from Google Drive.
     *
     * @param rest $service The REST service
     * @param string $fileid The file ID
     * @return string|null The file content or null on failure
     */
    private function download_file_content($service, $fileid) {
        try {
            $params = ['fileid' => $fileid, 'alt' => 'media'];
            $response = helper::request($service, 'get', $params, false);
            return $response;
        } catch (\Exception $e) {
            debugging("Failed to download file {$fileid}: " . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Parse transcript content to extract plain text.
     *
     * @param string $content The raw transcript content
     * @param string $format The file format (sbv, vtt, txt)
     * @return string The parsed text content
     */
    private function parse_transcript($content, $format) {
        $format = strtolower($format);

        if ($format === 'txt') {
            // Plain text, return as is.
            return trim($content);
        }

        // For SBV and VTT formats, remove timestamps and keep only text.
        $lines = explode("\n", $content);
        $text = [];
        $skipnext = false;

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines.
            if (empty($line)) {
                continue;
            }

            // Skip WEBVTT header.
            if (strpos($line, 'WEBVTT') === 0) {
                continue;
            }

            // Skip timestamp lines (format: 00:00:00.000 --> 00:00:00.000 or 0:00:00.000,0:00:00.000).
            if (preg_match('/^\d{1,2}:\d{2}:\d{2}[.,]\d{3}/', $line)) {
                continue;
            }

            // Skip cue identifiers (numbers).
            if (preg_match('/^\d+$/', $line)) {
                continue;
            }

            // This is actual transcript text.
            // Remove speaker labels like "[Speaker Name]" or "<v Speaker Name>".
            $line = preg_replace('/^\[.*?\]\s*/', '', $line);
            $line = preg_replace('/<v\s+[^>]+>/', '', $line);
            $line = preg_replace('/<\/v>/', '', $line);

            if (!empty($line)) {
                $text[] = $line;
            }
        }

        return implode(' ', $text);
    }

    /**
     * Create a meeting event in Google Calendar
     *
     * @param int $milli The time in milliseconds.
     *
     * @return string The formatted time
     */
    protected function formatseconds($milli=0) {
        $secs = $milli / 1000;

        if ($secs < MINSECS) {
            return '0:'. str_pad(floor($secs), 2, "0", STR_PAD_LEFT);
        } else if ($secs >= MINSECS && $secs < HOURSECS) {
            return floor($secs / MINSECS) .':'. str_pad(floor($secs % MINSECS), 2, "0", STR_PAD_LEFT);
        } else {
            return floor($secs / HOURSECS) .':'.
                str_pad(floor(($secs % HOURSECS) / MINSECS), 2, "0", STR_PAD_LEFT) .':'.
                str_pad(floor(($secs % HOURSECS) % MINSECS), 2, "0", STR_PAD_LEFT);
        }
    }

    /**
     * Get the email of the logged in Google account
     *
     * @return string The email
     */
    public function get_email() {
        if (!$this->check_login()) {
            return '';
        }

        $userauth = $this->get_user_oauth_client()->get_userinfo();
        return $userauth['username'];
    }

}
