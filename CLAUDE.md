# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Moodle activity module (`mod_googlemeet`) that creates Google Meet rooms from Moodle, syncs recordings from Google Drive, and runs AI analysis on recordings via Google Gemini. Fork of `ronefel/moodle-mod_googlemeet` modernized for Moodle 4.0+. Current version: `$plugin->release = '2.17.6'` / `$plugin->version = 2026060123` in `version.php`.

This directory is a Moodle plugin — it is expected to live at `{moodle_root}/mod/googlemeet/`. Many files (`view.php`, `callback.php`, `cli/process_transcripts.php`) `require` Moodle's `config.php` via `__DIR__ . '/../../config.php'`, so the plugin only functions inside a Moodle installation.

## Common operations

There is no build system, no composer, and no npm. All code is PHP loaded directly by Moodle. There IS now a PHPUnit suite (`tests/locallib_test.php`, `tests/subtitle_extractor_test.php`).

- **Install/upgrade**: bump `$plugin->version` in `version.php` (format `YYYYMMDDNN`) → visit Site Administration > Notifications in Moodle.
- **Run tests**: `vendor/bin/phpunit mod/googlemeet` (from the Moodle root, after `php admin/tool/phpunit/cli/init.php`). On this box: `init.php` fails under composer/PHP 8.5, so rebuild the test DB with `php admin/tool/phpunit/cli/util.php --drop` then `--install`; also generate the `en_AU.UTF-8` locale (`localedef` into `~/.locales` + export `LOCPATH`) or tests error out.
- **Add a DB table/column**: edit `db/install.xml` for fresh installs AND add an upgrade step in `db/upgrade.php` keyed to the previous `$plugin->version`.
- **Add a web service**: register it in `db/services.php` and implement the method in `classes/external.php` (uses `core_external\external_api` — Moodle 4.2+ namespaced API).
- **Add a capability**: edit `db/access.php`.
- **Add a scheduled task**: register in `db/tasks.php` and create the class under `classes/task/`.
- **Language strings**: edit `lang/en/googlemeet.php` (authoritative), then `lang/es/` and `lang/pt_br/`.
- **CLI bulk transcript/AI processing**: `php admin/cli/process_transcripts.php --googlemeetid=N [--recordingid=N] [--language=es|-l es] [--dry-run] [--skip-gemini]`. Requires `/tmp/yt-dlp` binary. Subtitle language priority: `--language` > `googlemeet/subtitlelanguage` setting > `'es'`.
- **Deploy (this box)**: the plugin is served from `formacion51/public/mod/googlemeet` (a flat copy, no `.git`). Deploy = `rsync` the repo there → run `admin/cli/upgrade.php` (CLI lives OUTSIDE `public/`) → `purge_caches` → reset web opcache via a temporary script dropped in `public/` over HTTPS (`opcache.validate_timestamps=0`). After deploy run `set_config('makerecordingspublic', 1, 'googlemeet')` (see Gotchas) or recording playback breaks.

## Architecture

### Entry points

- `view.php` — main student/teacher view. Write actions (`?sync=1` trigger Drive sync, `?logout=1` Google logout) are delegated to `googlemeet_handle_view_actions()` in `locallib.php`, which enforces the `editrecording` capability + sesskey and, for sync, requires a POST request. Dispatches to template renderers for upcoming events and recordings.
- `mod_form.php` — activity creation/edit form (recurring events, holidays, cancelled sessions, notification settings, recording filter).
- `lib.php` — Moodle core callbacks (`googlemeet_add_instance`, `_update_instance`, `_delete_instance`, `_supports`, etc.). On add/update, calls `client::create_meeting_event()` to create the Google Calendar event + Meet room.
- `locallib.php` — view helpers: `googlemeet_handle_view_actions` (logout/sync dispatch, POST-gated), `googlemeet_print_recordings`, `googlemeet_get_upcoming_events`, holiday/cancelled-date helpers, event construction for recurring schedules.
- `callback.php` — OAuth2 redirect target. Closes popup and reloads parent.

### `classes/` (namespace `mod_googlemeet`)

