# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Moodle activity module (`mod_googlemeet`) that creates Google Meet rooms from Moodle, syncs recordings from Google Drive, and runs AI analysis on recordings via Google Gemini. Fork of `ronefel/moodle-mod_googlemeet` modernized for Moodle 4.0+. Current version: `$plugin->release` in `version.php`.

This directory is a Moodle plugin — it is expected to live at `{moodle_root}/mod/googlemeet/`. Many files (`view.php`, `callback.php`, `cli/process_transcripts.php`) `require` Moodle's `config.php` via `__DIR__ . '/../../config.php'`, so the plugin only functions inside a Moodle installation.

## Common operations

There is no build system, no composer, no npm, and no test suite. All code is PHP loaded directly by Moodle.

- **Install/upgrade**: bump `$plugin->version` in `version.php` (format `YYYYMMDDNN`) → visit Site Administration > Notifications in Moodle.
- **Add a DB table/column**: edit `db/install.xml` for fresh installs AND add an upgrade step in `db/upgrade.php` keyed to the previous `$plugin->version`.
- **Add a web service**: register it in `db/services.php` and implement the method in `classes/external.php` (uses `core_external\external_api` — Moodle 4.2+ namespaced API).
- **Add a capability**: edit `db/access.php`.
- **Add a scheduled task**: register in `db/tasks.php` and create the class under `classes/task/`.
- **Language strings**: edit `lang/en/googlemeet.php` (authoritative), then `lang/es/` and `lang/pt_br/`.
- **CLI bulk transcript/AI processing**: `php admin/cli/process_transcripts.php --googlemeetid=N [--recordingid=N] [--dry-run] [--skip-gemini]`. Requires `/tmp/yt-dlp` binary.

## Architecture

### Entry points

- `view.php` — main student/teacher view. Handles `?sync=1` (trigger Drive sync) and `?logout=1` (Google logout) query params. Dispatches to template renderers for upcoming events and recordings.
- `mod_form.php` — activity creation/edit form (recurring events, holidays, cancelled sessions, notification settings, recording filter).
- `lib.php` — Moodle core callbacks (`googlemeet_add_instance`, `_update_instance`, `_delete_instance`, `_supports`, etc.). On add/update, calls `client::create_meeting_event()` to create the Google Calendar event + Meet room.
- `locallib.php` — view helpers: `googlemeet_print_recordings`, `googlemeet_get_upcoming_events`, holiday/cancelled-date helpers, event construction for recurring schedules.
- `callback.php` — OAuth2 redirect target. Closes popup and reloads parent.

### `classes/` (namespace `mod_googlemeet`)

- **`client`** — OAuth2 wrapper around `\core\oauth2\api`. Scopes: Drive + Calendar events. Key methods: `create_meeting_event()`, `syncrecordings()`, `check_login()`, `logout()`. Sync localises the Drive "Meet Recordings" folder name (English + Spanish "Registros de reuniones") and filters by meeting code (chars 24–36 of the Meet URL) plus activity name plus the optional user-defined `recordingfilter`.
- **`gemini_client`** — Google Gemini REST client. Supports text prompts and File API uploads (for full-video analysis). **Auto-fallback**: if a call to the configured primary model fails, retries once with `gemini-2.5-flash` (hardcoded `FALLBACK_MODEL` constant). If already on fallback, throws.
- **`subtitle_extractor`** — Uses `yt-dlp` (expected at `/tmp/yt-dlp`) to discover the Google Drive `timedtext` URL for a recording's auto-generated captions, then downloads and parses the subtitle XML. Avoids downloading the full video.
- **`ai_service`** — Higher-level orchestrator used by `external.php` and tasks; combines the Gemini client with DB persistence to `googlemeet_ai_analysis`.
- **`external.php`** (legacy non-namespaced class `mod_googlemeet_external`) — AJAX/WS endpoints: sync, rename/show-hide/delete recordings, generate/get/save/analyze AI analysis.
- **`rest`** — extends `\core\oauth2\rest`; declares the Drive + Calendar endpoints (`list`, `insertevent`, `createconference`, `create_permission`).
- **`helper`** — thin wrappers: `request()` (catches 403 "Access Not Configured" → user-friendly error), `create_calendar_event()` (Moodle-side calendar mirror).
- **`task/notify_event`** — scheduled every 5 min; sends pre-session reminders and records in `googlemeet_notify_done` to avoid duplicates.
- **`task/process_ai_analysis`** — scheduled every 10 min; drains pending rows in `googlemeet_ai_analysis`.
- **`task/process_video_analysis`** — adhoc task queued per-recording. Implements a **3-tier analysis fallback**:
  1. Use existing `transcripttext` already in DB (fastest).
  2. Extract Drive auto-captions via `subtitle_extractor` (fast, no video download).
  3. Download the full video and upload to the Gemini File API (slow, last resort).

