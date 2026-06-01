# UX/UI redesign plan for recorded classes in `mod_googlemeet`

## 1. Current UX critique

### What works

- The list already moved away from a dense table into a vertical media-list pattern: `.googlemeet-recordings-list` contains repeated `.googlemeet-recording-card` rows with a clear play affordance (`.googlemeet-play-btn`), title link (`.recording-name-text`), date, duration, Drive link, and teacher controls.
- AI is present where students make the decision: cards with `hasai` show `.googlemeet-ai-badge`, `aisummarypreview`, `aikeypointscount`, and `aitopics`. This is the right idea for adult students who want to decide quickly whether a recording is worth rewatching.
- The per-recording hub (`templates/recording_hub.mustache`) is video-first. The embedded player appears before metadata and tabs, which fits the primary action: watch or resume the class.
- Existing fields are useful and pragmatic: `huburl`, `webviewlink`, `createdtimeformatted`, `duration`, `hasai`, `aisummarypreview`, `aikeypoints`, `aikeypointscount`, `aitopics`, `aidate`, `aimodel`, `hasanalysis`, `summary`, `keypoints`, `topics`, and `transcript`.

### What hurts findability and scanability

- The list is capped by pagination from `maxrecordings` (default 5, max 20) and only offers chronological sort via `rorder`. There is no search by title/date, no topic filtering, and no grouping. For weekly classes, students may have to page through many near-identical names such as "Clase online..." to find a specific date or topic.
- The card title and metadata do not separate the date as a strong visual anchor. Since recording names include dates, the title becomes the only reliable date signal, while `createdtimeformatted` is long and system-formatted. This slows scanning in Spanish course contexts where students often remember "la clase de enero sobre contratos" more than the exact recording title.
- The AI preview is useful but visually heavy. `.googlemeet-ai-preview` uses a blue-tinted panel inside every AI card, so a list of AI-enabled recordings becomes a stack of prominent panels. The AI content competes with the recording title instead of acting as a compact preview.
- Topic tags (`.googlemeet-ai-topic-tag`, `.googlemeet-ai-topic-badge`) are all the same strong blue. They look clickable/filterable but currently are plain spans, which creates a mismatch between visual affordance and behavior.
- The expandable AI panel duplicates the hub: summary, keypoints, topics, and teacher-only transcript can all open inside the list, while the hub also has summary, keypoints, topics, transcript, questions, and materials. This creates two competing destinations: expand in place or open the class. For students, a compact preview plus hub is cleaner.
- AI missing states are inconsistent. The list only receives completed analysis from `googlemeet_list_recordings()` because the SQL filters `status = 'completed'`. So a pending, processing, or failed row is presented as no analysis (`hasai = false`) unless the user opens/generates via the hidden panel. The database and language strings support statuses (`pending`, `processing`, `completed`, `failed`), but the list does not surface them.

### Mobile issues

- At `max-width: 767px`, `.googlemeet-recording-content` wraps and `.googlemeet-recording-actions` gets `padding-left: 64px`. This preserves the desktop media-list shape but can leave action icons floating below the title instead of forming a predictable full-width action row.
- `.googlemeet-recording-meta` becomes a vertical stack, which is good, but long `createdtimeformatted` values can dominate the card. A shorter computed date label would improve mobile scanning.
- The AI expanded panel is large for a list screen. On mobile, opening multiple summaries and transcripts inside the list can produce very long scroll states and make it hard to return to the recording list position.
- Icon-only controls such as `.googlemeet-ai-toggle-btn`, `.googlemeet-drive-link`, and teacher edit/hide actions rely heavily on `title`. Titles are not enough on touch devices and are weak for accessibility.

## 2. Layout directions and tradeoffs

### Direction A: compact responsive media list, AI preview inline

Keep the current vertical list, but redesign each `.googlemeet-recording-card` as a denser class row:

- Left: date block or play thumbnail-like affordance.
- Center: title, short date, duration, AI status, one-line summary preview, and up to 3 topic tags.
- Right/Bottom: primary action "Ver clase" using `huburl`, secondary "Drive" using `webviewlink`, teacher controls only for editors.
- Replace the large list AI panel with a small "Ver resumen" link to the hub summary tab, while keeping teacher-only generation/edit actions available.

Tradeoffs:

- Lowest implementation risk because it fits `templates/recordingstable.mustache` and `styles.css`.
- Best for long chronological learning content because rows remain easy to compare.
- Less visually rich than thumbnails/cards, but Google Drive does not currently provide a thumbnail field in the template context.