- **`client`** — OAuth2 wrapper around `\core\oauth2\api`. Scopes: Drive + Calendar events. Key methods: `create_meeting_event()`, `syncrecordings()`, `check_login()`, `logout()`. Sync localises the Drive "Meet Recordings" folder name (English + Spanish "Registros de reuniones") and filters by meeting code (chars 24–36 of the Meet URL) plus activity name plus the optional user-defined `recordingfilter`.
- **`gemini_client`** — Google Gemini REST client. Supports text prompts and File API uploads (for full-video analysis). API key is sent in the `x-goog-api-key` request header (never in the URL/query string). **Auto-fallback**: the attempt chain `[primary, fallback]` is built by the `model_policy` value object (see below); if the configured primary model fails it retries once with `FALLBACK_MODEL` (`gemini-2.5-flash`). If already on the fallback, throws. `DEFAULT_MODEL` is `gemini-3.1-flash-preview`.
- **`model_policy`** (`classes/model_policy.php`) — small immutable value object encapsulating the model/fallback policy; given a primary and fallback model it exposes the de-duplicated ordered attempt chain used by `gemini_client`.
- **`subtitle_extractor`** — Uses `yt-dlp` (expected at `/tmp/yt-dlp`) to discover the Google Drive `timedtext` URL for a recording's auto-generated captions, then downloads and parses the subtitle XML. Avoids downloading the full video.
- **`ai_service`** — Higher-level orchestrator used by `external.php` and tasks; combines the Gemini client with DB persistence to `googlemeet_ai_analysis`.
- **`external.php`** (legacy non-namespaced class `mod_googlemeet_external`) — AJAX/WS endpoints: rename/show-hide/delete recordings, generate/get/save/analyze AI analysis. (The `mod_googlemeet_sync_recordings` WS was removed as dead code — sync is now triggered by `view.php?sync=1` → the global `sync_recordings()` in `lib.php`, dispatched via `googlemeet_handle_view_actions()` in `locallib.php`, which requires POST.)
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
- `googlemeet_events` — concrete generated sessions (timestamps). Also holds `autosynced`, plus `syncattempts` and `nextsyncattempt` (auto-sync retry bookkeeping used by the `process_autosync` task with a cron lock; added in 2026053000 — the task referenced these columns before the schema existed and crashed without them).
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
- **Gemini model fallback is visible.** The attempt chain is owned by `model_policy`; when `gemini_client` falls back from the configured primary to `FALLBACK_MODEL` (`gemini-2.5-flash`) it emits an `mtrace()` message (visible in cron/task output). `DEFAULT_MODEL` is `gemini-3.1-flash-preview`, and the `googlemeet/aimodel` setting's option list is kept in sync with the code constants.
- **`subtitle_extractor` is hard-coded to look for `/tmp/yt-dlp`.** Missing binary → tier-2 of the analysis pipeline silently skips and tier-3 (full video upload) runs instead.
- **Moodle 4.2+ namespaced externals**: `external.php` uses `core_external\external_api`. Older Moodle versions expose the same classes unnamespaced — do not add a compatibility shim unless explicitly asked; the plugin's minimum (`$plugin->requires = 2022041900`, Moodle 4.0) already covers this for supported versions.
- **Do not call `googlemeet_print_heading` or `googlemeet_print_intro` from `view.php`.** Moodle 4.0+ activity header renders them automatically; calling them duplicates the title/description (see comment in `view.php`).
- **`makerecordingspublic` default (1) is not persisted until Settings are saved.** This setting controls whether synced recordings are shared "anyone with the link" on Drive (required so enrolled students, who aren't the Drive owner, can play recordings). After deploy, run `set_config('makerecordingspublic', 1, 'googlemeet')` (or open & save the plugin settings) — otherwise the unsaved-default behaviour breaks student playback.
- **`subtitlelanguage` setting (default `es`)** sets the language code used for Drive auto-caption extraction; the CLI `--language`/`-l` flag overrides it (priority: CLI > setting > `es`).
- **Backup/restore now includes `googlemeet_ai_analysis` and `autosynchours`.** Earlier versions dropped AI analysis on backup/restore — verify both survive a course duplicate when touching `backup/moodle2/`.
- **Privacy provider coverage is broad.** `classes/privacy/provider.php` declares the `core_oauth2` subsystem link, the `creatoremail` activity field, the `googlemeet_ai_analysis` table, and external-location links for Google Drive / Calendar / Gemini (it previously only covered `notify_done`). Adding any new personal-data field or external call requires updating it.
