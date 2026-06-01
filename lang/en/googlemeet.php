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
$string['strftimedm'] = '%d %b';
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
$string['sync_new_recordings'] = '{$a} new recording(s) added';
$string['sync_updated_recordings'] = '{$a} recording(s) updated';
$string['sync_deleted_recordings'] = '{$a} recording(s) removed';
$string['sync_no_changes'] = 'Sync complete. {$a} recording(s) already up to date';
$string['sync_no_recordings_found'] = 'Sync complete. No recordings found in Google Drive for this meeting';
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
$string['recordingfilter'] = 'Recording name filter';
$string['recordingfilter_placeholder'] = 'e.g. Class Meeting CSIF';
$string['recordingfilter_help'] = 'Enter a custom text pattern to filter recordings from Google Drive. The sync will only include recordings whose filename contains this text. This is useful when the recording name in Google Drive (from Google Calendar) differs from the activity name in Moodle. Leave empty to use the default filtering (activity name or meeting code).';
$string['autosynchours'] = 'Auto-sync hours after session end';
$string['autosynchours_help'] = 'Hours to wait after a scheduled session ends before automatically syncing recordings from Google Drive. Set to 0 to disable auto-sync (teachers still can sync manually). Retry policy is controlled site-wide via "Max sync attempts" and "Retry interval".';
$string['autosynchours_default'] = 'Auto-sync default hours';
$string['autosynchours_default_desc'] = 'Default value for "Auto-sync hours after session end" on newly-created Google Meet activities.';
$string['maxsyncattempts'] = 'Max sync attempts';
$string['maxsyncattempts_desc'] = 'Maximum number of auto-sync attempts per event before giving up. Set to 1 to keep the original single-attempt behaviour. Higher values let the task recover from transient failures (token revoked, Drive API errors, recording not yet processed).';
$string['syncretryinterval'] = 'Retry interval (seconds)';
$string['syncretryinterval_desc'] = 'Seconds to wait between two failed auto-sync attempts for the same event. Minimum 60.';
$string['process_autosync_task'] = 'Auto-sync Google Meet recordings after sessions end';
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
$string['ai_notconfigured'] = 'AI analysis is not enabled for this site.';
$string['ai_noanalysis_hint'] = 'Click the button above to generate an AI summary, key points, and transcript.';
$string['ai_error'] = 'Error generating analysis: {$a}';
$string['ai_not_configured'] = 'AI features are not configured. Please contact your administrator.';
$string['ai_disabled'] = 'AI features are disabled.';
$string['googlemeet:generateai'] = 'Generate AI analysis for recordings';
$string['googlemeet:managequestions'] = 'Manage AI practice questions for recordings';
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
$string['ai_subtitles_unavailable'] = 'No auto-generated subtitles were found for this recording. Click "Transcribe from video" to download the full recording and generate a transcript instead.';
$string['ai_transcribe_from_video'] = 'Transcribe from video';
$string['ai_transcribe_from_video_desc'] = 'Download the full recording from Google Drive and let Gemini generate the transcript. This may take several minutes and use up to several GB of temporary disk space.';
$string['ai_transcribe_from_video_confirm'] = 'This will download the full video (may be several GB). Do you want to continue?';
$string['ai_processing_background'] = 'Analysis in progress';
$string['ai_processing_background_hint'] = 'The recording transcript is being retrieved and analyzed (the video itself is not downloaded). This may take a few minutes. The page will automatically update when complete.';
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
$string['ai_analyze_with_gemini'] = 'Analyze with Gemini';
$string['ai_analyze_transcript_hint'] = 'Paste the transcript from Google Meet and click "Analyze with Gemini" to auto-generate summary, key points and topics.';
$string['ai_analyze_empty_transcript'] = 'Please paste a transcript first';
$string['ai_analyzing'] = 'Analyzing...';
$string['ai_analyze_success'] = 'Analysis completed! Review and save the results.';
$string['hub_back_to_recordings'] = 'Back to recordings';
$string['hub_tab_summary'] = 'AI summary';
$string['hub_tab_questions'] = 'Questions';
$string['hub_tab_transcript'] = 'Transcript';
$string['hub_tab_materials'] = 'Materials';
$string['material_saved'] = 'Materials saved';
$string['materials_empty_teacher'] = 'No materials yet; add the first one.';
$string['materials_manage'] = 'Manage materials';
$string['materials_none'] = 'No materials are available for this recording.';
$string['materials_upload'] = 'Materials';
$string['openindrive'] = 'Open in Drive';
$string['question_advanced_edit'] = 'Advanced edit in question bank';
$string['question_bulk_discard'] = 'Discard selected';
$string['question_bulk_publish'] = 'Publish selected';
$string['question_category_name'] = 'Google Meet: {$a}';
$string['question_correct_answer'] = 'Correct answer';
$string['question_discard'] = 'Discard';
$string['question_discard_ready_error'] = 'Only draft questions can be discarded.';
$string['question_edit'] = 'Edit';
$string['question_empty_student'] = 'No questions have been published for this class yet.';
$string['question_empty_teacher'] = 'Generate questions with AI to create drafts for teacher review.';
$string['question_explanation'] = 'Explanation and reference';
$string['question_generate_ai'] = 'Generate questions with AI';
$string['question_generate_more'] = 'Generate more';
$string['question_generate_task'] = 'Generate Google Meet AI questions';
$string['question_generating'] = 'Generating questions...';
$string['question_no_reference'] = 'no reference detected';
$string['question_no_transcript_error'] = 'No transcript is available to generate questions.';
$string['question_publish'] = 'Publish';
$string['question_reference_label'] = 'Reference:';
$string['question_status_draft'] = 'Draft';
$string['question_status_published'] = 'Published';
$string['question_stem'] = 'Question';
$string['question_student_phase1'] = 'Questions have been published. The student practice player will be available in a later update.';
$string['question_unpublish'] = 'Unpublish';
$string['question_ai_draft_note'] = 'Generated with AI, pending review';
$string['question_ai_reviewed_note'] = 'Generated with AI, reviewed by teaching staff';
$string['practice_check'] = 'Check';
$string['practice_correct'] = 'Correct';
$string['practice_correct_answer'] = 'Correct answer:';
$string['practice_finish'] = 'Finish';
$string['practice_finished'] = 'Practice complete';
$string['practice_incorrect'] = 'Incorrect';
$string['practice_loading'] = 'Loading questions...';
$string['practice_next'] = 'Next';
$string['practice_retry'] = 'Retry';
$string['practice_title'] = 'Practice questions';
$string['practice_progress'] = 'Question {$a->current} of {$a->total}';
$string['showhide'] = 'Show/hide';

