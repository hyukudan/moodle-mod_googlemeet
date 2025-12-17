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
 * Plugin strings are defined here.
 *
 * @package     mod_googlemeet
 * @category    string
 * @copyright   2020 Rone Santos <ronefel@hotmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['at'] = 'at';
$string['issuerid'] = 'OAuth service';
$string['issuerid_desc'] = '<a href="https://github.com/ronefel/moodle-mod_googlemeet/wiki/How-to-create-Client-ID-and-Client-Secret" target="_blank">How to set up an OAuth Service</a>';
$string['calendareventname'] = '{$a} is scheduled for';
$string['checkweekdays'] = 'Select the days of the week that fall within the selected date range.';
$string['creatoremail'] = 'Organizer email';
$string['creatoremail_error'] = 'Enter a valid email address';
$string['creatoremail_help'] = 'Event organizer email';
$string['date'] = 'Date';
$string['duration'] = 'Duration';
$string['earlierto'] = 'The event date cannot be earlier than the course start date ({$a}).';
$string['emailcontent'] = 'Email content';
$string['emailcontent_default'] = '<p>Hi %userfirstname%,</p>
<p>This reminder is to remind you that there will be a Google meet event in %coursename%</p>
<p><b>%googlemeetname%</b></p>
<p>When: %eventdate% %duration% %timezone%</p>
<p>Access link: %url%</p>';
$string['emailcontent_help'] = 'When a notification is sent to a student, it takes the email content from this field. The following wildcards can be used:
<ul>
<li>%userfirstname%</li>
<li>%userlastname%</li>
<li>%coursename%</li>
<li>%googlemeetname%</li>
<li>%eventdate%</li>
<li>%duration%</li>
<li>%timezone%</li>
<li>%url%</li>
<li>%cmid%</li>
</ul>';
$string['entertheroom'] = 'Enter the room';
$string['eventdate'] = 'Event date';
$string['eventdetails'] = 'Event details';
$string['from'] = 'from';
$string['googlemeet:addinstance'] = 'Add a new Google Meet™ for Moodle';
$string['googlemeet:editrecording'] = 'Edit recordings';
$string['googlemeet:removerecording'] = 'Remove recordings';
$string['googlemeet:syncgoogledrive'] = 'Sync with Google Drive';
$string['googlemeet:view'] = 'View Google Meet™ for Moodle content';
$string['hide'] = 'Hide';
$string['invalideventenddate'] = 'This date can not be earlier than the "Event date"';
$string['invalideventendtime'] = 'The end time must be greater than start time';
$string['invalidissuerid'] = 'The OAuth service selected in the "Google Meet™ for Moodle" settings is not supported by Google';
$string['invalidstoredurl'] = 'Cannot display this resource, Google Meet URL is invalid.';
$string['isnotcreatoremail'] = 'Log in with organizer account or change organizer email in settings to sync recordings.';
$string['jstableinfo'] = 'Showing {start} to {end} of {rows} recordings';
$string['jstableinfofiltered'] = 'Showing {start} to {end} of {rows} recordings (filtered from {rowsTotal} recordings)';
$string['jstableloading'] = 'Loading...';
$string['jstablenorows'] = 'No recording found';
$string['jstableperpage'] = '{select} recordings per page';
$string['jstablesearch'] = 'Search...';
$string['lastsync'] = 'Last sync:';
$string['loading'] = 'Loading';
$string['logintoaccount'] = 'Log in to your Google account';
$string['logintoyourgoogleaccount'] = 'Log in to your Google account so that the Google Meet URL can be automatically created';
$string['loggedinaccount'] = 'Connected Google account';
$string['logout'] = 'Logout';
$string['manage'] = 'Manage';
$string['messageprovider:notification'] = 'Google Meet event start reminder';
$string['minutesbefore'] = 'Minutes before';
$string['minutesbefore_help'] = 'Number of minutes before the start of the event when the notification should be send.';
$string['modulename'] = 'Google Meet™ for Moodle';
$string['modulename_help'] = 'The Google Meet™ module for Moodle allows the teacher to create a Google Meet room as a course resource and after the meetings make available to the students the recordings, saved in Google Drive.
<p>©2018 Google LLC All rights reserved.<br/>
Google Meet and the Google Meet logo are registered trademarks of Google LLC.</p>';
$string['modulenameplural'] = 'Google Meet™ for Moodle instances';
$string['multieventdateexpanded'] = 'Recurrence of the event date expanded';
$string['multieventdateexpanded_desc'] = 'Show the "Recurrence of the event date" settings as expanded by default when creating new Room.';
$string['name'] = 'Name';
$string['never'] = 'Never';
$string['notification'] = 'Notification';
$string['notificationexpanded'] = 'Notification expanded';
$string['notify'] = 'Send notification to the student';
$string['notify_help'] = 'If checked, a notification will be sent to the student about the start date of the event.';
$string['notifycationexpanded_desc'] = 'Show the "Notification" settings as expanded by default when creating new Room.';
$string['notifytask'] = 'Google Meet™ for Moodle notification task';
$string['or'] = 'or';
$string['play'] = 'Play';
$string['pluginadministration'] = 'Google Meet™ for Moodle administration';
$string['pluginname'] = 'Google Meet™ for Moodle';
$string['privacy:metadata:googlemeet_notify_done'] = 'Records notifications sent to users about the start of events. This data is temporary and is deleted after the event start date.';
$string['privacy:metadata:googlemeet_notify_done:eventid'] = 'The event ID';
$string['privacy:metadata:googlemeet_notify_done:userid'] = 'The user ID';
$string['privacy:metadata:googlemeet_notify_done:timesent'] = 'The timestamp indicating when the user received a notification';
$string['recording'] = 'Recording';
$string['recordings'] = 'Recordings';
$string['recordings_count'] = '{$a} recording(s)';
$string['recording_watch'] = 'Watch recording';
$string['recording_hidden'] = 'Hidden from students';
$string['recordingswiththename'] = 'Recordings with the name:';
$string['recurrenceeventdate'] = 'Recurrence of the event date';
$string['recurrenceeventdate_help'] = 'This function makes it possible to create multiple recurrences from the event date.
<br>* <strong>Repeat on</strong>: Select the days of the week that your class will meet (for example, Monday / Wednesday / Friday).
<br>* <strong>Repeat every</strong>: This allows for a frequency setting. If your class will meet every week, select 1; will meet every two weeks, select 2; every 3 weeks, select 3, etc.
<br>* <strong>Repeat until</strong>: Select the last day of the meeting (the last day you want to take the recurring date of the event).';
$string['repeatasfollows'] = 'Repeat the event date above as follows';
$string['repeatevery'] = 'Repeat every';
$string['repeaton'] = 'Repeat on';
$string['repeatuntil'] = 'Repeat until';
$string['roomcreator'] = 'Organizer:';
$string['roomname'] = 'Room name';
$string['roomurl'] = 'Room url';
$string['roomurl_caution'] = '<strong>Caution!</strong> If the room URL or organizer email is changed, synchronized recordings can be removed in the next synchronization.';
$string['roomurl_desc'] = 'The room URL will be automatically generated.';
$string['roomurlexpanded'] = 'Room url expanded';
$string['roomurlexpanded_desc'] = 'Show the "Room url" settings as expanded by default when creating new Room.';
$string['servicenotenabled'] = 'Access not configured. Make sure the \'Google Drive API\' and \'Google Calendar API\' services are enabled.';
$string['sessionexpired'] = 'Your Google account session expired in the middle of the process, please login again.';
$string['show'] = 'Show';
$string['strftimedm'] = '%a. %d %b.';
$string['strftimedmy'] = '%a. %d %b. %Y';
$string['strftimedmyhm'] = '%a. %d %b. %Y %H:%M';
$string['strftimehm'] = '%H:%M';
$string['syncwithgoogledrive'] = 'Sync with Google Drive';
$string['sync_settings'] = 'Sync settings';
$string['sync_help_title'] = 'How sync works';
$string['sync_info'] = 'Wait at least 10 minutes for the recording file to be generated and saved in "My Drive > Meet Recordings" of the organizer.
<p></p>
To remove a recording first delete the recording file from Google Drive and after click the sync button above.
<p></p>
To record a meeting, make sure:
<ul>
    <li>You haven\'t met your personal Drive quota.</li>
    <li>Your organization hasn\'t met its Drive quota.</li>
