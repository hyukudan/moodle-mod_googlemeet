# mod_googlemeet Recordings UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the silent "missing content" problem and redesign how recorded classes are listed in `mod_googlemeet` — from a flat row list into an honest, scannable, card-based study index with search, topic filters and date grouping.

**Architecture:** Three shippable phases on top of one already-completed fix (Gemini model + processing-message correction, Phase 0). Pure data-prep logic goes into small, unit-tested helper functions in `lib.php`; presentation lives in `templates/recordingstable.mustache` + `styles.css`; the orchestrator `googlemeet_print_recordings()` in `locallib.php` wires search/filter/pagination. The per-recording hub (`recording_hub.mustache`) is left as the single destination for full viewing/study — the in-list expandable AI panel is reduced to a link into it for students.

**Tech Stack:** Moodle 5.1 plugin — PHP 8.x, Mustache templates, plain CSS (Bootstrap 5 utilities), inline jQuery AMD (`core/ajax`), PHPUnit. No build step.

**Key constraints (read before any task):**
- **Deploy model:** repo is `~/desarrollo/moodle-mod_googlemeet`; prod is a *flat copy* at `~/formacion51/public/mod/googlemeet` (no `.git`). CLI lives OUTSIDE `public/` at `~/formacion51/admin/cli`. Deploy = rsync → `php admin/cli/upgrade.php` → `purge_caches` → reset web opcache via a temp script in `public/` (`opcache.validate_timestamps=0`).
- **`hasai` is load-bearing in the template AND its inline JS.** Keep `hasai` meaning "completed analysis with content". New status info goes in *new* fields, never by repurposing `hasai`.
- **The inline JS in `recordingstable.mustache` binds to concrete selectors.** Any markup change MUST preserve (or migrate in the same commit) all of these: `.googlemeet-recording-card[data-recordingid]`, `.googlemeet-ai-content[data-recordingid]`, `.googlemeet-ai-panel[data-recordingid]`, `.googlemeet-ai-preview[data-recordingid]`, `.googlemeet-ai-generate-btn[data-id]`, `.googlemeet-ai-toggle-btn[data-id][data-hasai]`, `.googlemeet-ai-edit-btn[data-id][data-recordingname]`, `.googlemeet-ai-copy-btn[data-id]`, `.googlemeet-ai-refresh-btn`, `.recordingeditname[data-id]`, `.recordinghowhide[data-id]`, `.googlemeet-ai-loading`, `.googlemeet-ai-result`, `.googlemeet-ai-error`, `.googlemeet-ai-nodata`, `.googlemeet-ai-processing`, `.googlemeet-ai-transcript-text[data-recordingid]`, `.googlemeet-ai-keypoints-list`, `.googlemeet-ai-topics-tags`, `.googlemeet-ai-summary-text`.
- **Tests on this box:** `init.php` fails under PHP 8.5/composer. Rebuild the PHPUnit DB with `php admin/tool/phpunit/cli/util.php --drop` then `--install`; generate the `en_AU.UTF-8` locale (localedef into `~/.locales`, export `LOCPATH`). Run: `vendor/bin/phpunit mod/googlemeet/tests/locallib_test.php --filter <name>` from the Moodle root that hosts the plugin.

---

## File Structure

| File | Responsibility | Phases |
|------|----------------|--------|
| `version.php` | Version bump per phase deploy | all |
| `lib.php` | NEW pure helpers (`googlemeet_truncate_summary`, `googlemeet_ai_status_flags`, `googlemeet_recording_date_group`, `googlemeet_recording_is_new`, `googlemeet_filter_recordings_by_query`, `googlemeet_filter_recordings_by_topic`, `googlemeet_collect_topics`) + rework of `googlemeet_list_recordings()` | 1, 3 |
| `locallib.php` | `googlemeet_print_recordings()` — wire status/date fields (1), search+topic filter-before-paginate (3); `googlemeet_print_recording_hub()` — surface non-completed status (1) | 1, 3 |
| `view.php` | Read new GET params `rq`, `topic`; pass through | 3 |
| `templates/recordingstable.mustache` | List markup: status chips (1), card layout + centered play (2), search/filter/date-group controls (3) | 1, 2, 3 |
| `templates/recording_hub.mustache` | Summary tab shows processing/failed states | 1 |
| `styles.css` | Status chips (1), card grid + media zone + a11y focus (2), filter bar (3) | 1, 2, 3 |
| `lang/en/googlemeet.php`, `lang/es/googlemeet.php` | New strings (status labels, "Ver clase", "Resumen IA", "nuevo", search/filter labels) | 1, 2, 3 |
| `tests/locallib_test.php` | Unit tests for every new pure helper | 1, 3 |

---

## Phase 0 — Deploy the completed fix (model + processing message)

> These code/config changes are **already made in the repo** (`gemini_client.php`, `settings.php`, `lang/en`, `lang/es`) and the runtime `aimodel` config is already set in prod. This phase only DEPLOYS them and verifies. No TDD (config + string + constant changes).

### Task 0.1: Bump version and deploy

**Files:**
- Modify: `version.php`

- [ ] **Step 1: Bump the version**

In `version.php` set:
```php
$plugin->version = 2026060109;
$plugin->release = '2.14.3';
```

- [ ] **Step 2: Syntax-check changed PHP**

Run:
```bash
cd ~/desarrollo/moodle-mod_googlemeet
php -l classes/gemini_client.php && php -l settings.php && php -l lang/es/googlemeet.php && php -l lang/en/googlemeet.php && php -l version.php
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 3: rsync repo → prod (flat copy, no .git/tests)**

Run:
```bash
rsync -a --delete --exclude='.git' --exclude='tests' --exclude='docs' \
  ~/desarrollo/moodle-mod_googlemeet/ ~/formacion51/public/mod/googlemeet/
```

- [ ] **Step 4: Run the plugin upgrade + purge caches**

Run:
```bash
cd ~/formacion51 && php admin/cli/upgrade.php --non-interactive && php admin/cli/purge_caches.php
```
Expected: upgrade reports `mod_googlemeet` upgraded to `2026060109`; purge completes without error.

- [ ] **Step 5: Reset web (FPM) opcache**

Drop a one-line opcache-reset script into the served `public/` dir, hit it over HTTPS, delete it (the box runs `opcache.validate_timestamps=0`, so CLI opcache reset does NOT affect FPM):
```bash
TOK=$(openssl rand -hex 8)
printf '<?php if($_GET["t"]==="%s"){opcache_reset();echo "ok";}' "$TOK" > ~/formacion51/public/oc_$TOK.php
curl -s "https://<SITE>/oc_$TOK.php?t=$TOK" ; echo
rm -f ~/formacion51/public/oc_$TOK.php
```
Expected: `ok`.

- [ ] **Step 6: Verify the deployed message + model are live**

Run:
```bash
cd ~/formacion51 && php -r "define('CLI_SCRIPT',true);require('config.php');
echo get_string('ai_processing_background_hint','googlemeet').\"\n\";
echo 'aimodel='.get_config('googlemeet','aimodel').\"\n\";"
```
Expected: message contains "no se descarga el vídeo"; `aimodel=gemini-3.5-flash`.

- [ ] **Step 7: Commit**

```bash
cd ~/desarrollo/moodle-mod_googlemeet
git add version.php classes/gemini_client.php settings.php lang/en/googlemeet.php lang/es/googlemeet.php
git commit -m "fix(googlemeet): use gemini-3.5-flash, fix dead model + correct transcript-not-video processing message"
```

---

## Phase 1 — Honest AI state (Slice 1)

**Why first:** today's complaint ("faltan cosas") was a `failed` analysis silently shown as "no resumen". This phase surfaces real status and fixes the byte-unsafe truncation. Small, low JS-breakage risk.

### Task 1.1: `googlemeet_truncate_summary()` — multibyte-safe preview

**Files:**
- Modify: `lib.php` (add function near `googlemeet_list_recordings`, ~line 406)
- Test: `tests/locallib_test.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/locallib_test.php` (inside the test class):
```php
public function test_truncate_summary_short_text_unchanged(): void {
    $this->assertSame('hola', googlemeet_truncate_summary('hola', 200));
}

