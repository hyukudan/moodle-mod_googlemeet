# Google Meet™ for Moodle

The Google Meet™ for Moodle plugin allows teachers to create Google Meet rooms directly from Moodle and share meeting recordings stored in Google Drive with students.

> **Note:** This is a fork of [ronefel/moodle-mod_googlemeet](https://github.com/ronefel/moodle-mod_googlemeet) with modernizations and improvements for Moodle 4.x.

## Features

### Core Features
- **Create Google Meet rooms** directly from Moodle without leaving the platform
- **Schedule sessions** with support for recurring events (daily, weekly)
- **Exclusion periods** to skip sessions during holidays (Christmas, Easter, etc.)
- **Cancelled sessions** marking for individual dates with optional reason
- **Upcoming events cards** showing session status (Live, Starting soon, Scheduled, Cancelled)
- **Configurable event limit** to control how many upcoming events are displayed
- **Recording management** with a switchable **card or list view** for easy access to meeting recordings
- **Google Drive integration** to sync and display recordings automatically
- **Visibility controls** for teachers to show/hide recordings from students
- **Student notifications** before scheduled sessions
- **Calendar integration** with Moodle calendar events
- **Mobile app support** for Moodle mobile app (Ionic 5+)

### Recording Management
- **Custom recording filter** to specify the exact name pattern to search in Google Drive
- **Sync feedback** showing how many recordings were added, updated, or removed
- **Automatic recording sync** a configurable number of hours after a session ends, with bounded retries and back-off (`process_autosync` scheduled task)
- **Recording-ready notifications** - learners can opt in to be notified when new recordings are added to the activity
- **Pagination** with configurable recordings per page
- **Sorting options** (newest/oldest first)
- **Cards / List view toggle** - each user picks how recordings are displayed (compact list rows or cards); the choice is saved as a per-user preference and persists across sessions and devices
- **Search & topic filter** - find recordings by title, topic or summary, or filter by an AI topic tag
- **Expandable AI panel** - the teacher AI panel opens as a full-width row beneath the recording (in both card and list views), so it never leaves a gap in the grid

### AI-Powered Features (Gemini)
- **Automatic subtitle extraction** from Google Drive recordings (no video download needed)
- **AI Video Analysis** using Google Gemini to automatically analyze meeting recordings
- **Manual transcript analysis** - paste Google Meet transcripts and analyze with Gemini
- **Automatic summaries** generated from video content (educational content only, ignores small talk)
- **Key points extraction** highlighting the most important learning points
- **Topic tags** for quick content identification
- **Auto language detection** - summaries generated in the same language as the content
- **Preview cards** showing AI summary and topics directly in recording list
- **Teacher-only generation** - professors generate analysis once, students view without API calls
- **Background processing** with scheduled task for queued analyses
- **Resilient retries** - transient Gemini errors (rate limits / overload) are retried automatically with exponential back-off instead of failing permanently
- **CLI bulk processing** for batch transcript extraction and analysis
- **Default model: Gemini 3 Flash Preview** with automatic fallback to Gemini 2.5 Flash
- **Meeting Notes (Notes by Gemini)** - if Google Meet generated a "Notes by Gemini" document for the session, it is synced from Drive, sanitised, trimmed to just the notes (Summary / Next steps / Details — the embedded transcript and Gemini's own chrome are removed) and shown to **students** in a dedicated **Notes** tab. Notes that Gemini publishes after the recording first synced are back-filled on a later sync. Localised trimming for ES/EN/PT with a language-agnostic safety fallback.

### Per-recording hub, AI practice questions & materials
- **Recording hub** - each recording opens its own view (video + tabs: AI summary · Questions · Transcript · Notes · Materials)
- **AI-generated practice questions** from a recording's transcript, inserted into the Moodle **question bank** (reusable, tagged `googlemeet-rec-<id>`)
- **Draft → teacher review → publish** workflow (questions are created as drafts; students only see published ones)
- **One-at-a-time student practice** with immediate feedback, the correct answer and an explanation/citation (formative, no grade) — also available in the Moodle mobile app
- **Materials per recording** - teachers attach files to a specific recording; learners download them from the Materials tab
- **Chapters jump to the minute** - clicking an AI chapter (or any timestamp in the AI summary, key points or transcript) reloads the Drive preview at that moment; deep links (`&t=N`) and a "copy link to this moment" action are supported
- **Study-friendly hub** - lesson header (date · duration · chapters), status bar with a primary "Mark as viewed", chronological previous/next navigation, summary with paragraphs and "Read more", key-points checklist, topic chips that open the filtered list, tabs with counts and `#hash` deep links, and a study sidebar

### Abandoned-recurrence alerts

When a course's live classes end, its Google Meet activity can keep its recurrence active,
generating future "phantom" sessions that still show up in students' Moodle calendar. Because the
calendar sync is one-way (Moodle → Google Calendar, no reverse webhook), Moodle is the source of
truth for the schedule — closing the event in Google Calendar does **not** propagate back.

A weekly scheduled task (`\mod_googlemeet\task\check_stale_recurrence`, Mondays 04:00) flags any
activity that still schedules future sessions, has recorded before, but has had no recording for N
weeks, and notifies site admins with a link to edit the activity (set *Repeat until* to a past date).

Settings (Site administration → Plugins → Activity modules → Google Meet):

- **Enable abandoned-recurrence alerts** (`stalerecurrence_enabled`, default on)
- **Weeks without recording** (`stalerecurrence_weeks`, default 3)
- **Re-notify after (days)** (`stalerecurrence_renotifydays`, default 28)

### Reminders, push and "Add to my calendar"

- **Two reminders per session**: the existing one *Minutes before* the start and an optional
  *Early reminder* N hours before (per-activity `notifyhoursbefore`, default from the site setting
  `googlemeet/notifyhoursbefore` = 24 h, "Off" = 0). Each reminder is sent once per student and
  session (`googlemeet_notify_done.kind`). The send window tolerates cron gaps: a reminder is still
  sent when the task comes back late, as long as the session has not started; a late run never sends
  both reminders at once. Cancelled sessions get no reminder.
- **Push notifications in the Moodle app**: the reminder and the new-recording notice allow the
  `airnotifier` processor (enabled by default) and carry `customdata` (`cmid`, `courseid`, `appurl`)
  so tapping the notification opens the activity. **Requires the site to have the Moodle app
  notifications (airnotifier) configured** (Site administration → Messaging → Mobile); without it
  nothing changes.
- **New-recording notice with content**: each new lesson is announced with its readable title and,
  when the AI analysis is ready, a short summary and its chapters (the notice waits up to 3 hours
  for a queued analysis). When the site setting `googlemeet/requireaireview` is on, only reviewed
  analyses are quoted. The HTML body is the `mod_googlemeet/email_new_recordings` template, wrapped
  by `local_achievements` only if that plugin is installed. All texts come from the language pack in
  the recipient's language.
- **Teacher alert**: when auto-sync exhausts `maxsyncattempts` without finding a recording, the
  activity's teachers (`mod/googlemeet:syncgoogledrive`) are told in the same run (message
  provider `autosyncfailed`); site admins only when no teacher qualifies.
- **Add to my calendar**: the live-class block links to `calendar.php?id=<cmid>`, an iCalendar
  (.ics) file with every upcoming, non-cancelled session as its own event (UTC times, stable UIDs
  so a re-import updates instead of duplicating), and to a Google Calendar template for the next
  session.

## Requirements

- Moodle 5.0 or higher (tested on 5.1). Since 2.29.0 the templates use Bootstrap 5 `data-bs-*` markup (teacher "Acciones" menu), so Moodle 4.5 is no longer supported; stay on 2.28.x there.
- PHP 8.1 or higher

## Installation

1. Copy this plugin to the `mod/googlemeet` folder on the server
2. Login as administrator
3. Go to Site Administrator > Notifications
4. Install the plugin
5. Configure OAuth 2 service for Google (see below)

## OAuth 2 Configuration

To create Google Meet rooms from Moodle, you need an active OAuth 2 service for Google.

[Learn how to create Client ID and Client Secret](https://github.com/ronefel/moodle-mod_googlemeet/wiki/How-to-create-Client-ID-and-Client-Secret)

## Usage

### Creating a Google Meet activity

1. Turn editing on in your course
2. Add an activity > Google Meet
3. Enter the meeting name and configure options
4. Save the activity

### Managing recordings

1. Open the Google Meet activity
2. Login with your Google account (if not already logged in)
3. Click "Sync with Google Drive" to fetch recordings
4. Use the visibility toggle to show/hide recordings from students

### Configuring AI Features

1. Get a free Gemini API key from [Google AI Studio](https://aistudio.google.com/app/apikey)
2. Go to Site Administration > Plugins > Activity modules > Google Meet
3. Enable AI features and enter your API key
4. Select your preferred model (Flash for speed, Pro for quality)
5. Optionally enable auto-generation for new recordings

### Using AI Analysis

1. Open a Google Meet activity with synced recordings
2. Click the expand button (▼) on any recording to view AI analysis
3. Teachers can click "Generate AI Analysis" to create analysis from video
4. Alternatively, teachers can click the edit icon and paste a Google Meet transcript to analyze
5. Students will see the summary preview directly in the recording card
6. Click on the preview or expand button to see the full analysis
7. Use "Copy" to copy the transcript to clipboard

### Practice questions & materials (recording hub)

Open a recording (click its name or play button in the recordings list) to enter its **hub**.

- **AI practice questions** (teachers): in the *Questions* tab, click *Generate AI questions*. Questions
  are created in the course's question bank as **drafts** (tagged `googlemeet-rec-<id>`). Review each
  one, edit if needed, then **Publish**. Students only ever see published questions. Requires the
  `mod/googlemeet:managequestions` capability.
- **Practice** (students): in the *Questions* tab, answer the published questions one at a time and get
  immediate feedback with the correct answer and an explanation. Available on the web and in the Moodle
  mobile app. It is formative — nothing is graded or stored.
- **Materials** (teachers): in the *Materials* tab, click *Manage materials* to attach files (slides,
  PDFs, etc.) to that recording. Anyone who can view the activity can download them. Requires the
  `mod/googlemeet:editrecording` capability.

### CLI Bulk Processing

Extract subtitles and run AI analysis for all recordings in one command:

```bash
# Requires yt-dlp in PATH or at the googlemeet/ytdlppath setting (a persistent, admin-only path, never /tmp):
# curl -sL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /path/to/bin/yt-dlp && chmod +x /path/to/bin/yt-dlp

# Process all recordings for a Google Meet activity
php admin/cli/process_transcripts.php --googlemeetid=1

# Dry run (preview without changes)
php admin/cli/process_transcripts.php --googlemeetid=1 --dry-run

# Process a single recording
php admin/cli/process_transcripts.php --googlemeetid=1 --recordingid=4

# Extract subtitles only (skip Gemini analysis)
php admin/cli/process_transcripts.php --googlemeetid=1 --skip-gemini

# Force a specific subtitle language (overrides the site setting; default 'es')
php admin/cli/process_transcripts.php --googlemeetid=1 --language=en
```

Subtitle language priority: `--language`/`-l` flag > `googlemeet/subtitlelanguage` site setting > `es`.

The CLI script extracts Google Drive's auto-generated subtitles (~200KB) instead of downloading the full video (~1GB), making it much faster and lighter.

## Changes in this fork

### Version 2.29.1 (2026-10-09) — Fixes from the 2.29.0 production check (RemUI)

- **Activity list**: the "Filtros" button no longer shows on desktop under RemUI/Boost (theme `.btn` display rules beat the plugin rule); the search, topic, order and view controls line up again. It still shows on phones.
- **Search**: a results line says how many classes contain the term and where it is looked for (title, summary, topics, plus notes and transcript for teachers). Matches in the AI summary or topics now show a "Match in summary/topics" hint with the matching text, like notes/transcript matches already did, and students see these hints too. The generated display title is searched as well as the stored name.
- **Viewing report**: the matrix no longer widens the page (3640px on a 390px phone). The scroller is now positioned, so the hidden per-cell labels stay inside it; the student column stays sticky.
- **Recording hub (phones)**: the chapter list also collapses when the window enters the phone layout after loading (rotation/resize), not only on load.
- **Question review**: the "Select all" checkbox label switches to "Deselect all" while every question is ticked.

#### Deployment notes (2.29.1)

- Version 2026101003: run `admin/cli/upgrade.php`, purge caches (CSS, AMD and strings changed), reset opcache. No DB changes.

### Version 2.29.0 (2026-10-09) — Recording hub, activity list, AI review flow and practice analytics

- **Key points checklist (keypoints)** - the "reviewed" ticks of a recording's key points persist per user in the user preference `mod_googlemeet_kp_<recordingid>` (`<hash>:<bits>`, reset automatically when the key points change). Declared and exported by the privacy provider.
- **Recording hub (hub)** - lesson title above the player; full-width player on phones; visible "Video not loading? Open in Google Drive" link under the player (the duplicate "Open in Drive" in the status bar is gone); chapters toggle only on phones, 2-line chapter titles; a single prev/next system (bottom cards); the Questions tab is hidden from students when there are no published questions; "Meet notes" tab with an explanation; edge fades on the tab row; "Continue with chapter …" resume label (`googlemeet_hub_resume_label()`); dark-mode contrast fixes. Lesson pages (`view.php?recording=`) no longer repeat the activity description and get the `googlemeet-hub-page` body class.
- **AI review flow (ai)** - "Analizar con Gemini" is the primary action and replacing an existing (or hand-edited) analysis asks for confirmation; analysis generation is shown as queued. Teacher question panel: select all, "Publish selected (N)", "Publish all for this class (N)" and "Publish all drafts in the activity (N)" (new WS `mod_googlemeet_publish_activity_drafts`; each class is published as a whole or not at all); correct answers are marked with an icon and the word "Correct", not only colour. New AMD module `question_review`.
- **Activity page list (list)** - the hero does not repeat the activity title; 12 lessons per page by default (instances still on the old default 5 are migrated to 12); hero progress "N vistas · M empezadas de T clases"; "Parcial" renamed "Empezada"; teacher rows show "Abrir clase" plus a labelled "Acciones" dropdown and the students' activity on each lesson; search as you type (server side, debounced) and a "Filtros" panel on phones; one vocabulary ("Clase en directo" / "Clases grabadas"); dark mode.
- **Practice analytics (analytics)** - every practice answer is stored in the new table `googlemeet_practice_attempts` (privacy export/delete, backup/restore with user data, removed with the recording/activity). New `practice.php` (review failed questions / practise by topic across the activity, WS `mod_googlemeet_get_practice_session`, AMD `practice_session`) and a hero call-to-action. New teacher report `report.php` (viewing and practice per student, CSV/XLSX export) behind the new capability `mod/googlemeet:viewreports`, linked from the activity settings navigation. The hub teacher questions panel shows "% correct" per question from those attempts.
- `mod_googlemeet_check_practice_answer` is now a `write` web service (it stores the attempt).
- New tests: `keypoints_checklist_test`, `hub_ui_test`, `question_publish_test`, `practice_attempts_test`, `report_builder_test`, `privacy_practice_attempts_test`, `backup_practice_attempts_test`.

#### Deployment notes (2.29.0)
- **Requires Moodle 5.0+** (`requires = 2025041400`, `supported = [500, 501]`).
- Run `upgrade.php`: step `2026100903` changes the `maxrecordings` default to 12 and moves instances with 5 to 12; step `2026101001` creates `googlemeet_practice_attempts`. Version `2026101002`.
- New capability `mod/googlemeet:viewreports` (teacher, editingteacher, manager by default): review overrides in custom roles.
- New web services `mod_googlemeet_publish_activity_drafts` and `mod_googlemeet_get_practice_session`; `check_practice_answer` changed from read to write (the upgrade re-registers them).
- Purge all caches (new strings, templates, AMD modules) and reset opcache when `opcache.validate_timestamps=0`.
- Practice attempts are only recorded from this version on: reports and "% correct" start empty.

### Version 2.28.1 (2026-10-09) — Friendly message for stale recording links

- Opening a trashed, deleted or hidden recording link no longer shows "Can't find data record in database table {$a}": view.php redirects to the activity with a warning and web services return the new `recordingnotfound` string. Internal `invalidrecord` errors now carry their table name.

### Version 2.28.0 (2026-10-09) — Practice questions survive duplication and course copies
- **Questions in backup/duplicate (DAT-02)** - the module declares `FEATURE_USES_QUESTIONS` (private question bank in the module context, like `mod_quiz`; it does not publish questions) and the activity backup now annotates its question categories, so duplicating the activity or backing up/restoring/copying the course carries the AI practice questions with their draft/published status. After restore the category idnumber is rewritten to `googlemeet_cm_<new cmid>` and each `googlemeet-rec-<id>` tag is remapped to the restored recording id; questions of recordings that were in the trash (not backed up) are kept under a `googlemeet-orphan-rec-<oldid>` tag. `get_category()` also self-heals an activity whose only category still carries another cmid.
- **Coherent restore (DAT-03, minimal)** - a restored/duplicated activity no longer keeps the original's Google Calendar `eventid` (so it can never edit the original event) nor its `lastsync`; future sessions get fresh auto-sync bookkeeping (past sessions keep theirs, so old sessions are not re-synced). The organizer email is kept (it owns the Meet room and the Drive recordings the copy keeps using).
- **Sync keeps inherited recordings** - a Drive recording that belongs to both the source and the copy is no longer skipped (and then trashed) by the copy's sync.
- New tests: `tests/backup_restore_test.php`.

#### Deployment notes (2.28.0)
- Run `upgrade.php` (version bump only, no schema or data migration) and purge caches.
- Existing questions stay where they are (the module context of each activity); nothing is moved. Copies made **before** 2.28.0 have no questions: they must be re-duplicated or the questions regenerated.
- New activities now get core's default "Default for …" question category in their module context (side effect of `FEATURE_USES_QUESTIONS`); the practice questions keep using their own `googlemeet_cm_<cmid>` category.
- The user who restores/duplicates needs `moodle/question:add` and `moodle/question:managecategory` in the target course; otherwise core moves the questions to a fallback course question bank (`mod_qbank`) and they are not linked to the activity.

### Version 2.27.1 (2026-10-09) — Fase 0 hotfixes
- **Reminder recipients (NOT-01)** - pre-session reminders go to students only (a role with the `student` archetype in the course/module context or above) who have an active enrolment, can view the activity and pass its access restrictions; suspended users, users of other courses and teachers (editing or non-editing) are excluded.
- **Optional leak check (QA-02)** - publishing/editing practice questions no longer fatals if `local/questions/fugaslib.php` is missing; the check is skipped with a debugging notice. With the library present, behaviour is unchanged (if one question fails, none is published).
- **Organizer email locked (PRIV-05 mitigation)** - on existing activities the organizer email can only be changed by a site administrator or to the editor's own linked Google account.
- **No `/tmp/yt-dlp` (PRIV-08)** - yt-dlp is resolved only from the `ytdlppath` setting or PATH; otherwise the subtitle tier is skipped.
- **Graceful pages (UX-03)** - `material.php` without/with an invalid `recording` redirects to the activity with a notice; an invalid stored Meet URL hides the join button (warning shown only to editors) instead of breaking the page for everyone.
- **Userinfo session cache (PERF-01)** - the linked Google account (email, name, avatar) is cached per session for one hour (`cachedef_userinfo`), removing the userinfo/avatar requests from every teacher page load; purged on logout/relink.
- **Compatibility (QA-01, QA-04)** - requires Moodle 4.5 (`supported = [405, 501]`), declares `MOD_PURPOSE_COMMUNICATION`, and the global `sync_recordings()` is now `googlemeet_sync_recordings()`.

#### Deployment notes (2.27.1)
- Run `upgrade.php` and purge caches (registers the new `mod_googlemeet/userinfo` cache definition); reset opcache when `opcache.validate_timestamps=0`.
- No database schema changes.
- Make sure `googlemeet/ytdlppath` points to an executable outside `/tmp` (or yt-dlp is in the web/cron user's PATH).

### Version 2.27.0 (2026-10-08) — Hub and list design pass
- **Distinguishable lesson titles** - lesson titles are told apart from the AI topics shown beside them.
- **Hub header and navigation** - date · duration · chapters header, a status bar with "Mark as viewed" as the primary action, and previous/next links in chronological order.
- **Study-friendly summary** - the AI summary is split into paragraphs with a "Read more" toggle; key points become a checklist (checked state is stored in the browser only, via `localStorage`); topic chips link to the recording list filtered by that topic.
- **Tabs** - scrollable tabs with item counts and `#hash` deep links (the tab is restored from the URL); shorter study tab on phones.
- **Practice** - tappable options, live score, result summary and a "Review missed questions" action (attempts are still not stored).
- **Lesson list** - "N of M viewed" progress, a "Pending" filter, compact rows and a compact hero on phones.
- **Study sidebar** with shortcuts to topics, practice and materials.
- **Print summary** - a "Print summary" action and a print stylesheet for study notes.
- **Dark mode fixes** for the hub, chapters, lesson list and hero.

### Version 2.26.0 (2026-10-08) — Player improvements
- **Chapters seek the Drive preview** - a chapter click reloads the embedded preview iframe at that minute (`?t=`).
- **Clickable timestamps** in the AI output (summary, key points) and in the transcript.
- **Deep links** (`&t=N`) open the recording at a given moment, plus a "copy link to this moment" button per chapter.
- **Approximate "Continue at mm:ss"** - resumes from the last jump point; stored as the per-user preference `mod_googlemeet_lastjump_<recordingid>` (declared in the privacy provider).
- **Chapter panel layout** - side by side with the video on desktop, collapsible on mobile.
- **Transcript search** (teachers) with jump to the matching moment.
- **Player help** - a "Can't see the video?" help block and iframe hardening (`loading="lazy"`, `referrerpolicy`).

#### Known limitations (2.26 / 2.27)
- The embedded Drive viewer cannot report the playback position, so there is no real progress tracking or resume: "Continue at mm:ss" is only the last point the user jumped to.
- Recordings are shared "anyone with the link" on Drive by design (`makerecordingspublic`) so enrolled students can play them.
- Practice attempts are not stored; the score and "review missed" list exist only during the session.
- The `?t=` parameter of the Drive preview is undocumented Drive behaviour and may change.

#### Deployment notes (2.26 / 2.27)
- Run `upgrade.php` (Site administration > Notifications or `admin/cli/upgrade.php`) and purge caches; reset opcache too when `opcache.validate_timestamps=0`.
- No database schema changes in 2.26 or 2.27.
- New user preference: `mod_googlemeet_lastjump_<recordingid>`.

### Version 2.25.3 (2026-07-06)
- **Live hide/show toggle** - the "Hide from students" / "Show to students" control now flips its label via AJAX with no page reload.
- **"View full schedule"** link is always visible in the classroom hero.
- **Moodle calendar integration** - a `core_calendar` `provide_event_action` callback adds a "Go to classroom" action (actionable from 30 minutes before a class until it ends); the Moodle-side calendar mirror is reconciled on cancel/reschedule, events are named "Live class: {activity}", and a campus-calendar link is exposed.

### Version 2.25.2 (2026-07-06)
- **Progress indicator redesign** - the per-recording progress bar renders only while watching is in progress (1–99%) and shows the numeric percentage.
- **Hero refinements** - an "Upcoming classes" block plus a collapsible "Class schedule" section; the "Continue watching" card falls back honestly to the "Latest class" when there is no in-progress recording.
- **Recording hub navigation** - Previous/Next links between recordings; a player empty-state for Drive links that cannot be embedded; the default tab is always Summary for students.
- **Consistent recording cards** with a 2-line summary clamp, plus mobile string fixes.

### Version 2.25.1 (2026-07-05) — Virtual classroom (F1–F3)
- **"Enter room" CTA** with live/soon states and a countdown; the room becomes clickable 30 minutes before a class starts.
- **Per-student viewing progress** - new `googlemeet_recording_progress` table, an anti-IDOR web service, a 30s visibility-aware heartbeat, Viewed/Partial badges, a "Mark as viewed" action, and a completion threshold (60%, capped at 8 min). Declared in the privacy provider and backup/restore.
- **Classroom hero** showing the next class and a "Continue watching" entry point.
- **Per-recording AI chapters** - new `chapters` column plus a `cli/backfill_chapters.php` backfill script; the lesson list is grouped by month.
- **AI questions backfill CLI** (`cli/backfill_questions.php`).
- **Hygiene** - removed the dead `get_api_key`, and suspended users are now filtered out of the sync tasks.

### Version 2.20.x – 2.24.x
- **Soft-delete recordings + teacher trash** - recordings are soft-deleted with a per-recording trash button and restore, backed by a per-instance sync lock and auto-sync reset.
- **Sync robustness** - Drive listing pagination, retry/back-off, regex-based name matching, and deferred enrichment.
- **Search across transcripts and Gemini notes** with accent folding and match snippets.
- **AMD migration** - inline template JavaScript moved to proper AMD modules, with a shared `ai_result` partial and CSS custom properties; documented the server-side `format_text` sanitisation boundary in the practice player.
- **UX pass** - cleaner recording titles, more readable chips, Material icons in the hub, core Moodle modals for confirmations, and a per-recording trash button.
- **Complete Spanish translation** (105 keys) with unified *tuteo* in the `_help` strings, dropped dead `.dark` CSS, a sync overlay, and accessibility quick wins.
- **Mobile-safe recording emails** - the "new recording" notification HTML renders reliably on mobile clients, and recording subjects no longer use emoji (style guide).

### Version 2.18.x – 2.19.x (Meeting Notes)
- **Meeting Notes (Notes by Gemini)** - new `notestext`/`notesdocid` columns on recordings; the Gemini meeting-notes Google Doc is matched in Drive (by the meeting-name prefix, since the recording ends `- Recording` and the notes Doc ends `- Notas de Gemini` / `- Notes by Gemini`), exported to HTML, sanitised and trimmed, then shown to students in a **Notes** tab. Declared in the privacy provider and backup/restore (`$userinfo`-gated).
- **Robust note trimming** - removes the Doc header, the embedded transcript appendix (localised ES/EN/PT markers + a language-agnostic fallback that cuts from the first `HH:MM:SS` heading) and Gemini's promo/disclaimer text; flattens transcript timestamp links to plain text; runs `clean_text()` before trimming so HTML-entity-encoded markers match.
- **REST fix** - declared the Drive `get` (alt=media) and `export` endpoints; `helper::request()` now allows raw string responses (this also fixes transcript-file downloads, which previously failed silently).
- **Recording materials UX** - the per-recording materials render as file rows (type icon · name · size · download cue) in both card and list views, teacher and student variants, with dark-mode styling and accessible download labels.
- **Sync efficiency** - the Drive document listing used for note matching is fetched once per sync instead of once per recording.

### Version 2.14.1
- UX polish: Summary tab shows a clear "AI not enabled" state when AI is off (instead of "no analysis yet"); cleaner teacher question-review card layout (separate checkbox / stem / status badge); accessible labels.
- Code cleanup: simplified the Drive large-file download confirm-token handling in the video-analysis task (removed a redundant request).

### Version 2.14.0
- **Materials per recording** - teachers attach files to a recording (own `pluginfile` file area, capability- and instance-scoped; backed up/restored). New "Materials" hub tab.
- **Mobile practice** - the student practice player is now available in the Moodle app (one question at a time; server-side answer checking). *Needs real-device testing.*

### Version 2.13.x
- **Per-recording hub** at `view.php?id=&recording=` (video + tabs: AI summary · Questions · Transcript · Materials); the recordings list now opens the hub, with "Open in Drive" as a secondary action.
- **AI practice questions** generated from a recording's transcript into the Moodle **question bank** as drafts, tagged `googlemeet-rec-<id>`; teacher **review → publish** flow (new capability `mod/googlemeet:managequestions`).
- **Student practice player** - one question at a time with immediate feedback, correct answer and explanation (formative). Web services never expose the correct answer to the client.

### Version 2.12.0
- **Per-user "notify me" subscriptions** - learners opt in to be notified when new recordings are available (new table, capability, message provider, privacy + backup coverage).
- **Auto-generate AI analysis on sync** - wires the `ai_autogenerate` setting so newly synced recordings are queued for analysis automatically.
- **AI summaries in the mobile app**.

### Version 2.11.1
- **Schema reconciliation** - added the composite `UNIQUE (eventid, userid)` index on `googlemeet_notify_done` (deduping any existing rows first) and aligned the `syncattempts` column length, clearing all `check_database_schema.php` discrepancies.

### Version 2.11.0
- **Security & robustness pass** from an independent code audit (codex + gemini, adjudicated and verified):
  - **Transient Gemini errors** (rate limits / overload) now retry with bounded exponential back-off instead of being marked permanently failed
  - Fixed **HTML injection** in notification emails (values escaped with `s()`, literal `str_replace`)
  - Uploaded **Gemini files are always cleaned up**, even when analysis fails mid-pipeline
  - **Backup/restore** now honours the "include user data" setting for transcripts and AI analysis
  - **Privacy provider** declares recording transcript/Drive metadata
  - AI **transcript in the web service** is restricted to users who can edit recordings (matching the UI)
  - Fixed activity creation **without a Google login** (`originalname` default)
  - Corrected web-service capability names, CLI subtitle language handling, transcript Drive-search fallback, and added a cron lock to the notification task

### Version 2.10.x
- **Automatic recording auto-sync** N hours after a session ends, with bounded retries/back-off (`process_autosync` task and `autosynchours` setting)
- **Security & quality hardening** - capability and ownership (IDOR) checks on all web services, sesskey on the OAuth callback, SSRF-safe subtitle extraction, generic client-facing AI error messages
- **Visible Gemini model fallback** (primary → `gemini-2.5-flash`) and a configurable subtitle language setting
- **PHPUnit test suite** (`tests/`)

### Version 2.8.0
- **Automatic subtitle extraction** from Google Drive recordings using yt-dlp
  - Extracts auto-generated captions without downloading the full video
  - 3-tier analysis: existing transcript → subtitle extraction → video upload (fallback)
  - New `subtitle_extractor` class for reusable subtitle fetching
- **CLI bulk processing** (`cli/process_transcripts.php`) for batch operations
  - Process all recordings in a Google Meet activity with one command
  - Supports dry-run, single recording, and skip-gemini modes
- **Requires**: [yt-dlp](https://github.com/yt-dlp/yt-dlp) for subtitle extraction

### Version 2.7.4
- **Manual transcript analysis** - paste Google Meet transcripts and analyze with Gemini
- **Updated default model** to Gemini 3 Flash Preview (December 2025)
- **Educational content focus** - AI ignores small talk and focuses on curriculum
- **Auto language detection** - analysis in the same language as the transcript
- **Improved UX** - chevron expand icon instead of star, solid blue theme colors

### Version 2.7.3
- **Custom recording filter** - specify text pattern to match recordings in Google Drive
- **Sync feedback** - shows count of added, updated, and removed recordings
- **Manual AI editing** - teachers can manually enter/edit summaries, key points, and topics
- **Transcript section** hidden from students (teachers only)

### Version 2.7.0
- **Recordings pagination** with configurable items per page
- **Sorting options** (newest/oldest first)
- **Improved disk space management** for video processing

### Version 2.5.0 (AI Features)
- **Added AI-powered video analysis** using Google Gemini API
- Generate summaries, key points, topics, and transcripts from recordings
- Beautiful preview cards with AI content directly in recording list
- Background task processing for asynchronous analysis
- Teacher-only generation with student viewing (no API calls for students)

### Version 2.4.0
- **Added cancelled sessions feature** for individual date cancellations with optional reason
- **Added configurable maximum upcoming events** setting
- Cancelled sessions display with visual indicator and reason

### Version 2.3.0
- **Added exclusion periods** for recurring events (skip holidays like Christmas, Easter)

### Previous versions
- Modernized codebase for Moodle 4.0+ compatibility
- Removed deprecated Ionic 3 mobile support
- Removed deprecated `core-course-module-description` component
- Replaced deprecated `notice()` and `insert_records()` functions
- Removed insecure `unserialize()` usage
- Removed legacy logging system (now uses events)
- Updated minimum requirements to Moodle 4.0
- Fixed play button styling in recordings

## Security

If you discover any security related issues, please use the [GitHub issue tracker](https://github.com/hyukudan/moodle-mod_googlemeet/issues).

## Credits

**Original author:**
- Rone Santos <ronefel@hotmail.com> - [ronefel/moodle-mod_googlemeet](https://github.com/ronefel/moodle-mod_googlemeet)

**Fork maintainer:**
- [hyukudan](https://github.com/hyukudan)

**Development assistance:**
- Code modernization and improvements completed with the assistance of [Claude](https://claude.ai) (Anthropic)

## License

The GNU GENERAL PUBLIC LICENSE. Please see [License File](LICENSE.md) for more information.

---

> ©2018 Google LLC All rights reserved.
> Google Meet and the Google Meet logo are registered trademarks of Google LLC.