</ul>
If you have space in your Drive, but your organization doesn\'t have space, you can\'t record the meeting.
<p></p>
For more information, look this Help Center article:
<br>
<a href="https://notifications.google.com/g/p/APNL1TjJltVk6EcLPyFTJ8V_9ty1FeTAD0XSSJVLiaWPezIaQKfIPd1kGURFUMVV3I5yHgVZoOgxkl4gySV-4SCf2pZ27Vk8Iy9DnHSQBqtK51uG3Gyz" target="_blank" rel="nofollow noopener">https://support.google.com/meet/answer/9308681</a>';
$string['sync_notloggedin'] = 'Log in to your Google account for the synchronize Google Meet recording with Moodle';
$string['thereisnorecordingtoshow'] = 'There is no recording to show.';
$string['timeahead'] = 'Is not possible to create multiple recurrences of the event date that exceed one year, adjust the start and end dates.';
$string['timedate'] = '%d/%m/%Y %H:%M';
$string['to'] = 'to';
$string['today'] = 'Today';
$string['upcomingevents'] = 'Upcoming events';
$string['event_status_live'] = 'Live now';
$string['event_status_soon'] = 'Starting soon';
$string['event_status_scheduled'] = 'Scheduled';
$string['event_starts_in'] = 'Starts in {$a}';
$string['event_started_ago'] = 'Started {$a} ago';
$string['event_join_now'] = 'Join now';
$string['event_time_minutes'] = '{$a} min';
$string['event_time_hours'] = '{$a}h';
$string['event_time_days'] = '{$a}d';
$string['event_no_upcoming'] = 'No upcoming sessions';
$string['url'] = '';
$string['url_failed'] = 'A valid Google Meet URL is required';
$string['url_help'] = 'E.g. https://meet.google.com/aaa-aaaa-aaa';
$string['visible'] = 'Visible';
$string['week'] = 'Week(s)';

