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

## Requirements

- Moodle 4.0 or higher
- PHP 7.4 or higher

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
# Requires yt-dlp: curl -sL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /tmp/yt-dlp && chmod +x /tmp/yt-dlp

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
