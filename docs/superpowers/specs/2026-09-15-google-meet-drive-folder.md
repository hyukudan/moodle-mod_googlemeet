# Design: adapt recording sync to Google's new "Google Meet" Drive folder

- **Date:** 2026-09-15
- **Plugin:** `mod_googlemeet`
- **Status:** approved (plan), in implementation
- **Planned release:** 2.26.0

## Problem

Google reorganised how Google Meet stores meeting artifacts in Drive
([official announcement](https://workspaceupdates.googleblog.com/2026/07/google-meet-now-organizes-your-meeting-notes-transcripts-and-recordings-in-your-Google-Drive.html),
full rollout for all Workspace customers on 2026-07-22 / 2026-07-30):

- Recordings, transcripts and meeting notes are now uploaded to a new folder named
  **"Google Meet"** in the host's My Drive, organised into **one subfolder per meeting**
  (recurring meetings share a single subfolder).
- The previous "Meet Recordings" folder is **moved inside "Google Meet" and renamed to
  "Legacy Meet Recordings"**.
- Attendees with access see **shortcuts** to the source files in their own "Google Meet"
  folder (`mimeType = application/vnd.google-apps.shortcut`).

The plugin's folder discovery did not survive this change:

- `client::get_meet_recordings_parents_query()` matched folders by exact name
  (`name = "Meet Recordings"`), so the renamed "Legacy Meet Recordings" folder is no longer
  found, and the new "Google Meet" folder was never searched for.
- The recordings query filters with `parents="<folder id>"`, which in the Drive API only
  matches **direct children**. New recordings live one level deeper
  (`Google Meet/<meeting subfolder>/<video>.mp4`) and would be missed even if the root
  folder were found.
- Today syncing only keeps working because of the pre-existing fallback that searches **all
  of Drive** when no folder is found (`classes/client.php`). That fallback is slower and
  less precise (it scans the host's entire Drive and relies solely on name matching).
- `client::find_transcript_for_recording()` uses the same parent clause and suffers the
  same problem. `client::find_notes_for_recording()` already searches all of Drive and is
  unaffected.

## Objective

Restore precise folder-scoped discovery for both the **new topology**
(`Google Meet/<meeting subfolder>/`) and the **legacy topology** (flat `Meet Recordings`,
now renamed and nested), while keeping the all-Drive fallback as last resort.

## Chosen approach: recursive folder discovery (Option A)

1. **Find root folders by name:**
   `(name = "Google Meet" or name contains "Meet Recordings" or name contains "Registros de reuniones")
   and trashed = false and mimeType = "application/vnd.google-apps.folder" and "me" in owners`
   - `name contains "Meet Recordings"` deliberately covers both "Meet Recordings" and the
     renamed "Legacy Meet Recordings".
   - "Registros de reuniones" covers the Spanish localisation of the legacy folder name
     (Google localises the auto-created folder name to the account language).
2. **Enumerate subfolders** breadth-first, max depth 2 (root -> meeting subfolder -> files),
   capped at a constant total number of folders with a `debugging()` notice when the cap is
   hit.
3. **Build the `parents` clause in chunks** (constant chunk size, e.g. 50 folder ids per
   chunk) to stay well under the practical length limit of the Drive `q` parameter; run one
   `files.list` per chunk and merge the results.
4. If **no folder is found at all**, keep the current all-Drive fallback unchanged.
5. Attendee **shortcuts are never followed**: the plugin only syncs while impersonating the
   host (`creatoremail`), and the `"me" in owners` + `mimeType = "video/mp4"` filters
   naturally exclude shortcut files.

### Rejected alternatives

- **Option B (drop folder scoping, always search all of Drive):** far simpler, but slower
  on large Drives and more false positives; loses the precision the folder scope provides.
- **Option C (hybrid: new folder only, else fallback):** misses recordings during the
  transition period where both folder layouts coexist.

## Design decisions

- **No schema, language-pack or scheduled-task changes.** No new UI. `debugging()`
  messages in English, matching the surrounding code style.
- New class constants in `classes/client.php`:
  `DRIVE_MEET_FOLDER_MAX_DEPTH` (2), `DRIVE_MEET_FOLDERS_MAX` (500),
  `DRIVE_PARENTS_CHUNK_SIZE` (50).
- New private methods:
  - `get_meet_recordings_folder_ids($service): array` — root discovery + BFS of
    subfolders; returns flat list of folder ids (roots + descendants).
  - `build_parents_query_chunks(array $folderids): array` — escaped OR fragments of
    `parents="<id>"`, chunk-sized.
  - `list_files_with_parent_chunks($service, $folderids, $qrest, ...)` — one `files.list`
    per chunk, merged results (used by the recordings query).
- `get_meet_recordings_parents_query()` is removed once nothing calls it.
- `find_transcript_for_recording()` receives the parent chunks and tries each chunk until
  files are found; with no folders it keeps today's all-Drive search.
- `find_notes_for_recording()`: the unused `$parents` parameter is dropped (private method,
  zero behavioural change).
- **Cost:** 1-2 extra `files.list` calls per sync (folder listing) versus the all-Drive
  fallback — negligible.
- **Permissions/public sharing, `sync_recordings()` upsert, soft-delete and enrichment
  pipeline are untouched.** Security posture unchanged: same capabilities, same
  `"me" in owners`, same endpoint allowlist in `classes/rest.php` (read-only Drive access
  plus `create_permission`).

## Edge cases

- **Transition period:** both "Google Meet" (with new subfolders) and "Legacy Meet
  Recordings" can coexist; both are discovered and both contribute to the parent clause.
  Duplicate folder ids are not expected (different names/ids), but the merge is idempotent
  because Drive file ids are unique.
- **User-created folders** whose name contains "Meet Recordings" may be picked up — same
  behaviour as the old `name contains "Registros de reuniones"` clause, accepted risk;
  name-based matching in `filter_recordings_for_activity()` remains the final guard.
- **Localised root name:** we assume Google keeps "Google Meet" as a brand name in all
  languages. If it is localised, the root-name list must be extended (single-line change).
- **Very active hosts** (>500 meeting folders): the cap stops the BFS with a
  `debugging()` notice; recordings below the cap are still found precisely, and the
  all-Drive name filter remains as an implicit safety net for the rest.
- **Depth beyond 2** (a meeting subfolder containing nested folders) is intentionally not
  traversed; Google documents a flat per-meeting subfolder layout.

## Out of scope (YAGNI)

- Following/creating shortcuts, or syncing as an attendee.
- Any Drive write operations (move/rename/create folders) — `classes/rest.php` stays
  read-only plus `create_permission`.
- Admin setting to override the folder names.
- Recursive discovery deeper than depth 2.

## Testing

- Unit tests in `tests/client_test.php` using the existing fake Drive service:
  - Root discovery finds "Google Meet" and "Legacy Meet Recordings".
  - BFS collects root + meeting subfolder ids and respects the depth/folder caps.
  - Parent chunks are built at the configured size with proper escaping.
  - A simulated sync over the new topology includes parents from both levels.
- Manual verification on a test Moodle with a real Drive account that has the new folder
  layout: the sync button picks up recordings stored in `Google Meet/<subfolder>/` and in
  "Legacy Meet Recordings".
