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

        $client = $this->get_user_oauth_client();
        if ($this->enabled && $client->get_login_url()->get_host() !== 'accounts.google.com') {
            throw new moodle_exception('invalidissuerid', 'googlemeet');
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
     * Print the login in a popup.
     *
     * @param array|null $attr Custom attributes to be applied to popup div.
     *
     * @return string HTML code
     */
    public function print_login_popup($attr = null) {
        global $OUTPUT;

        $client = $this->get_user_oauth_client();
        $url = new moodle_url($client->get_login_url());
        $state = $url->get_param('state') . '&reloadparent=true';
        $url->param('state', $state);

        return html_writer::div('
            <button class="btn btn-primary" onClick="javascript:window.open(\''.$client->get_login_url().'\',
                \'Login\',\'height=600,width=599,top=0,left=0,menubar=0,location=0,directories=0,fullscreen=0\'
            ); return false">'.get_string('logintoaccount', 'googlemeet').'</button>', 'mt-2');

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
            $js = <<<EOD
<html>
<head>
    <script type="text/javascript">
        window.location = '{$url}'.replaceAll('&amp;','&')
    </script>
</head>
<body></body>
</html>
EOD;
            die($js);
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
     *
     * @return void
     */
    public function syncrecordings($googlemeet) {
        global $PAGE;

        if ($this->check_login()) {
            $service = new rest($this->get_user_oauth_client());

            $folderparams = [
                'q' => 'name = "Meet Recordings" and
                        trashed = false and
                        mimeType = "application/vnd.google-apps.folder" and
                        "me" in owners',
                'pageSize' => 1000,
                'fields' => 'nextPageToken, files(id,owners)'
            ];

            $folderresponse = helper::request($service, 'list', $folderparams, false);

            $folders = $folderresponse->files;
            $parents = '';
            for ($i = 0; $i < count($folders); $i++) {
                $parents .= 'parents="'.$folders[$i]->id.'"';
                if ($i + 1 < count($folders)) {
                    $parents .= ' or ';
                }
            }

            $meetingcode = substr($googlemeet->url, 24, 12);
            $name = $googlemeet->name;
            $recordingparams = [
                'q' => '('.$parents.') and
                        trashed = false and
                        mimeType = "video/mp4" and
                        "me" in owners and
                        (name contains "'.$meetingcode.'" or name contains "'.$name.'")',
                'pageSize' => 1000,
                'fields' => 'files(id,name,permissionIds,createdTime,videoMediaMetadata,webViewLink)'
            ];

            $recordingresponse = helper::request($service, 'list', $recordingparams, false);

            $recordings = $recordingresponse->files;

            // Filter recordings more strictly to avoid duplicates across activities.
            // The Drive API "contains" filter is broad, so we do additional validation here.
            $recordings = $this->filter_recordings_for_activity($recordings, $meetingcode, $name);

            if ($recordings && count($recordings) > 0) {
                for ($i = 0; $i < count($recordings); $i++) {
                    $recording = $recordings[$i];

                    // If the recording has already been processed.
                    if (isset($recording->videoMediaMetadata)) {
                        if (!in_array('anyoneWithLink', $recording->permissionIds)) {
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

                        // Try to find and fetch associated transcript.
                        $transcriptdata = $this->find_transcript_for_recording($service, $parents, $recording->name);
                        if ($transcriptdata) {
                            $recordings[$i]->transcriptfileid = $transcriptdata['fileid'];
                            $recordings[$i]->transcripttext = $transcriptdata['content'];
                        }

                        unset($recordings[$i]->id);
                        unset($recordings[$i]->permissionIds);
                        unset($recordings[$i]->videoMediaMetadata);
                    } else {
                        $recordings[$i]->unprocessed = true;
                    }
                }

                sync_recordings($googlemeet->id, $recordings);
            }

            $url = new moodle_url($PAGE->url);
            $js = <<<EOD
<html>
<head>
    <script type="text/javascript">
        window.location = '{$url}'.replaceAll('&amp;','&')
    </script>
</head>
<body></body>
</html>
EOD;
            die($js);
        }
    }

    /**
     * Filter recordings to match only those belonging to this specific activity.
     *
     * The Drive API "contains" filter is broad and may return recordings from
     * other activities with similar names. This method applies stricter filtering:
     * 1. Recording name must start with the activity name (case-insensitive)
     * 2. Or recording name must contain the exact meeting code
     * 3. Recording must not already exist in another activity (avoid duplicates)
     *
     * @param array $recordings Array of recording objects from Drive API
     * @param string $meetingcode The meeting code (e.g., "abc-defg-hij")
     * @param string $activityname The activity name in Moodle
     * @return array Filtered array of recordings
     */
    private function filter_recordings_for_activity($recordings, $meetingcode, $activityname) {
        global $DB;

        if (empty($recordings)) {
            return [];
        }

        $filtered = [];
        $activitynamelower = core_text::strtolower(trim($activityname));

        foreach ($recordings as $recording) {
            $recordingname = $recording->name ?? '';
            $recordingnamelower = core_text::strtolower($recordingname);

            // Check if this recording already exists in ANY activity (global duplicate check).
            $existingrecording = $DB->get_record('googlemeet_recordings', ['recordingid' => $recording->id]);
            if ($existingrecording) {
                // Skip this recording - it's already associated with another activity.
                continue;
            }

            // Check 1: Recording name contains the exact meeting code.
            if (!empty($meetingcode) && strpos($recordingnamelower, core_text::strtolower($meetingcode)) !== false) {
                $filtered[] = $recording;
                continue;
            }

            // Check 2: Recording name starts with the activity name.
            // This handles filenames like "Activity Name (2024-01-15 10:00).mp4".
            if (!empty($activitynamelower) && strpos($recordingnamelower, $activitynamelower) === 0) {
                $filtered[] = $recording;
                continue;
            }

            // Check 3: Recording name contains the full activity name followed by space or parenthesis.
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

        // Search for transcript files (sbv, vtt, txt) with similar name.
        $transcriptparams = [
            'q' => '('.$parents.') and
                    trashed = false and
                    "me" in owners and
                    (mimeType = "text/plain" or mimeType = "text/vtt" or mimeType = "application/x-subrip") and
                    name contains "'.$basename.'"',
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