### Direction B: responsive card grid with summary-first cards

Show 2-column desktop cards and 1-column mobile cards. Each card emphasizes summary, tags, and a prominent "Ver grabación" button.

Tradeoffs:

- More modern and visually spacious.
- Worse for students trying to compare many weekly classes by date because card heights will vary with `aisummarypreview` and topic count.
- The current data has no thumbnail, progress, or meaningful imagery, so a grid would likely become a set of text-heavy cards.

### Direction C: timeline grouped by month/week with search and topic filters

Group recordings under date headings such as "Mayo 2026" or course weeks, with a sticky-ish filter bar above the list.

Tradeoffs:

- Strong findability for weekly recordings and exam-preparation cohorts.
- Needs new computed fields from PHP (`dategroup`, maybe `shortdate`) and careful pagination behavior. Grouping across pages can feel odd unless pagination is increased or filtering happens before pagination.
- Works well combined with Direction A, but is more than a pure CSS/template cleanup.

### Recommendation

Use Direction A as P0 and evolve toward Direction C in P1.

For adult oposición students, speed and certainty matter more than a marketing-style grid. A compact media list lets them scan dates, durations, topics, and summary snippets quickly. Add topic/search/date grouping incrementally once the data is passed cleanly from PHP. Avoid a card grid until there is a real thumbnail/progress data source.

## 3. Information architecture

### List card: decision-making summary

Each `.googlemeet-recording-card` should answer: "Is this the class I need?"

Show on the card:

- Primary title: `name`, linked to `huburl`.
- Short date: new `createddateshort` computed from `createdtime`, e.g. `12 may 2026`. Keep `createdtimeformatted` available as a `title` or visually hidden full date if needed.
- Duration: existing `duration`.
- AI state:
  - Completed: existing `hasai`, but rename the visible badge from generic "AI" to a student-facing "Resumen IA" or "Resumen disponible".
  - No row: "Sin resumen" only if useful; otherwise do not add noise for students.
  - Pending/processing/failed: requires new fields; see roadmap.
- Summary preview: existing `aisummarypreview`, ideally clamped to 2 lines with CSS rather than hard truncating only by `substr()`.
- Topics: existing `aitopics`, limited in the template/PHP to the first 3 or 4 visible tags plus a `+N` count if many exist.
- Keypoints count: existing `aikeypointscount`, but make it secondary. "5 puntos clave" is useful, but less important than the actual topics.
- Primary action: "Ver clase" to `huburl`.
- Secondary action: Drive icon/link to `webviewlink`.
- Teacher controls: keep edit name, hide/show, manual AI edit, generate/regenerate, but visually separate them from student actions.

Do not show full transcript in the list. The transcript is too long and, currently, list transcript content is loaded lazily only for teachers. It belongs in the hub.

### Hub: watching and deep study

The hub should answer: "Watch, then study the content from this class."

Keep on the hub:

- Video iframe from `embedurl` as the first major element.
- Title, duration, Drive link, visibility/edit controls.
- Summary tab: `summary`, `keypoints`, `topics`.
- Questions tab: existing practice/teacher flow.
- Materials tab: existing `materials`.
- Transcript tab: currently only shown to `caneditrecording`. That is consistent with privacy caution, but if students are expected to skim transcript, this permission decision should be revisited separately. Do not expose transcript in the redesign without a product/privacy decision.

Improve hub layout:

- Add a compact AI overview row directly under the title when `hasanalysis`: topic tags plus keypoint count, before tabs.
- In the Summary tab, make keypoints the fastest-scanning element: show them before or beside the long summary on desktop; stack on mobile.
- Keep generated metadata (`aidate`, `aimodel`) low-emphasis. The hub currently passes no `aidate` or `aimodel`; add only if teachers need it.

### Pending, failed, regenerate

Current database supports `googlemeet_ai_analysis.status`, `error`, `retrycount`, `nextretry`, but the list and hub only fetch completed records:

- `lib.php::googlemeet_list_recordings()` selects `WHERE recordingid IN (...) AND status = 'completed'`.
- `locallib.php::googlemeet_print_recording_hub()` fetches `status = 'completed'`.

Recommended states:

- `completed`: show summary preview/topics and "Resumen IA".
- `processing`: show a neutral status chip "Resumen en proceso" and disable regenerate while processing.
- `pending`: show "Resumen pendiente" for teachers; for students, either hide or show a small neutral chip if useful.
- `failed`: for teachers, show "Error al generar resumen" with regenerate action and a concise error detail. For students, show no AI summary or a soft "Resumen no disponible".
- `no analysis row`: show nothing for students; show "Generar análisis" for users with `cangenerateai`.

New template fields needed: `aistatus`, `aistatuslabel`, `aistatusiscompleted`, `aistatusisprocessing`, `aistatusispending`, `aistatusisfailed`, `aierror`, `hasanalysisrow`, `canretryai`.

## 4. Findability

Only add features that match available or easy-to-compute data.

### Search by title and AI text

Realistic P1 option:

- Add a GET search parameter, e.g. `rq`, in `view.php`.
- Filter server-side before pagination by `googlemeet_recordings.name`.
- If AI is enabled, optionally join completed `googlemeet_ai_analysis` and search `summary` and `topics`. Keep this simple and DB-portable with Moodle SQL `LIKE`.

New fields/params:

- `recordingquery` for the input value.
- `hasactivefilters`.
- Pagination links must preserve `rq`.

Avoid relying on the currently loaded `jstable.min.js` for search unless it is already initialized elsewhere. The current template is not a table anymore, so client-side table search is not the right primary path.

### Filter by topic tag

Realistic P1/P2 option:

- Since `topics` is stored as JSON text, topic filtering is easiest after fetching AI rows for the current activity, then normalizing tags in PHP.
- For P1, provide clickable topic chips only from topics visible on the current page and filter client-side within that page. This is simple but can mislead because pagination hides matches on other pages.
- For P2, compute all distinct completed AI topics for the activity in PHP and filter server-side before pagination.

New fields:

- Per card: `aitopicslimited`, `hiddentopiccount`.
- Page: `alltopics`, `selectedtopic`.
- Pagination links preserve `topic`.

### Group by date

Realistic P1 option:

- Compute `dategroup` in PHP from `createdtime`, e.g. "Mayo 2026".
- Add `showdategroup` on the first recording in each group.
- Render a simple heading before cards inside `recordingstable.mustache`.

This works even with pagination, though a month can span pages. That is acceptable if the "Mostrando X - Y / total" line remains visible.

### Continue watching

Not realistic with current fields. There is no per-user watch progress, last watched timestamp, or completion state in the files reviewed. Do not design "Continuar viendo" until the plugin records user progress.

Possible future fields:

- `lastwatchedtime`, `watchprogresspercent`, `resumeurl`, `iscompleted`, backed by a new per-user table or Moodle completion/log data.

### New/unwatched indicator

Partially realistic:

- "New" can be approximated from `createdtime` compared with current time, e.g. new within 7 or 14 days. This requires only PHP fields `isnew` and `newlabel`.
- "Unwatched" is not realistic without per-user watch data.

Recommendation:

- Add `isnew` based on `createdtime` for the first iteration.
- Do not claim "No visto" until there is user-specific data.

## 5. Responsive and accessibility fixes

### Responsive behavior

- Keep one-column cards on all viewports, but make desktop denser:
  - Desktop: media row with fixed play/date column, flexible content, actions aligned right.
  - Mobile: title and summary first, actions in a full-width row with text labels for primary actions.
- Replace `.googlemeet-recording-actions { padding-left: 64px; }` on mobile with a real flex row:
  - `width: 100%`
  - `padding-left: 0`
  - `justify-content: flex-start`
  - buttons/chips wrap naturally.
- Limit `.googlemeet-ai-preview-summary` to 2 or 3 lines using CSS line clamp, with the full summary available in the hub.
- Keep topic chips small and wrapping. Avoid making every chip strong blue; use a quieter neutral background so the title and primary action remain dominant.
- On the hub, keep the iframe responsive via `.ratio-16x9`, but ensure tabs can wrap or horizontally scroll without breaking on narrow screens.

### Accessibility gaps