// Holiday/exclusion periods.
$string['holidayperiods'] = 'Exclusion periods';
$string['holidayperiods_help'] = 'Define periods during which no events will be scheduled (e.g., Christmas holidays, Easter break). Events that would fall within these periods will be skipped.';
$string['addholidayperiod'] = 'Add exclusion period';
$string['removeholidayperiod'] = 'Remove';
$string['holidayname'] = 'Name (optional)';
$string['holidayname_placeholder'] = 'e.g., Christmas holidays';
$string['holidaystartdate'] = 'Start date';
$string['holidayenddate'] = 'End date';
$string['invalidholidayenddate'] = 'The end date of an exclusion period cannot be earlier than its start date';
$string['noholidayperiods'] = 'No exclusion periods defined';

// Cancelled dates.
$string['cancelleddates'] = 'Cancelled sessions';
$string['cancelleddates_help'] = 'Define individual dates when sessions are cancelled (e.g., sick day, public holiday). These sessions will appear with a "Cancelled" indicator rather than being hidden.';
$string['addcancelleddate'] = 'Add cancelled date';
$string['removecancelleddate'] = 'Remove';
$string['cancelleddate'] = 'Date';
$string['cancelledreason'] = 'Reason (optional)';
$string['cancelledreason_placeholder'] = 'e.g., Teacher illness';
$string['event_status_cancelled'] = 'Cancelled';

// Max upcoming events.
$string['maxupcomingevents'] = 'Maximum upcoming events';
$string['maxupcomingevents_help'] = 'Select the maximum number of upcoming events to display on the activity page.';

// Recordings settings.
$string['recordingssettings'] = 'Recordings display settings';
$string['maxrecordings'] = 'Recordings per page';
$string['maxrecordings_help'] = 'Select the maximum number of recordings to display per page. Additional recordings will be accessible via pagination.';
$string['recordingsorder'] = 'Recordings order';
$string['recordingsorder_help'] = 'Select whether to show newest or oldest recordings first.';
$string['recordingsorder_desc'] = 'Newest first';
$string['recordingsorder_asc'] = 'Oldest first';
$string['recordings_pagination_info'] = 'Showing {$a->start} to {$a->end} of {$a->total} recordings';
$string['recordings_page_previous'] = 'Previous';
$string['recordings_page_next'] = 'Next';
$string['recordings_sort_by'] = 'Sort by';
$string['recordings_showing'] = 'Showing';