### Database (`db/install.xml`)

Seven tables, all keyed to `googlemeet.id`:
- `googlemeet` — activity instances (URL, creator email, recurrence fields, `recordingfilter`, `maxupcomingevents`, `maxrecordings`, `recordingsorder`).
- `googlemeet_events` — concrete generated sessions (timestamps).
- `googlemeet_recordings` — synced Drive videos + optional transcript.
- `googlemeet_notify_done` — dedupes per-user-per-event notifications.
- `googlemeet_holidays` — exclusion date ranges for recurring events.
- `googlemeet_cancelled` — individually cancelled session dates with optional reason.
- `googlemeet_ai_analysis` — one-to-one with `googlemeet_recordings`; holds `summary`, `keypoints` (JSON), `topics` (JSON), `transcript`, `language`, `status` (`pending`/`processing`/`completed`/`failed`), `aimodel`.

### Frontend

- `templates/recordingstable.mustache` — the main recordings UI, includes expandable AI-analysis cards and the transcript-paste/edit modals. Uses Bootstrap 5 (the codebase was recently migrated from BS4 — watch for stale `sr-only`, `ml-*`/`mr-*`, `badge-*`, or `data-dismiss` artifacts in any new work).
- `templates/upcomingevents.mustache`, `syncbutton.mustache`, `mobile_view_page.mustache`.
- `assets/js/build/jstable.min.js` — prebuilt vendor table lib; **there is no source or build step for this file in-repo**.
- `styles.css` — plain CSS, no preprocessor.

## Gotchas

- **OAuth issuer must exist before the plugin is usable.** The Moodle admin creates a Google OAuth 2 service under Site administration > Server > OAuth 2 services, then selects it in the plugin settings (`googlemeet/issuerid`). Without this, `client` constructs in a disabled state.
- **Recording sync is *name-matched*, not event-ID-matched.** Google Meet doesn't tag Drive files with the meeting event ID, so `client::syncrecordings()` filters Drive files by meeting code (substring of the Meet URL) + activity name + the optional `recordingfilter`. If recordings don't show up, the filename likely doesn't contain any of those tokens.
- **Gemini model fallback is silent.** `gemini_client` logs at `DEBUG_DEVELOPER` when it falls back to `gemini-2.5-flash`. If analysis results don't match the configured model, check debug logs.
- **`subtitle_extractor` is hard-coded to look for `/tmp/yt-dlp`.** Missing binary → tier-2 of the analysis pipeline silently skips and tier-3 (full video upload) runs instead.
- **Moodle 4.2+ namespaced externals**: `external.php` uses `core_external\external_api`. Older Moodle versions expose the same classes unnamespaced — do not add a compatibility shim unless explicitly asked; the plugin's minimum (`$plugin->requires = 2022041900`, Moodle 4.0) already covers this for supported versions.
- **Do not call `googlemeet_print_heading` or `googlemeet_print_intro` from `view.php`.** Moodle 4.0+ activity header renders them automatically; calling them duplicates the title/description (see comment in `view.php`).