- Replace `href="javascript:void(0);"` controls with `<button type="button">` where they trigger actions: `.recordingeditname`, `.recordinghowhide`, `.googlemeet-ai-toggle-btn`, `.googlemeet-ai-edit-btn`. This improves keyboard semantics and avoids fake links.
- If the AI panel remains in any form, connect toggle buttons to panels with `aria-expanded` and `aria-controls`. Current `.googlemeet-ai-toggle-btn` has only `title` and data attributes.
- The AI preview block has `cursor: pointer` but is a `<div>`. Make it a button/link or remove pointer behavior if it only toggles hidden content.
- Add visible text labels on mobile for key actions. Icon-only `title` is insufficient on touch and for many assistive workflows.
- Ensure focus states are visible for `.googlemeet-play-btn`, topic filters, pagination links, and teacher controls. Current hover styles are stronger than focus styles.
- Avoid color-only status communication. For hidden recordings, `.googlemeet-recording-hidden` and `.googlemeet-recording-hidden-badge` are good, but AI states should include text labels, not just blue borders/badges.
- Check contrast:
  - White text on `#1a73e8` is acceptable.
  - Small muted text (`#888`, `#999`) should be used sparingly, especially for important metadata.
  - Blue-on-blue AI panels can become low hierarchy rather than low contrast; simplify backgrounds.
- Tabs in the hub already use Bootstrap tab ARIA attributes. Preserve those if changing layout.

## 6. Incremental implementation roadmap

### P0: redesign the existing list without changing storage

Scope: one Mustache template plus CSS, no new build step.

- Refactor `templates/recordingstable.mustache` card markup while keeping existing fields: `name`, `huburl`, `createdtimeformatted`, `duration`, `webviewlink`, `visible`, `hasai`, `aisummarypreview`, `aikeypointscount`, `aitopics`, `hascapability`, `aienabled`, `cangenerateai`.
- Make `huburl` the dominant primary action: title link and a visible "Ver clase" button.
- Convert the AI preview from a large blue panel into a compact preview area:
  - small "Resumen IA" chip when `hasai`
  - 2-line `aisummarypreview`
  - up to visible topic tags
  - "Ver resumen" link to `huburl` (optionally with `&tab=summary` later)
- Keep the expandable `.googlemeet-ai-panel` only for teacher/admin AI actions or remove it from the student path. If it remains, make the toggle a real button and use `aria-expanded`.
- Simplify mobile actions: no left padding hack; actions wrap in a full-width row.
- Update `styles.css` for the new classes, reusing existing names where possible to reduce JS breakage.
- Add CSS focus states for `.googlemeet-play-btn`, `.googlemeet-ai-toggle-btn`, `.googlemeet-drive-link`, pagination, and new buttons.

New PHP fields needed for P0: none.

### P1: add real AI status and stronger date scanning

Scope: `lib.php`, `locallib.php`, `view.php` only as needed, plus template/CSS.

- Change `googlemeet_list_recordings()` to fetch AI rows for listed recordings regardless of status, not only `status = 'completed'`.
- Populate new fields:
  - `hasanalysisrow`
  - `aistatus`
  - `aistatuslabel`
  - `aistatusiscompleted`
  - `aistatusisprocessing`
  - `aistatusispending`
  - `aistatusisfailed`
  - `aierror` for teachers only
  - `canretryai`
- Keep `hasai` meaning "completed analysis with content" so existing template conditions do not break.
- Add `createddateshort` and `dategroup` from `createdtime`.
- Add `showdategroup` in the recordings loop so the template can render headings such as "Mayo 2026".
- Add `isnew` based on `createdtime` within a configurable/simple threshold, e.g. 14 days.
- In `recording_hub.mustache`, surface non-completed AI states in the Summary tab instead of only `hasanalysis` vs no analysis.

### P2: search and topic filtering

Scope: server-side filtering in PHP, template controls, pagination link preservation.

- Add a search input above `.googlemeet-recordings-list`:
  - GET param `rq`
  - field `recordingquery`
  - filter by recording `name`; optionally completed AI `summary` and `topics`.
- Add topic filters:
  - field `alltopics` with `text`, `url`, `active`
  - GET param `topic`
  - preserve `topic`, `rq`, `rorder`, and `rpage` in all sort/pagination URLs.
- Apply filters before pagination so counts and pages are accurate.
- If server-side topic filtering against JSON is too DB-specific, normalize in PHP after fetching candidate rows for the activity, then paginate the filtered array. This is acceptable if recording counts are modest; otherwise introduce a normalized topic index table later.
- Consider a "Limpiar filtros" control when `hasactivefilters` is true.

Not in scope until new user data exists:

- Continue watching.
- Unwatched indicators.
- Watch progress bars.
- Personalized recommendations.

## Design principle for this module

Treat the list as a study index, not a video gallery. The strongest signals should be date, topic, summary, and the direct path to watch. The hub is the place for full viewing, full AI study content, questions, materials, and teacher management.