// AI Features.
$string['ai_settings'] = 'AI Features (Gemini)';
$string['ai_settings_desc'] = 'Configure AI-powered features using Google Gemini to automatically generate summaries, key points, and transcripts from your meeting recordings.';
$string['enableai'] = 'Enable AI features';
$string['enableai_desc'] = 'Enable AI-powered analysis of meeting recordings using Google Gemini.';
$string['geminiapikey'] = 'Gemini API Key';
$string['geminiapikey_desc'] = 'Enter your Google Gemini API key. Get one free at <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>.';
$string['aimodel'] = 'AI Model';
$string['aimodel_desc'] = 'Select the Gemini model to use for analysis. Flash is faster and has a generous free tier, Pro is more capable but has lower free limits.';
$string['ai_autogenerate'] = 'Auto-generate analysis';
$string['ai_autogenerate_desc'] = 'Automatically generate AI analysis when new recordings are synced.';
$string['ai_analysis'] = 'AI Analysis';
$string['ai_summary'] = 'Summary';
$string['ai_keypoints'] = 'Key Points';
$string['ai_transcript'] = 'Transcript';
$string['ai_topics'] = 'Topics';
$string['ai_generate'] = 'Generate AI Analysis';
$string['ai_regenerate'] = 'Regenerate';
$string['ai_generating'] = 'Generating analysis...';
$string['ai_status_pending'] = 'Pending';
$string['ai_status_processing'] = 'Processing';
$string['ai_status_completed'] = 'Completed';
$string['ai_status_failed'] = 'Failed';
$string['ai_noanalysis'] = 'No AI analysis available yet.';
$string['ai_noanalysis_hint'] = 'Click the button above to generate an AI summary, key points, and transcript.';
$string['ai_error'] = 'Error generating analysis: {$a}';
$string['ai_not_configured'] = 'AI features are not configured. Please contact your administrator.';
$string['ai_disabled'] = 'AI features are disabled.';
$string['googlemeet:generateai'] = 'Generate AI analysis for recordings';
$string['ai_generated_on'] = 'Generated';
$string['ai_model'] = 'Model';
$string['ai_model_used'] = 'Model: {$a}';
$string['ai_copy_transcript'] = 'Copy';
$string['ai_copied'] = 'Copied!';
$string['ai_expand'] = 'Expand';
$string['ai_collapse'] = 'Collapse';
$string['ai_process_task'] = 'Process pending AI analyses';
$string['ai_analysis_available'] = 'AI analysis available';
$string['ai_keypoints_short'] = 'key points';
$string['ai_transcript_loading'] = 'Loading transcript...';
$string['ai_error_unknown'] = 'An unknown error occurred. Please try again.';
$string['ai_processing_background'] = 'Analysis in progress';
$string['ai_processing_background_hint'] = 'The video is being downloaded and analyzed. This may take several minutes for long videos. The page will automatically update when complete.';
$string['ai_check_status'] = 'Check status';
$string['ai_process_video_task'] = 'Process video AI analysis';
$string['ai_edit_manual'] = 'Edit manually';
$string['ai_edit_manual_title'] = 'Edit AI Analysis';
$string['ai_edit_summary_placeholder'] = 'Enter a summary of the meeting...';
$string['ai_edit_keypoints_placeholder'] = 'Enter key points (one per line)...';
$string['ai_edit_topics_placeholder'] = 'Enter topics (comma or newline separated)...';
$string['ai_edit_transcript_placeholder'] = 'Paste the transcript here...';
$string['ai_edit_save'] = 'Save';
$string['ai_edit_cancel'] = 'Cancel';
$string['ai_edit_saving'] = 'Saving...';
$string['ai_edit_saved'] = 'Analysis saved successfully';
$string['ai_edit_error'] = 'Error saving analysis: {$a}';