public function test_truncate_summary_adds_ellipsis_when_cut(): void {
    $text = str_repeat('a', 250);
    $out = googlemeet_truncate_summary($text, 200);
    $this->assertSame(201, core_text::strlen($out)); // 200 chars + ellipsis.
    $this->assertStringEndsWith('…', $out);
}

public function test_truncate_summary_does_not_split_multibyte(): void {
    // 201 'é' (2 bytes each in UTF-8). A byte-based substr(0,200) would split the 100th char.
    $text = str_repeat('é', 201);
    $out = googlemeet_truncate_summary($text, 200);
    $this->assertSame(201, core_text::strlen($out));
    // No U+FFFD replacement char from a broken byte sequence.
    $this->assertStringNotContainsString("\xEF\xBF\xBD", $out);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit mod/googlemeet/tests/locallib_test.php --filter test_truncate_summary`
Expected: FAIL — `Call to undefined function ...googlemeet_truncate_summary()`.

- [ ] **Step 3: Implement the helper**

Add to `lib.php` after `googlemeet_count_recordings()`:
```php
/**
 * Multibyte-safe truncation for AI summary previews.
 *
 * @param string $text The full summary text.
 * @param int $length Maximum length in characters.
 * @return string Truncated text with an ellipsis if it was cut.
 */
function googlemeet_truncate_summary(string $text, int $length = 200): string {
    if (core_text::strlen($text) <= $length) {
        return $text;
    }
    return core_text::substr($text, 0, $length) . '…';
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit mod/googlemeet/tests/locallib_test.php --filter test_truncate_summary`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add lib.php tests/locallib_test.php
git commit -m "feat(googlemeet): mb-safe googlemeet_truncate_summary helper"
```

### Task 1.2: `googlemeet_ai_status_flags()` — status → template booleans

**Files:**
- Modify: `lib.php`
- Test: `tests/locallib_test.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_ai_status_flags_completed(): void {
    $f = googlemeet_ai_status_flags('completed');
    $this->assertSame('completed', $f['aistatus']);
    $this->assertTrue($f['hasanalysisrow']);
    $this->assertTrue($f['aistatusiscompleted']);
    $this->assertFalse($f['aistatusisfailed']);
}

public function test_ai_status_flags_failed(): void {
    $f = googlemeet_ai_status_flags('failed');
    $this->assertTrue($f['aistatusisfailed']);
    $this->assertFalse($f['aistatusiscompleted']);
}

public function test_ai_status_flags_null_means_no_row(): void {
    $f = googlemeet_ai_status_flags(null);
    $this->assertFalse($f['hasanalysisrow']);
    $this->assertSame('', $f['aistatus']);
}

public function test_ai_status_flags_unknown_treated_as_no_row(): void {
    $f = googlemeet_ai_status_flags('banana');
    $this->assertFalse($f['hasanalysisrow']);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit mod/googlemeet/tests/locallib_test.php --filter test_ai_status_flags`
Expected: FAIL — undefined function.

- [ ] **Step 3: Implement**

Add to `lib.php`:
```php
/**
 * Build template booleans for an AI analysis status.
 *
 * @param string|null $status One of pending|processing|completed|failed, or null when no row exists.
 * @return array Associative array of status flags.
 */
function googlemeet_ai_status_flags(?string $status): array {
    $valid = ['pending', 'processing', 'completed', 'failed'];
    $status = in_array($status, $valid, true) ? $status : null;
    return [
        'aistatus' => $status ?? '',
        'hasanalysisrow' => $status !== null,
        'aistatusiscompleted' => $status === 'completed',
        'aistatusisprocessing' => $status === 'processing',
        'aistatusispending' => $status === 'pending',
        'aistatusisfailed' => $status === 'failed',
    ];
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit mod/googlemeet/tests/locallib_test.php --filter test_ai_status_flags`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add lib.php tests/locallib_test.php
git commit -m "feat(googlemeet): googlemeet_ai_status_flags helper"
```

### Task 1.3: Rework `googlemeet_list_recordings()` to fetch ALL statuses

**Files:**
- Modify: `lib.php:341-405`

- [ ] **Step 1: Replace the AI query + enrichment block**

In `googlemeet_list_recordings()` change the SQL (remove the `status = 'completed'` filter) and enrichment loop. Replace lines `363-399` with:
```php
        $sql = "SELECT recordingid, summary, keypoints, topics, status, error, aimodel, timemodified, retrycount, nextretry
                FROM {googlemeet_ai_analysis}
                WHERE recordingid $insql";
        $airecords = $DB->get_records_sql($sql, $inparams);
        foreach ($airecords as $ai) {
            $aidata[$ai->recordingid] = $ai;
        }
    }

    $formattedrecordings = [];
    foreach ($recordings as $recording) {
        $recording->createdtimeformatted = userdate($recording->createdtime);

        $ai = $aidata[$recording->id] ?? null;
        $status = $ai->status ?? null;
        $flags = googlemeet_ai_status_flags($status);
        foreach ($flags as $key => $value) {
            $recording->$key = $value;
        }

        // hasai keeps its existing meaning: a COMPLETED analysis with content.
        $hascontent = $ai && $status === 'completed'
            && (trim((string)$ai->summary) !== '' || ($ai->keypoints ?? '') !== '' || ($ai->topics ?? '') !== '');
        if ($hascontent) {
            $recording->hasai = true;
            $recording->aisummary = $ai->summary;
            $recording->aisummarypreview = googlemeet_truncate_summary((string)$ai->summary, 200);
            $recording->aikeypoints = json_decode($ai->keypoints) ?: [];
            $recording->aikeypointscount = count($recording->aikeypoints);
            $recording->aitopics = json_decode($ai->topics) ?: [];
            $recording->aimodel = $ai->aimodel;
            $recording->aidate = userdate($ai->timemodified, get_string('strftimedmy', 'googlemeet'));
        } else {
            $recording->hasai = false;
            $recording->aisummary = '';
            $recording->aisummarypreview = '';
            $recording->aikeypoints = [];
            $recording->aikeypointscount = 0;
            $recording->aitopics = [];
            $recording->aimodel = '';
            $recording->aidate = '';
        }

        $formattedrecordings[] = $recording;
    }

    return $formattedrecordings;
```

- [ ] **Step 2: Syntax-check**

Run: `php -l lib.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Smoke-test against prod data (read-only)**

Run:
```bash
cd ~/formacion51 && php -r "define('CLI_SCRIPT',true);require('config.php');require_once('public/mod/googlemeet/lib.php');
\$r = googlemeet_list_recordings(['googlemeetid'=>6], true, 'DESC', 5, 0);
foreach(\$r as \$x){ printf(\"%s | hasai=%d status=%s\n\", substr(\$x->name,0,40), \$x->hasai, \$x->aistatus); }"
```
NOTE: run against the repo file only after Step 5 deploy; for local verification point the require at the repo copy. Expected: rows print, a `failed` row shows `hasai=0 status=failed` (not silently blank).

- [ ] **Step 4: Commit**

```bash
git add lib.php
git commit -m "feat(googlemeet): list recordings expose real AI status (not only completed)"
```

### Task 1.4: Add status-chip lang strings

**Files:**
- Modify: `lang/en/googlemeet.php`, `lang/es/googlemeet.php`

- [ ] **Step 1: Add EN strings**

Append to `lang/en/googlemeet.php`:
```php
$string['ai_status_chip_processing'] = 'Summary in progress';
$string['ai_status_chip_pending'] = 'Summary queued';
$string['ai_status_chip_failed_student'] = 'Summary not available yet';
$string['ai_status_chip_failed_teacher'] = 'Summary generation failed';
$string['ai_badge_label'] = 'AI summary';
```

- [ ] **Step 2: Add ES strings**

Append to `lang/es/googlemeet.php`:
```php
$string['ai_status_chip_processing'] = 'Resumen en proceso';
$string['ai_status_chip_pending'] = 'Resumen en cola';
$string['ai_status_chip_failed_student'] = 'Resumen no disponible todavía';
$string['ai_status_chip_failed_teacher'] = 'Error al generar el resumen';
$string['ai_badge_label'] = 'Resumen IA';
```

- [ ] **Step 3: Commit**

```bash
git add lang/en/googlemeet.php lang/es/googlemeet.php
git commit -m "feat(googlemeet): status chip + AI summary lang strings"
```

### Task 1.5: Render status chips in the list (preserve all JS hooks)

**Files:**
- Modify: `templates/recordingstable.mustache` (meta block ~line 122-130)

- [ ] **Step 1: Add the chip block inside `.googlemeet-recording-meta`**

After the `googlemeet-recording-duration` span (line ~124), add:
```html
                {{^hasai}}
                  {{#aistatusisprocessing}}<span class="googlemeet-ai-statuschip googlemeet-ai-statuschip-processing">{{# str }} ai_status_chip_processing, mod_googlemeet {{/ str }}</span>{{/aistatusisprocessing}}
                  {{#aistatusispending}}<span class="googlemeet-ai-statuschip googlemeet-ai-statuschip-pending">{{# str }} ai_status_chip_pending, mod_googlemeet {{/ str }}</span>{{/aistatusispending}}
                  {{#aistatusisfailed}}
                    {{#hascapability}}<span class="googlemeet-ai-statuschip googlemeet-ai-statuschip-failed">{{# str }} ai_status_chip_failed_teacher, mod_googlemeet {{/ str }}</span>{{/hascapability}}
                    {{^hascapability}}<span class="googlemeet-ai-statuschip googlemeet-ai-statuschip-muted">{{# str }} ai_status_chip_failed_student, mod_googlemeet {{/ str }}</span>{{/hascapability}}
                  {{/aistatusisfailed}}
                {{/hasai}}
```

- [ ] **Step 2: Rename the visible "AI" badge text to the localized label**

At line ~113, change the hard-coded `AI` text inside `.googlemeet-ai-badge` to:
```html
                    {{# str }} ai_badge_label, mod_googlemeet {{/ str }}
```
(Keep the SVG and the surrounding span/attributes untouched.)

- [ ] **Step 3: Commit**

```bash
git add templates/recordingstable.mustache
git commit -m "feat(googlemeet): show AI status chips + localized AI badge in list"
```

### Task 1.6: Style the status chips

**Files:**
- Modify: `styles.css`

- [ ] **Step 1: Add chip styles (near the AI preview block ~line 934)**

```css
.googlemeet-ai-statuschip {
    display: inline-block;
    font-size: 0.75rem;
    line-height: 1.4;
    padding: 0.1rem 0.5rem;
    border-radius: 999px;
    border: 1px solid transparent;
}
.googlemeet-ai-statuschip-processing,
.googlemeet-ai-statuschip-pending {
    background: #e8f0fe;
    color: #1a56c4;
    border-color: #c6dafc;
}
.googlemeet-ai-statuschip-failed {
    background: #fce8e6;
    color: #b3261e;
    border-color: #f6c7c2;
}
.googlemeet-ai-statuschip-muted {
    background: #f1f3f4;
    color: #5f6368;
}
.dark .googlemeet-ai-statuschip-processing,
.dark .googlemeet-ai-statuschip-pending { background: #1f2a3d; color: #aac6ff; border-color: #2c3e5c; }
.dark .googlemeet-ai-statuschip-failed { background: #3b1f1d; color: #f3aaa3; border-color: #5c2c28; }
.dark .googlemeet-ai-statuschip-muted { background: #2a2c2e; color: #c4c7c5; }
```

- [ ] **Step 2: Commit**

```bash
git add styles.css
git commit -m "style(googlemeet): AI status chip styling (light + dark)"
```

### Task 1.7: Surface non-completed status in the hub Summary tab

**Files:**
- Modify: `locallib.php` `googlemeet_print_recording_hub()` (~line 586-633)
- Modify: `templates/recording_hub.mustache` (Summary tab ~line 89-92)

- [ ] **Step 1: Fetch the analysis regardless of status, expose status flags**

In `googlemeet_print_recording_hub()` change line 586 to drop the status filter and compute flags:
```php
    $analysis = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recording->id]);
    $analysiscompleted = $analysis && $analysis->status === 'completed';
    $statusflags = googlemeet_ai_status_flags($analysis->status ?? null);
```
Then below, gate the content on `$analysiscompleted` instead of `$analysis`:
```php
    $keypoints = [];
    $topics = [];
    if ($analysiscompleted) {
        $keypoints = json_decode($analysis->keypoints) ?: [];
        $topics = json_decode($analysis->topics) ?: [];
    }
```
And in the `$hasstudentsummary` line use `$analysiscompleted`:
```php
    $hasstudentsummary = $analysiscompleted && (trim((string)$analysis->summary) !== '' || !empty($keypoints) || !empty($topics));
```
In the `$templatecontext` array, set `'hasanalysis' => $analysiscompleted,` and merge the flags + a teacher error string:
```php
        'aistatusisprocessing' => $statusflags['aistatusisprocessing'],
        'aistatusispending' => $statusflags['aistatusispending'],
        'aistatusisfailed' => $statusflags['aistatusisfailed'],
        'aierror' => ($caneditrecording && $analysis && !empty($analysis->error)) ? s($analysis->error) : '',
```
(Leave `'summary'`, `'keypoints'`, `'topics'`, `'transcript'` guarded by `$analysiscompleted` — change the ternaries from `$analysis ?` to `$analysiscompleted ?`.)

- [ ] **Step 2: Show the status in the Summary tab**

In `recording_hub.mustache`, replace the `{{^hasanalysis}}` block (lines ~89-92) with:
```html
      {{^hasanalysis}}
        {{#aistatusisprocessing}}<div class="alert alert-info">{{# str }} ai_status_chip_processing, mod_googlemeet {{/ str }}</div>{{/aistatusisprocessing}}
        {{#aistatusispending}}<div class="alert alert-info">{{# str }} ai_status_chip_pending, mod_googlemeet {{/ str }}</div>{{/aistatusispending}}
        {{#aistatusisfailed}}<div class="alert alert-warning">{{# str }} ai_status_chip_failed_student, mod_googlemeet {{/ str }}{{#aierror}}<div class="small text-muted mt-1">{{aierror}}</div>{{/aierror}}</div>{{/aistatusisfailed}}
        {{^aistatusisprocessing}}{{^aistatusispending}}{{^aistatusisfailed}}
          {{#aienabled}}<div class="alert alert-info">{{# str }} ai_noanalysis, mod_googlemeet {{/ str }}</div>{{/aienabled}}
          {{^aienabled}}<div class="alert alert-secondary">{{# str }} ai_notconfigured, mod_googlemeet {{/ str }}</div>{{/aienabled}}
        {{/aistatusisfailed}}{{/aistatusispending}}{{/aistatusisprocessing}}
      {{/hasanalysis}}
```

- [ ] **Step 3: Syntax-check + commit**

Run: `php -l locallib.php`
```bash
git add locallib.php templates/recording_hub.mustache
git commit -m "feat(googlemeet): hub summary tab shows processing/pending/failed AI state"
```

### Task 1.8: Deploy + verify Phase 1

- [ ] **Step 1: Bump version** in `version.php` to `2026060110` / release `2.14.4`.
- [ ] **Step 2: Run the full local test file**

Run: `vendor/bin/phpunit mod/googlemeet/tests/locallib_test.php`
Expected: all PASS (existing + new helpers).

- [ ] **Step 3: Deploy** (repeat Phase 0 Task 0.1 Steps 3-5: rsync, upgrade, purge, opcache reset).
- [ ] **Step 4: Manual verification** — Use the `verify` skill / Playwright: open the casos-prácticos activity as a student; confirm a recording whose analysis is `failed`/`processing` shows the soft chip (not blank), and a teacher sees the error/regenerate path. Take a screenshot.
- [ ] **Step 5: Commit the version bump**

```bash
git add version.php
git commit -m "chore(googlemeet): v2026060110 — honest AI state (Phase 1)"
```

---

## Phase 2 — Card layout with centered play (Slice 2, Direction B refined)

**Decision (user + Codex follow-up):** responsive **card grid** with a **compact ~88px media strip** (72px mobile) — NOT a full 16:9 zone. The strip holds a centered/left play button, a **prominent date anchor** ("14 may"), the duration chip, and "Nuevo"/"Resumen IA" badges, over a branded gradient. Body uses **CSS line-clamp**. This keeps the owner's centered-play card while answering Codex's objection that a big gradient with no thumbnail is decorative noise and hurts date-scanning. Grid is **2 columns by default**, 3 only at ≥1280px, 1 on mobile. Body is ordered for **recognition first**. A real 16:9 thumbnail (Drive `thumbnailLink`) is a future enhancement that can later replace the strip (see Backlog).

> Hard precondition: the JS-selector inventory in "Key constraints" governs this phase. Reuse the existing class names on the same elements; only the *visual container* changes.

### Task 2.1: Add card + "Ver clase" / "nuevo" lang strings

**Files:** `lang/en/googlemeet.php`, `lang/es/googlemeet.php`

- [ ] **Step 1: EN**
```php
$string['recording_watch_cta'] = 'Watch class';
$string['recording_new'] = 'New';
$string['recording_view_summary'] = 'View summary';
$string['recording_play_aria'] = 'Play class';
```
- [ ] **Step 2: ES**
```php
$string['recording_watch_cta'] = 'Ver clase';
$string['recording_new'] = 'Nuevo';
$string['recording_view_summary'] = 'Ver resumen';
$string['recording_play_aria'] = 'Reproducir clase';
```
- [ ] **Step 3: Commit**
```bash
git add lang/en/googlemeet.php lang/es/googlemeet.php
git commit -m "feat(googlemeet): card CTA + new badge lang strings"
```

### Task 2.2: `googlemeet_recording_is_new()` + `googlemeet_recording_date_group()`

**Files:** `lib.php`, `tests/locallib_test.php`

- [ ] **Step 1: Write failing tests**
```php
public function test_recording_is_new_within_threshold(): void {
    $now = mktime(12, 0, 0, 6, 10, 2026);
    $created = mktime(12, 0, 0, 6, 8, 2026); // 2 days earlier.
    $this->assertTrue(googlemeet_recording_is_new($created, $now, 7));
}
public function test_recording_is_new_outside_threshold(): void {
    $now = mktime(12, 0, 0, 6, 10, 2026);
    $created = mktime(12, 0, 0, 5, 20, 2026); // ~21 days earlier.
    $this->assertFalse(googlemeet_recording_is_new($created, $now, 7));
}
public function test_recording_date_group_is_month_year(): void {
    $ts = mktime(12, 0, 0, 5, 27, 2026);
    $out = googlemeet_recording_date_group($ts);
    $this->assertNotSame('', $out);
    $this->assertStringContainsString('2026', $out);
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit mod/googlemeet/tests/locallib_test.php --filter "recording_is_new|recording_date_group"`
Expected: FAIL — undefined functions.

- [ ] **Step 3: Implement**
```php
/**
 * Whether a recording counts as "new" relative to a reference time.
 *
 * @param int $createdtime Recording creation time.
 * @param int $now Reference "now" timestamp.
 * @param int $thresholddays Age threshold in days.
 * @return bool
 */
function googlemeet_recording_is_new(int $createdtime, int $now, int $thresholddays = 7): bool {
    return ($now - $createdtime) <= ($thresholddays * DAYSECS);
}

/**
 * Localised month/year heading for grouping recordings, e.g. "mayo 2026".
 *
 * @param int $timestamp Recording creation time.
 * @return string
 */
function googlemeet_recording_date_group(int $timestamp): string {
    return userdate($timestamp, get_string('strftimemonthyear', 'langconfig'));
}
```

- [ ] **Step 4: Run to verify pass** — `--filter "recording_is_new|recording_date_group"` → PASS (3).
- [ ] **Step 5: Commit**
```bash
git add lib.php tests/locallib_test.php
git commit -m "feat(googlemeet): is_new + date_group helpers"
```

### Task 2.3: Pass `isnew`, `createddateshort`, `dategroup`, `showdategroup` to the template

**Files:** `lib.php` (`googlemeet_list_recordings`), `locallib.php` (`googlemeet_print_recordings`)

- [ ] **Step 1: In `googlemeet_list_recordings()` loop, add per-recording fields**

Inside the `foreach ($recordings as $recording)` loop (after `createdtimeformatted`), add:
```php
        $recording->createddateshort = userdate($recording->createdtime, get_string('strftimedm', 'googlemeet'));
        $recording->isnew = googlemeet_recording_is_new((int)$recording->createdtime, time(), 7);
        $recording->dategroup = googlemeet_recording_date_group((int)$recording->createdtime);
```
(If `strftimedm` is not defined in the plugin lang, add `$string['strftimedm'] = '%d %b';` to `lang/en` and `lang/es`.)

- [ ] **Step 2: In `googlemeet_print_recordings()` set `showdategroup` on group boundaries**

After the `foreach ($recordings as $recording)` huburl loop (line ~457) add:
```php
    $prevgroup = null;
    foreach ($recordings as $recording) {
        $recording->showdategroup = ($recording->dategroup !== $prevgroup);
        $prevgroup = $recording->dategroup;
    }
```

- [ ] **Step 3: Syntax-check + commit**

Run: `php -l lib.php && php -l locallib.php`
```bash
git add lib.php locallib.php
git commit -m "feat(googlemeet): expose isnew/short-date/date-group fields to list"
```

### Task 2.4: Rebuild the list markup as a card grid (preserve JS hooks)

**Files:** `templates/recordingstable.mustache` (the `.googlemeet-recordings-list` + `{{#recordings}}` block, lines ~89-288)

- [ ] **Step 1: Replace the card markup**

Replace the recording card (lines ~91-158, the `.googlemeet-recording-card` content row) with the card structure below. **Keep** `data-recordingid` on the card and all action elements with their existing classes/`data-*`. Add a date-group heading before cards:
```html
  <div class="googlemeet-recordings-grid">
    {{#recordings}}
        {{#showdategroup}}<h5 class="googlemeet-recordings-group w-100">{{dategroup}}</h5>{{/showdategroup}}
        <div class="googlemeet-recording-card {{^visible}}googlemeet-recording-hidden{{/visible}} {{#hasai}}googlemeet-recording-has-ai{{/hasai}}" data-recordingid="{{id}}">
          <a href="{{huburl}}" class="googlemeet-card-media" aria-label="{{# str }} recording_play_aria, mod_googlemeet {{/ str }}: {{name}}">
            <span class="googlemeet-card-play" aria-hidden="true">{{# pix }} play, mod_googlemeet {{/ pix }}</span>
            <span class="googlemeet-card-date" aria-hidden="true">{{createddateshort}}</span>
            <span class="googlemeet-card-duration">{{duration}}</span>
            {{#isnew}}<span class="googlemeet-card-newbadge">{{# str }} recording_new, mod_googlemeet {{/ str }}</span>{{/isnew}}
            {{#hasai}}<span class="googlemeet-card-aibadge" title="{{# str }} ai_analysis_available, mod_googlemeet {{/ str }}">{{# str }} ai_badge_label, mod_googlemeet {{/ str }}</span>{{/hasai}}
          </a>
          <div class="googlemeet-card-body">
            <div class="googlemeet-card-title">
              <a href="{{huburl}}" class="recording-name-text">{{name}}</a>
              {{#hascapability}}<a href="javascript:void(0);" class="recordingeditname googlemeet-edit-btn" data-id="{{id}}" title="{{# str }} edit {{/ str }}">{{# pix }} i/edit, core {{/ pix }}</a>{{/hascapability}}
            </div>
            <div class="googlemeet-card-meta">
              <span class="googlemeet-recording-date visually-hidden">{{createdtimeformatted}}</span>
              {{#hasai}}<span class="googlemeet-ai-keypoints-count">{{aikeypointscount}} {{# str }} ai_keypoints_short, mod_googlemeet {{/ str }}</span>{{/hasai}}
              {{^hasai}}
                {{#aistatusisprocessing}}<span class="googlemeet-ai-statuschip googlemeet-ai-statuschip-processing">{{# str }} ai_status_chip_processing, mod_googlemeet {{/ str }}</span>{{/aistatusisprocessing}}
                {{#aistatusispending}}<span class="googlemeet-ai-statuschip googlemeet-ai-statuschip-pending">{{# str }} ai_status_chip_pending, mod_googlemeet {{/ str }}</span>{{/aistatusispending}}
                {{#aistatusisfailed}}
                  {{#hascapability}}<span class="googlemeet-ai-statuschip googlemeet-ai-statuschip-failed">{{# str }} ai_status_chip_failed_teacher, mod_googlemeet {{/ str }}</span>{{/hascapability}}
                  {{^hascapability}}<span class="googlemeet-ai-statuschip googlemeet-ai-statuschip-muted">{{# str }} ai_status_chip_failed_student, mod_googlemeet {{/ str }}</span>{{/hascapability}}
                {{/aistatusisfailed}}
              {{/hasai}}
            </div>
            {{#hasai}}
            <p class="googlemeet-card-summary">{{aisummarypreview}}</p>
            {{#aitopics.0}}<div class="googlemeet-card-topics">{{#aitopics}}<span class="googlemeet-ai-topic-tag">{{.}}</span>{{/aitopics}}</div>{{/aitopics.0}}
            {{/hasai}}
            <div class="googlemeet-card-footer">
              <a href="{{huburl}}" class="btn btn-primary btn-sm googlemeet-card-cta">{{# str }} recording_watch_cta, mod_googlemeet {{/ str }}</a>
              <div class="googlemeet-card-actions googlemeet-recording-actions">
                <a href="{{webviewlink}}" target="_blank" rel="noopener" class="googlemeet-drive-link" title="{{# str }} openindrive, mod_googlemeet {{/ str }}" aria-label="{{# str }} openindrive, mod_googlemeet {{/ str }}: {{name}}">{{# pix }} i/externallink, core {{/ pix }}</a>
                {{#hascapability}}
                  <a href="javascript:void(0);" class="recordinghowhide googlemeet-visibility-btn" data-id="{{id}}" aria-label="{{# str }} showhide, mod_googlemeet {{/ str }}">{{#visible}}{{# pix }} i/hide, core {{/ pix }}{{/visible}}{{^visible}}{{# pix }} i/show, core {{/ pix }}{{/visible}}</a>
                  <a href="javascript:void(0);" class="googlemeet-ai-edit-btn" data-id="{{id}}" data-recordingname="{{name}}" title="{{# str }} ai_edit_manual, mod_googlemeet {{/ str }}">{{# pix }} i/edit, core {{/ pix }}</a>
                {{/hascapability}}
                {{! Student in-list AI panel is removed (Task 2.6); toggle is teacher-only so it never points at a missing panel. }}
                {{#aienabled}}{{#hascapability}}
                  <a href="javascript:void(0);" class="googlemeet-ai-toggle-btn {{#hasai}}googlemeet-ai-toggle-active{{/hasai}}" data-id="{{id}}" data-hasai="{{#hasai}}1{{/hasai}}{{^hasai}}0{{/hasai}}" title="{{# str }} ai_analysis, mod_googlemeet {{/ str }}" aria-expanded="false" aria-controls="googlemeet-ai-panel-{{id}}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="googlemeet-ai-toggle-icon"><path d="M7.41 8.59L12 13.17L16.59 8.59L18 10L12 16L6 10L7.41 8.59Z" fill="currentColor"/></svg>
                  </a>
                {{/hascapability}}{{/aienabled}}
              </div>
            </div>
          </div>
          {{! Keep the expandable AI panel markup that follows (lines ~160-285) UNCHANGED — its IDs/classes are JS-bound. Add id="googlemeet-ai-panel-{{id}}" to the .googlemeet-ai-panel div for aria-controls. }}
```
Then **leave the existing `{{! AI Preview Section}}` and `{{! Expandable AI Panel}}` blocks intact** (lines ~160-285), only adding `id="googlemeet-ai-panel-{{id}}"` to the `.googlemeet-ai-panel` div (line ~181). Close the grid with `</div>` replacing the old `</div>` of `.googlemeet-recordings-list` (line ~288).

- [ ] **Step 2: Verify no JS selector was dropped**

Run:
```bash
cd ~/desarrollo/moodle-mod_googlemeet
for sel in googlemeet-ai-generate-btn googlemeet-ai-toggle-btn googlemeet-ai-edit-btn googlemeet-ai-copy-btn recordingeditname recordinghowhide googlemeet-ai-content googlemeet-ai-panel googlemeet-ai-loading googlemeet-ai-result googlemeet-ai-error googlemeet-ai-nodata; do
  printf '%s: %s\n' "$sel" "$(grep -c "$sel" templates/recordingstable.mustache)"; done
```
Expected: every selector count ≥ 1 (toggle/edit/generate appear in both markup and JS).

- [ ] **Step 3: Commit**
```bash
git add templates/recordingstable.mustache
git commit -m "feat(googlemeet): card-grid list with centered play, date groups, status (JS hooks preserved)"
```

### Task 2.5: Card grid CSS + media zone + a11y focus states

**Files:** `styles.css`

- [ ] **Step 1: Add the card grid + media zone styles**
```css
.googlemeet-recordings-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr)); /* 2 columns default; 3 only on wide desktop. */
    gap: 1rem;
    align-items: stretch;
}
.googlemeet-recordings-group {
    grid-column: 1 / -1;
    margin: 0.5rem 0 0;
    color: #5f6368;
    text-transform: capitalize;
}
.googlemeet-recording-card {
    display: flex;
    flex-direction: column;
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
    transition: box-shadow 0.15s ease, transform 0.15s ease;
}
.googlemeet-recording-card:hover { box-shadow: 0 6px 18px rgba(0,0,0,0.10); transform: translateY(-2px); }
.googlemeet-card-media {
    position: relative;
    display: flex;
    align-items: center;
    height: 88px;            /* Compact strip, NOT 16:9. Date is the anchor. */
    padding: 0 0.75rem;
    /* Branded gradient placeholder (no thumbnail yet — future: Drive thumbnailLink). */
    background: linear-gradient(135deg, #1a73e8 0%, #174ea6 100%);
    color: #fff;
    text-decoration: none;
}
.googlemeet-card-play {
    display: flex; align-items: center; justify-content: center;
    width: 40px; height: 40px; border-radius: 50%;
    background: rgba(255,255,255,0.18);
    flex: 0 0 auto;
}
.googlemeet-card-play .icon { width: 22px; height: 22px; margin: 0; }
.googlemeet-card-date {
    margin-left: 0.6rem;
    font-size: 1.05rem; font-weight: 700; letter-spacing: 0.3px;
    text-transform: capitalize;
}
.googlemeet-card-duration {
    position: absolute; right: 8px; bottom: 6px;
    background: rgba(0,0,0,0.55); color: #fff;
    font-size: 0.72rem; padding: 0.05rem 0.4rem; border-radius: 4px;
}
.googlemeet-card-newbadge {
    position: absolute; left: 8px; top: 8px;
    background: #1e8e3e; color: #fff;
    font-size: 0.7rem; font-weight: 600; padding: 0.1rem 0.5rem; border-radius: 999px;
}
.googlemeet-card-aibadge {
    position: absolute; right: 8px; top: 8px;
    background: rgba(255,255,255,0.92); color: #174ea6;
    font-size: 0.7rem; font-weight: 600; padding: 0.1rem 0.5rem; border-radius: 999px;
}
.googlemeet-card-body { display: flex; flex-direction: column; gap: 0.4rem; padding: 0.75rem; flex: 1 1 auto; }
.googlemeet-card-title a.recording-name-text {
    font-weight: 600; color: inherit; text-decoration: none;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.googlemeet-card-meta { font-size: 0.8rem; color: #5f6368; display: flex; gap: 0.4rem; flex-wrap: wrap; align-items: center; }
.googlemeet-card-summary {
    font-size: 0.85rem; color: #3c4043; margin: 0;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.googlemeet-card-topics { display: flex; flex-wrap: wrap; gap: 0.25rem; }
.googlemeet-card-footer { margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding-top: 0.5rem; }
.googlemeet-card-actions { display: flex; align-items: center; gap: 0.35rem; padding-left: 0; }
/* a11y: visible focus for all interactive list elements. */
.googlemeet-card-media:focus-visible,
.googlemeet-card-cta:focus-visible,
.googlemeet-drive-link:focus-visible,
.googlemeet-ai-toggle-btn:focus-visible,
.recordinghowhide:focus-visible,
.recordingeditname:focus-visible,
.googlemeet-pagination .page-link:focus-visible {
    outline: 3px solid #1a73e8; outline-offset: 2px;
}
@media only screen and (min-width: 1280px) { .googlemeet-recordings-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media only screen and (max-width: 600px) {
    .googlemeet-recordings-grid { grid-template-columns: 1fr; }
    .googlemeet-card-media { height: 72px; }
}
@media (prefers-reduced-motion: reduce) {
    .googlemeet-recording-card { transition: none; }
    .googlemeet-recording-card:hover { transform: none; }
}
/* Quiet topic chips in the card body (metadata, not saturated pills). */
.googlemeet-card-topics .googlemeet-ai-topic-tag {
    background: #f1f3f4; color: #3c4043; border: 1px solid #e0e0e0; font-weight: 500;
}
.dark .googlemeet-recording-card { background: #1f1f1f; border-color: #3c4043; }
.dark .googlemeet-card-summary { color: #c4c7c5; }
.dark .googlemeet-card-topics .googlemeet-ai-topic-tag { background: #2a2c2e; color: #c4c7c5; border-color: #3c4043; }
```

- [ ] **Step 2: Neutralize the old mobile `padding-left: 64px` hack**

At `styles.css:808-810` (inside the `max-width: 767px` block), change the `.googlemeet-recording-actions` rule body to:
```css
        width: auto;
        padding-left: 0;
```

- [ ] **Step 3: Commit**
```bash
git add styles.css
git commit -m "style(googlemeet): responsive card grid, media zone with centered play, focus states"
```

### Task 2.6: Convert student in-list AI panel to a hub link; keep teacher actions

**Files:** `templates/recordingstable.mustache`

- [ ] **Step 1: Gate the heavy in-list AI panel to teachers only**

Wrap the `{{! Expandable AI Panel}}` block (the `.googlemeet-ai-panel` div, ~line 181-284) so students get a lightweight "Ver resumen" link to the hub instead. Inside `{{#aienabled}}`:
```html
          {{^hascapability}}
            {{#hasai}}<a href="{{huburl}}" class="googlemeet-card-summarylink">{{# str }} recording_view_summary, mod_googlemeet {{/ str }} ›</a>{{/hasai}}
          {{/hascapability}}
          {{#hascapability}}
            {{! existing .googlemeet-ai-panel block stays here, unchanged, for teachers }}
          {{/hascapability}}
```
This removes the status-polling/regenerate machinery from the student DOM (less risk, lighter page) while teachers retain generate/regenerate/edit/transcript. The `.googlemeet-ai-toggle-btn` for students can be hidden via `{{#hascapability}}` around it in Task 2.4's action row if desired, OR kept pointing at the (now teacher-only) panel — choose hidden-for-students to avoid a dead toggle.

- [ ] **Step 2: Verify teacher JS still resolves**

Run the selector inventory from Task 2.4 Step 2 again; counts unchanged.

- [ ] **Step 3: Commit**
```bash
git add templates/recordingstable.mustache
git commit -m "feat(googlemeet): students get a hub summary link; in-list AI panel teacher-only"
```

### Task 2.7: Deploy + verify Phase 2

- [ ] **Step 1: Bump version** to `2026060111` / release `2.15.0`.
- [ ] **Step 2: Run full test file** — `vendor/bin/phpunit mod/googlemeet/tests/locallib_test.php` → PASS.
- [ ] **Step 3: Deploy** (rsync, upgrade, purge, opcache reset).
- [ ] **Step 4: Manual verification** — Use `verify`/Playwright at desktop (3-col), tablet (2-col), mobile (1-col). Confirm: centered play works, cards equal height, date-group headings appear, teacher generate/regenerate/edit/hide/copy/toggle still work, student sees "Ver resumen" link. Screenshot each breakpoint.
- [ ] **Step 5: Commit version bump**
```bash
git add version.php
git commit -m "chore(googlemeet): v2026060111 — card-grid recordings UI (Phase 2)"
```

---

## Phase 3 — Findability: search + topic filter + date grouping is live (Slice 3)

Date grouping already shipped in Phase 2. This phase adds server-side **search** (`rq`) and **topic filter** (`topic`), applied **before pagination**.

### Task 3.1: Filter helpers (`by_query`, `by_topic`, `collect_topics`)

**Files:** `lib.php`, `tests/locallib_test.php`

- [ ] **Step 1: Write failing tests**
```php
private function rec(string $name, bool $hasai = false, array $topics = [], string $summary = ''): \stdClass {
    $r = new \stdClass();
    $r->name = $name; $r->hasai = $hasai; $r->aitopics = $topics; $r->aisummary = $summary;
    return $r;
}
public function test_filter_by_query_matches_name_case_insensitive(): void {
    $recs = [$this->rec('Clase de Contratos'), $this->rec('Clase de Personal')];
    $out = googlemeet_filter_recordings_by_query($recs, 'contratos');
    $this->assertCount(1, $out);
    $this->assertSame('Clase de Contratos', $out[0]->name);
}
public function test_filter_by_query_matches_ai_topic(): void {
    $recs = [$this->rec('Sesión 1', true, ['Silencio administrativo'])];
    $out = googlemeet_filter_recordings_by_query($recs, 'silencio');
    $this->assertCount(1, $out);
}
public function test_filter_by_query_empty_returns_all(): void {
    $recs = [$this->rec('a'), $this->rec('b')];
    $this->assertCount(2, googlemeet_filter_recordings_by_query($recs, '  '));
}
public function test_filter_by_topic_exact(): void {
    $recs = [$this->rec('a', true, ['LPAC']), $this->rec('b', true, ['LCSP'])];
    $out = googlemeet_filter_recordings_by_topic($recs, 'LPAC');
    $this->assertCount(1, $out);
    $this->assertSame('a', $out[0]->name);
}
public function test_collect_topics_unique_sorted(): void {
    $recs = [$this->rec('a', true, ['LCSP', 'LPAC']), $this->rec('b', true, ['LPAC'])];
    $this->assertSame(['LCSP', 'LPAC'], googlemeet_collect_topics($recs));
}
```

- [ ] **Step 2: Run to verify failure** — `--filter "filter_by_query|filter_by_topic|collect_topics"` → FAIL.

- [ ] **Step 3: Implement**
```php
/**
 * Filter recordings by a free-text query against name (and AI summary/topics when completed).
 *
 * @param array $recordings Recording stdClass list (post AI-enrichment).
 * @param string $query Raw search query.
 * @return array Filtered recordings, re-indexed.
 */
function googlemeet_filter_recordings_by_query(array $recordings, string $query): array {
    $needle = core_text::strtolower(trim($query));
    if ($needle === '') {
        return $recordings;
    }
    return array_values(array_filter($recordings, static function($r) use ($needle) {
        $haystacks = [(string)($r->name ?? '')];
        if (!empty($r->hasai)) {
            $haystacks[] = (string)($r->aisummary ?? '');
            foreach (($r->aitopics ?? []) as $t) {
                $haystacks[] = (string)$t;
            }
        }
        foreach ($haystacks as $h) {
            if (core_text::strpos(core_text::strtolower($h), $needle) !== false) {
                return true;
            }
        }
        return false;
    }));
}

/**
 * Filter recordings to those tagged with an exact AI topic.
 *
 * @param array $recordings Recording stdClass list.
 * @param string $topic Exact topic text.
 * @return array Filtered recordings, re-indexed.
 */
function googlemeet_filter_recordings_by_topic(array $recordings, string $topic): array {
    $topic = trim($topic);
    if ($topic === '') {
        return $recordings;
    }
    return array_values(array_filter($recordings, static function($r) use ($topic) {
        foreach (($r->aitopics ?? []) as $t) {
            if ((string)$t === $topic) {
                return true;
            }
        }
        return false;
    }));
}

/**
 * Distinct, naturally-sorted list of AI topics across recordings.
 *
 * @param array $recordings Recording stdClass list.
 * @return string[]
 */
function googlemeet_collect_topics(array $recordings): array {
    $seen = [];
    foreach ($recordings as $r) {
        foreach (($r->aitopics ?? []) as $t) {
            $t = (string)$t;
            if ($t !== '') {
                $seen[$t] = true;
            }
        }
    }
    $topics = array_keys($seen);
    sort($topics, SORT_NATURAL | SORT_FLAG_CASE);
    return $topics;
}
```

- [ ] **Step 4: Run to verify pass** → PASS (5).
- [ ] **Step 5: Commit**
```bash
git add lib.php tests/locallib_test.php
git commit -m "feat(googlemeet): search/topic filter + collect_topics helpers"
```

### Task 3.2: Wire filter-before-paginate into `googlemeet_print_recordings()`

**Files:** `view.php`, `locallib.php`

- [ ] **Step 1: Read the new params in `view.php`**

After line 110 (`$recordingsorder = ...`), add:
```php
$recordingquery = optional_param('rq', '', PARAM_TEXT);
$recordingtopic = optional_param('topic', '', PARAM_TEXT);
```
Change the call on line 112 to:
```php
googlemeet_print_recordings($googlemeet, $cm, $context, $recordingspage, $recordingsorder, $recordingquery, $recordingtopic);
```

- [ ] **Step 2: Update the function signature + fetch-all-then-filter logic**

In `locallib.php`, change `function googlemeet_print_recordings($googlemeet, $cm, $context, $page = 0, $orderoverride = null) {` to:
```php
function googlemeet_print_recordings($googlemeet, $cm, $context, $page = 0, $orderoverride = null, $query = '', $topic = '') {
```
Replace the count/fetch block (lines ~440-449) with fetch-all → filter → slice:
```php
    // Fetch ALL recordings (no SQL limit) so search/topic filters apply before pagination.
    $allrecordings = googlemeet_list_recordings($params, $aienabled, $order, 0, 0);

    // Apply filters in PHP (topics are stored as JSON; this stays DB-portable).
    if (trim((string)$query) !== '') {
        $allrecordings = googlemeet_filter_recordings_by_query($allrecordings, (string)$query);
    }
    if (trim((string)$topic) !== '') {
        $allrecordings = googlemeet_filter_recordings_by_topic($allrecordings, (string)$topic);
    }
    $alltopics = googlemeet_collect_topics(googlemeet_list_recordings($params, $aienabled, $order, 0, 0));

    $totalrecordings = count($allrecordings);
    $totalpages = max(1, (int)ceil($totalrecordings / $maxrecordings));
    $page = min($page, max(0, $totalpages - 1));
    $offset = $page * $maxrecordings;
    $recordings = array_slice($allrecordings, $offset, $maxrecordings);
```
> Note for modest recording counts per activity (tens). If an activity ever holds hundreds of recordings, replace with a normalized topic index; `log()` nothing is truncated here because we paginate the *filtered* array in full.

- [ ] **Step 3: Build topic-filter context + preserve params in URLs**

After the huburl loop, build the topic chip list and add filter state to the template context (in the `render_from_template` array):
```php
    $topicchips = [];
    foreach ($alltopics as $t) {
        $topicchips[] = [
            'text' => $t,
            'url' => (new moodle_url('/mod/googlemeet/view.php',
                ['id' => $cm->id, 'topic' => $t, 'rorder' => $order]))->out(false),
            'active' => ($t === $topic),
        ];
    }
```
Add to the context array:
```php
        'recordingquery' => s($query),
        'alltopics' => $topicchips,
        'hasactivefilters' => (trim((string)$query) !== '' || trim((string)$topic) !== ''),
        'clearfiltersurl' => (new moodle_url('/mod/googlemeet/view.php', ['id' => $cm->id]))->out(false),
```
Also append `rq`/`topic` to the pagination `$pages[]` entries and the sort links so they survive paging (add `'rq' => $query, 'topic' => $topic` where those URLs are built in the template — see Task 3.3).

- [ ] **Step 4: Syntax-check + commit**

Run: `php -l view.php && php -l locallib.php`
```bash
git add view.php locallib.php
git commit -m "feat(googlemeet): filter recordings by search/topic before pagination"
```

### Task 3.3: Search box + topic chips + clear-filters in the template

**Files:** `templates/recordingstable.mustache`, `lang/en`, `lang/es`

- [ ] **Step 1: Add lang strings**

EN:
```php
$string['recordings_search_label'] = 'Search classes';
$string['recordings_search_placeholder'] = 'Search by title, topic or summary…';
$string['recordings_filter_clear'] = 'Clear filters';
$string['recordings_filter_all_topics'] = 'All topics';
```
ES:
```php
$string['recordings_search_label'] = 'Buscar clases';
$string['recordings_search_placeholder'] = 'Buscar por título, tema o resumen…';
$string['recordings_filter_clear'] = 'Quitar filtros';
$string['recordings_filter_all_topics'] = 'Todos los temas';
```

- [ ] **Step 2: Add the filter bar above the grid**

After the `.googlemeet-recordings-header` div (line ~81), add:
```html
  {{#hasrecordings}}
  <form method="get" action="view.php" class="googlemeet-recordings-filterbar d-flex flex-wrap gap-2 align-items-center mb-3">
    <input type="hidden" name="id" value="{{coursemoduleid}}">
    <label class="visually-hidden" for="googlemeet-rq">{{# str }} recordings_search_label, mod_googlemeet {{/ str }}</label>
    <input type="search" id="googlemeet-rq" name="rq" value="{{recordingquery}}" class="form-control form-control-sm" style="max-width:320px"
           placeholder="{{# str }} recordings_search_placeholder, mod_googlemeet {{/ str }}">
    <button type="submit" class="btn btn-sm btn-primary">{{# str }} recordings_search_label, mod_googlemeet {{/ str }}</button>
    {{#hasactivefilters}}<a href="{{clearfiltersurl}}" class="btn btn-sm btn-link">{{# str }} recordings_filter_clear, mod_googlemeet {{/ str }}</a>{{/hasactivefilters}}
  </form>
  {{#alltopics.0}}
  <div class="googlemeet-recordings-topicfilter d-flex flex-wrap gap-1 mb-3">
    {{#alltopics}}
      <a href="{{url}}" class="googlemeet-ai-topic-tag {{#active}}googlemeet-topic-active{{/active}}">{{text}}</a>
    {{/alltopics}}
  </div>
  {{/alltopics.0}}
  {{/hasrecordings}}
```

- [ ] **Step 3: Preserve `rq`/`topic` in pagination + sort links**

In every pagination `<a>` and the sort `<a>` (lines ~70-77, ~295-320), append `&rq={{recordingquery}}&topic={{selectedtopic}}` — and add `'selectedtopic' => s($topic)` to the PHP context in Task 3.2 Step 3.

- [ ] **Step 4: Style active topic chip**

Add to `styles.css`:
```css
.googlemeet-ai-topic-tag.googlemeet-topic-active,
.googlemeet-topic-active { background: #174ea6; color: #fff; }
.googlemeet-recordings-topicfilter a { text-decoration: none; }
```

- [ ] **Step 5: Commit**
```bash
git add templates/recordingstable.mustache lang/en/googlemeet.php lang/es/googlemeet.php styles.css
git commit -m "feat(googlemeet): search box + topic filter chips + clear filters"
```

### Task 3.4: Deploy + verify Phase 3

- [ ] **Step 1: Bump version** to `2026060112` / release `2.16.0`.
- [ ] **Step 2: Run full tests** — `vendor/bin/phpunit mod/googlemeet/tests/locallib_test.php` → PASS.
- [ ] **Step 3: Deploy** (rsync, upgrade, purge, opcache reset).
- [ ] **Step 4: Manual verification** — search "casos"/a topic; confirm filtered count + accurate pagination; clear filters; topic chip toggles; `rq`/`topic` survive page changes & sort toggles. Screenshot.
- [ ] **Step 5: Commit version bump**
```bash
git add version.php
git commit -m "chore(googlemeet): v2026060112 — search + topic filtering (Phase 3)"
```

---

## Backlog (out of scope — note, do not silently assume)

- **Real thumbnails:** extend `client::syncrecordings()` to persist Google Drive `thumbnailLink` into a new `googlemeet_recordings.thumbnailurl` column (install.xml + upgrade.php), then swap the gradient `.googlemeet-card-media` for the image with the gradient as fallback. Highest visual upgrade; needs schema + sync change.
- **Continue watching / unwatched:** requires per-user watch state (new table or logstore-derived). Do NOT label "No visto" until that data exists.
- **Server-side topic index:** only if an activity grows to hundreds of recordings and the fetch-all-then-filter approach gets slow.

---

## Self-review notes

- **Spec coverage:** message fix (Phase 0) ✓; dead-model fix (Phase 0) ✓; honest AI status list+hub (Phase 1, Tasks 1.3/1.7) ✓; mb-safe truncation (Task 1.1) ✓; card layout with centered play per user (Phase 2) ✓; ragged-height mitigation via fixed media zone + line-clamp (Task 2.5) ✓; a11y focus/aria/button (Tasks 2.4/2.5) ✓; JS-selector preservation (constraint + Task 2.4 Step 2 inventory) ✓; date grouping (Tasks 2.2/2.3/2.4) ✓; search + topic filter before pagination (Phase 3) ✓; deploy procedure each phase ✓.
- **Type/name consistency:** status flag keys (`aistatusisprocessing` etc.) are produced by `googlemeet_ai_status_flags()` and consumed identically in both templates and `googlemeet_print_recording_hub()`. `hasai` semantics unchanged everywhere. Helper names match between definition (lib.php) and call sites (lib.php loop, locallib.php).
- **Known follow-ups to confirm during execution:** the `strftimedm`/`strftimemonthyear` lang keys (add `strftimedm` to the plugin if missing; `strftimemonthyear` is core `langconfig`); confirm `play` pix exists (it does — used today at recordingstable line 101).