// Error handling and privacy (security/quality review 2026-05-30).
$string['ai_error_generic'] = 'Error generating analysis. Please try again later or contact your administrator.';
$string['ai_invalid_analysis'] = 'The AI returned an analysis that could not be parsed: {$a}';
$string['ai_video_not_public'] = 'Cannot download the recording for full-video analysis. This option requires the recording to be shared publicly ("Anyone with the link"), which is controlled by the "Make recordings public" setting, or for auto-generated subtitles to be available. The downloaded content was an error page, not a video.';
$string['makerecordingspublic'] = 'Make recordings publicly accessible';
$string['makerecordingspublic_desc'] = 'When enabled, synced recordings are granted "anyone with the link" read access on Google Drive so that enrolled students (who are not the Drive owner) can play the embedded recording. Disabling this improves privacy but breaks playback for everyone except the Google account that owns the recordings.';
$string['noeventswithperiod'] = 'With the selected days and "Repeat every N weeks" period, no events would fall within the chosen date range. Reduce the period or extend the end date.';
$string['privacy:metadata:core_oauth2'] = 'The Google Meet activity makes use of the OAuth 2 subsystem to authenticate users against Google services.';
$string['privacy:metadata:googlemeet'] = 'Information about the Google Meet activity instances.';
$string['privacy:metadata:googlemeet:creatoremail'] = 'The email address of the Google account used to create the Meet room. This identifies the creator but is stored as an activity property, not linked to a Moodle user account.';
$string['privacy:metadata:googlemeet_ai_analysis'] = 'AI-generated analysis of recordings. The transcript may incidentally contain the names or voices of session participants. This data is associated with a recording, not with an individual Moodle user.';
$string['privacy:metadata:googlemeet_ai_analysis:summary'] = 'An AI-generated summary of the recording content.';
$string['privacy:metadata:googlemeet_ai_analysis:keypoints'] = 'AI-generated key points extracted from the recording content.';
$string['privacy:metadata:googlemeet_ai_analysis:topics'] = 'AI-generated topics discussed in the recording content.';
$string['privacy:metadata:googlemeet_ai_analysis:transcript'] = 'A transcript of the recording, which may contain the names or voices of session participants.';
$string['privacy:metadata:googlemeet_recordings'] = 'Information about recordings synced from Google Drive, including the original Meet transcript. The transcript may contain the names or speech of session participants. This data is associated with a recording, not with an individual Moodle user.';
$string['privacy:metadata:googlemeet_recordings:name'] = 'The name of the recording file.';
$string['privacy:metadata:googlemeet_recordings:webviewlink'] = 'The Google Drive link used to view the recording.';
$string['privacy:metadata:googlemeet_recordings:transcripttext'] = 'The original Google Meet transcript of the recording, which may contain the names or speech of session participants.';
$string['privacy:metadata:googlemeet_recordings:transcriptfileid'] = 'The Google Drive file identifier of the recording transcript.';
$string['privacy:metadata:google_gemini'] = 'Recording transcripts and, as a fallback, the full recording video are sent to the Google Gemini API to generate the analysis. These may contain personal data such as participants'."'".' names and voices.';
$string['privacy:metadata:google_gemini:transcript'] = 'The transcript text of the recording sent for analysis.';
$string['privacy:metadata:google_gemini:video'] = 'The full recording video file, sent for analysis when no transcript is available.';
$string['privacy:metadata:google_drive'] = 'Recordings are read from the user'."'".'s Google Drive on their behalf using their OAuth 2 authorisation.';
$string['privacy:metadata:google_drive:userid'] = 'The identity of the authenticated user is sent to Google Drive to access their recordings.';
$string['privacy:metadata:google_calendar'] = 'Calendar events and the Meet room are created and read in the user'."'".'s Google Calendar on their behalf using their OAuth 2 authorisation.';
$string['privacy:metadata:google_calendar:userid'] = 'The identity of the authenticated user is sent to Google Calendar to manage their events and Meet room.';
$string['subtitlelanguage'] = 'Subtitle language';
$string['subtitlelanguage_desc'] = 'Language code used when extracting auto-generated subtitles from Google Drive recordings (e.g. es, en, pt-BR). Defaults to es.';
$string['googlemeet:subscriberecordings'] = 'Subscribe to new recording notifications';
$string['messageprovider:recordingavailable'] = 'Google Meet recording available';
$string['privacy:metadata:googlemeet_recording_subs'] = 'Stores users who have subscribed to notifications when new recordings are available for a Google Meet activity.';
$string['privacy:metadata:googlemeet_recording_subs:googlemeetid'] = 'The Google Meet activity ID.';
$string['privacy:metadata:googlemeet_recording_subs:userid'] = 'The user ID.';
$string['privacy:metadata:googlemeet_recording_subs:timecreated'] = 'The timestamp indicating when the user subscribed to recording notifications.';
$string['recordingavailable_body'] = '{$a->count} new recording(s) are available in {$a->name}.' . "\n\n" . 'Open the activity: {$a->url}';
$string['recordingavailable_subject'] = '{$a->count} new recording(s) available: {$a->name}';
$string['recordingnotificationsubscription'] = 'Recording notification subscription';
$string['subscriberecordings'] = 'Notify me when new recordings are available';
$string['unsubscriberecordings'] = 'Stop notifying me';
$string['ai_status_chip_processing'] = 'Summary in progress';
$string['ai_status_chip_pending'] = 'Summary queued';
$string['ai_status_chip_failed_student'] = 'Summary not available yet';
$string['ai_status_chip_failed_teacher'] = 'Summary generation failed';
$string['ai_badge_label'] = 'AI summary';
$string['recording_watch_cta'] = 'Watch class';
$string['recording_new'] = 'New';
$string['recording_view_summary'] = 'View summary';
$string['recording_play_aria'] = 'Play class';
