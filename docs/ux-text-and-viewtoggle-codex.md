# UX QA: Text and Card/List Toggle

Context reviewed: `templates/recordingstable.mustache`, `templates/recording_hub.mustache`, `lang/es/googlemeet.php`, `lang/en/googlemeet.php`, `locallib.php`, `lib.php`, and the prior `docs/ux-final-qa-codex.md`.

## Q1. Other Spanish Text/Label Issues

### 1. `subscriberecordings`

Current: `Avisarme cuando haya nuevas grabaciones disponibles`

Problem: too long for a small outline button in the header controls. It competes with the sort buttons and will wrap or force awkward header stacking on narrow desktop/mobile. Tone is acceptable, but it reads like body copy inside a control.

Recommended: `Recibir avisos`

If more specificity is needed in the accessible name/title: `Recibir avisos de nuevas grabaciones`.

Implementation note: use the short text in the visible button, and add `title`/`aria-label` with the longer phrase if necessary. The paired unsubscribe label can stay short, but should be parallel:

- `subscriberecordings`: `Recibir avisos`
- `unsubscriberecordings`: `Dejar de recibir avisos`

### 2. `strftimedm`

Current: `%d %b`, example `27 may`

Problem: this is acceptable for a compact card date, but the live Moodle/userdate output may inherit locale abbreviations with capitalization and trailing periods in fuller formats, for example `Mié. 27 May.`. That is useful in a sentence or long timestamp, but visually noisy inside a compact badge/card. Spanish month abbreviations in running UI usually read better lowercase, and the trailing period is unnecessary in a date chip.

Recommended for card date: keep compact numeric/month text, but normalize presentation in PHP for this field only:

- Desired card display: `27 may`
- If the UI needs weekday: `mié 27 may`, no final dot, lowercase

Concrete fix: do not reuse `strftimedmy` for compact chips. Add a dedicated string such as:

- `recording_date_chip_format`: `%d %b`

Then post-process `createddateshort` for the chip with `core_text::strtolower()` and remove `.` characters if the locale returns `may.`. Do this only for the chip/date label, not for full Moodle timestamps.

### 3. `ai_badge_label`

Current: `Resumen IA`

Problem: understandable, but slightly product-jargony and visually long for the media strip badge. It also creates repetition with status chips that all begin with `Resumen`.

Recommended: `IA`

Use `ai_analysis_available` or a `title`/`aria-label` to preserve clarity: `Resumen generado con IA disponible`.

### 4. `ai_keypoints_short`

Current: `puntos clave`, rendered as `{n} puntos clave`

Problem: reads well for plural, but is grammatically wrong for `1 puntos clave`.

Recommended fix: pass a localized pluralized string from PHP instead of concatenating count + suffix in Mustache/JS.

Suggested strings:

- `ai_keypoints_count`: `{$a} punto clave`
- `ai_keypoints_count_plural`: `{$a} puntos clave`

Or use Moodle plural handling if this codebase has an existing pattern available. If implementation must stay simple, render `{{aikeypointscountlabel}}` from PHP.

### 5. AI Status Chips

Current:

- `ai_status_chip_processing`: `Resumen en proceso`
- `ai_status_chip_pending`: `Resumen en cola`
- `ai_status_chip_failed_student`: `Resumen no disponible todavía`
- `ai_status_chip_failed_teacher`: `Error al generar el resumen`

Problems: the first two are clear but a bit long for chips; the student failed state is misleading because a failed generation is not merely "todavía"; the teacher failed state is accurate but too long for compact cards. Also the UI calls the feature `Análisis IA`, but the chips say `Resumen`, which narrows the feature incorrectly because it also includes puntos clave, temas and transcripción.

Recommended:

- `ai_status_chip_processing`: `Análisis en curso`
- `ai_status_chip_pending`: `Análisis en cola`
- `ai_status_chip_failed_student`: `Análisis no disponible`
- `ai_status_chip_failed_teacher`: `Error de análisis`

If the teacher needs detail, show it in the expanded panel/error area, not the card chip.

### 6. `recordings_search_placeholder`

Current: `Buscar por título, tema o resumen…`

Problem: good copy, but risky as placeholder-only guidance in a `max-width:320px` input. At 320px it fits in most Moodle fonts, but at narrower widths the placeholder can clip. The visible submit button also says `Buscar clases`, so the control reads as "Buscar clases" twice for screen readers/visual users.

Recommended:

- Placeholder: `Título, tema o resumen`
- Button: `Buscar`
- Label: keep `recordings_search_label` as `Buscar clases` for the visually-hidden label.

This keeps the input compact and the button command-like.

### 7. `recordings_filter_all_topics`

Current: `Todos los temas`

Problem: clear and acceptable. No copy change required. The risk is in user-generated topic option text, which may be long AI phrases.

Recommended UI fix: cap the select width, keep `text-overflow: ellipsis`, and consider changing the default to the slightly shorter `Todos` only if the select sits next to a visible label `Tema`. With the current hidden label, keep `Todos los temas`.

### 8. `recording_watch_cta`, `recording_view_summary`, `recording_play_aria`

Current:

- `recording_watch_cta`: `Ver clase`
- `recording_view_summary`: `Ver resumen`
- `recording_play_aria`: `Reproducir clase`

Problems: the visible CTA and aria label describe different actions. The destination is the hub, not direct playback only: it includes video plus summary/questions/materials. `Ver clase` was already flagged for wrapping and can sound like joining/live class rather than opening a recording. `Reproducir clase` is especially ambiguous because the link goes to a page, not just a play action.

Recommended:

- Primary CTA: `Abrir clase`
- Student summary link: `Abrir resumen`
- Media aria label: `Abrir clase grabada: {{name}}`

Alternative if product wants recording-specific wording:

- Primary CTA: `Ver grabación`
- Media aria label: `Abrir grabación: {{name}}`

My recommendation is `Abrir clase` because the hub is now a class workspace, not just a Drive recording.

### 9. `thereisnorecordingtoshow`

Current: `There is no recording to show.` in English; Spanish key is absent in the visible excerpt, so Moodle may fall back if not defined elsewhere.

Problem: if the Spanish string is missing, the empty state appears in English. Even if translated elsewhere, the current English source is singular and stiff.

Recommended Spanish:

- `thereisnorecordingtoshow`: `Todavía no hay grabaciones.`

If filters are active, the same empty state is misleading because recordings may exist but not match the filters.

Recommended additional string and logic:

- `recordings_no_filter_results`: `No hay grabaciones que coincidan con los filtros.`

Use it when `totalrecordings === 0` after filters and `hasactivefilters` is true.

### 10. `recordings_showing`

Current rendered pattern: `Mostrando 1 - 5 / 12`

Problem: compact, but the slash pattern feels like a table counter rather than Moodle copy. It is understandable, not a blocker.

Recommended: replace the composed template text with the existing fuller string style:

- `recordings_pagination_info`: `Mostrando {$a->start} a {$a->end} de {$a->total} grabaciones`

Current template already has `recordings_pagination_info` in language files, but does not use it.

### 11. `recording_new`

Current: `Nuevo`

Problem: already flagged visually. Copy-wise, `Nuevo` can refer to the class, not the recording. On a recording card, a shorter and clearer tag is possible.

Recommended: `Nueva`

Reason: it agrees with `grabación`. If the card is framed as `clase`, then `Nueva` still works for `clase grabada`. Pair it with the visual collision fix from the prior QA note.

### 12. Topic Overflow Chip

Current: `+{{aitopicsoverflow}}`

Problem: understandable but ambiguous for screen readers and weak for users who do not immediately infer it means additional topics. It can also be clipped per the prior QA.

Recommended:

- Visible chip: `+{{aitopicsoverflow}}`
- `aria-label`: `{{aitopicsoverflow}} temas más`
- Optional `title`: `{{aitopicsoverflow}} temas más`

No need to show `+N temas` visibly in the card; that is too long for the chip.

### 13. Teacher Action Labels: `showhide`, `openindrive`, `ai_edit_manual`, `ai_analysis`

Current:

- `showhide`: `Mostrar/ocultar`
- `openindrive`: `Abrir en Drive`
- `ai_edit_manual`: `Editar manualmente`
- `ai_analysis`: `Análisis IA`

Problems: `Mostrar/ocultar` is vague without state. The icon action should announce the actual next action: `Ocultar a estudiantes` or `Mostrar a estudiantes`. `Editar manualmente` is acceptable in the panel button, but long for an icon title; `Editar análisis` is clearer. `Análisis IA` is acceptable, though `Análisis con IA` reads more naturally in Spanish.

Recommended:

- Add state-specific strings: `recording_hide_from_students`: `Ocultar a estudiantes`; `recording_show_to_students`: `Mostrar a estudiantes`
- `ai_edit_manual`: visible button can be `Editar análisis`; longer help text can explain manual editing if needed
- `ai_analysis`: `Análisis con IA`

### 14. Hub Tabs

Current:

- `hub_tab_summary`: `Resumen IA`
- `hub_tab_questions`: `Preguntas`
- `hub_tab_transcript`: `Transcripción`
- `hub_tab_materials`: `Materiales`

Problems: generally good. `Resumen IA` is the only awkward one; use the same convention as above. Tab width is fine for Spanish, but `Transcripción` is long on mobile.

Recommended:

- `hub_tab_summary`: `Resumen`
- Keep other tabs as-is.

If the AI source must be explicit, use a small badge or panel copy, not the tab label.

### 15. Modal Placeholders

Current examples:

- `Introduce un resumen de la reunión...`
- `Introduce los puntos clave (uno por línea)...`
- `Guardando...`

Problem: Spanish UI uses ASCII three dots in several places while newer strings use ellipsis. This is not a functional issue, but it looks inconsistent.

Recommended: use `…` consistently in visible loading/placeholder strings:

- `Introduce un resumen de la clase…`
- `Guardando…`
- `Generando análisis…`

Also consider replacing `reunión` with `clase` in this product surface, since the list copy uses class language.

## Q2. Cards/List View Toggle

### Recommended Control

Place the view toggle in the recordings toolbar next to `Ordenar por`, after the sort segmented control and before/after the subscribe button depending on available width. The view toggle is a view preference, so it belongs with ordering and filtering controls, not inside each card.

Recommended layout:

- Header row: title + count on the left.
- Controls on the right: subscription button, `Ordenar por`, then view toggle.
- On narrow widths, let controls wrap; keep the filter bar below as it is.

Control appearance:

- Segmented control with two icon buttons.
- Visible short labels if there is room: `Tarjetas` and `Lista`.
- Use Moodle/core pix icons if available; otherwise use simple Bootstrap/Moodle button styling with text. Do not use custom decorative SVGs for this.
- Active state should match sort controls (`btn-primary` active, `btn-outline-secondary` inactive).

New strings:

- `recordings_view_label`: `Vista`
- `recordings_view_cards`: `Tarjetas`
- `recordings_view_list`: `Lista`
- `recordings_view_cards_aria`: `Ver grabaciones como tarjetas`
- `recordings_view_list_aria`: `Ver grabaciones como lista`

### Persistence

Use Moodle user preferences:

- `get_user_preferences('mod_googlemeet_recordings_view', 'cards')`
- `set_user_preference('mod_googlemeet_recordings_view', $view)`

This is the right persistence model for a Moodle activity because it is per-user, server-side, survives navigation/devices, and does not pollute shareable URLs. It also avoids localStorage fragmentation across browsers.

Do not use URL params as the source of truth. URL params should stay focused on content state:

- `rpage`: pagination
- `rorder`: sort order
- `rq`: search query
- `topic`: topic filter

Recommended interaction:

- Add optional GET param `rview=cards|list` only as an action to update the preference.
- When `rview` is present, validate it, call `set_user_preference()`, then redirect to the same `view.php` URL with `rview` removed and existing `id`, `rpage`, `rorder`, `rq`, `topic` preserved.
- Default is `cards` for existing users.

This keeps URLs clean after the click and still works without JavaScript.

### List View Layout

Use a compact two-line row, not a single-line table. The content has too many states for a one-line row, especially teacher actions and AI chips.

Recommended row structure:

- Left/date column: compact date chip `27 may`; optionally year only when crossing years or in tooltip/full hidden text.
- Main column line 1: recording title, hidden badge if teacher and hidden, new badge if needed.
- Main column line 2: duration, AI status/keypoint count, up to 2 topic chips plus `+N`, short summary preview only when there is enough width.
- Right/action column: primary `Abrir clase` button, Drive icon, teacher visibility/edit/AI actions.

Density:

- Desktop: row height around 72-88px without summary; 96-112px with one-line summary preview.
- Mobile: stack actions below the title/meta, keep date and title visible first.
- Do not show the full 200-character summary in list mode by default. Use one clamped line, or omit summary and rely on the AI badge/status.

Teacher AI expansion:

- Reuse the planned sibling full-width panel row from the prior QA, not a panel nested inside the card/list row.
- In grid/card mode, expansion inserts/reveals `.googlemeet-ai-panel-row` spanning the grid.
- In list mode, expansion inserts/reveals a full-width row immediately after the list item.
- The same panel markup can be shared if it is structurally outside the compact item and keyed by recording id.

This keeps the compact list compact and avoids row height jumping into a full editing surface.

### Implementation Shape

Recommendation: branch server-side and render only the selected structure.

Reasoning:

- Rendering both cards and list doubles the DOM for up to 20 recordings and duplicates interactive controls with identical recording ids, AI panel ids, edit-name bindings, visibility toggles, transcript lazy loading, and modal triggers.
- The current inline JS binds by broad class selectors (`.recordingeditname`, `.recordinghowhide`, `.googlemeet-ai-toggle-btn`). Duplicated structures would produce duplicate bindings and state synchronization issues unless the JS is refactored.
- Moodle pages commonly tolerate a full reload for preference changes, and the list is paginated; a reload is acceptable and robust.

Tradeoff: server-side branching means the view toggle click reloads the page. That is acceptable for v1. The saved preference survives navigation, and the no-JS fallback is automatic.

Recommended v1 flow:

1. In `view.php`, read optional `rview` alongside `rpage`, `rorder`, `rq`, `topic`.
2. Validate `rview` as `cards|list`.
3. If present, call `set_user_preference('mod_googlemeet_recordings_view', $rview)` and redirect to `view.php` with the existing content params except `rview`.
4. In `googlemeet_print_recordings()`, read `get_user_preferences('mod_googlemeet_recordings_view', 'cards')`.
5. Pass `recordingsview`, `isviewcards`, `isviewlist`, and toggle URLs to Mustache.
6. In `recordingstable.mustache`, branch:
   - `{{#isviewcards}}` current `.googlemeet-recordings-grid`
   - `{{#isviewlist}}` new `.googlemeet-recordings-listview`
7. Reuse the same per-recording data objects and the same AI panel partial/markup where possible.

Optional enhancement later: add a small AJAX/web service endpoint to set the preference and switch classes without reload. Do not do this first; it creates more QA surface for little benefit.

### Accessibility

Use a segmented radio-style control or pressed buttons.

Best markup:

- A wrapping element with `role="radiogroup"` and `aria-label="Vista"`.
- Two controls with `role="radio"`, `aria-checked="true|false"`.
- Because the controls navigate/reload, anchors are acceptable if they carry the radio semantics and active state.

Also acceptable:

- Two `<button>` elements in a GET form with `aria-pressed="true|false"`, one submitting `rview=cards`, the other `rview=list`.

Avoid using only color for state; keep active styling plus `aria-current` or `aria-checked`.

Keyboard behavior:

- Native anchors/buttons are keyboard reachable.
- If using true radio behavior with arrow-key navigation, that requires JS. For v1, native tab/enter activation is enough.

Screen reader labels:

- Visible: `Tarjetas`, `Lista`
- `aria-label`: `Ver grabaciones como tarjetas`, `Ver grabaciones como lista`
- The active control should announce selected/current state.

## A. Prioritized Text/Tag Fixes

P0:

1. Shorten `subscriberecordings` to `Recibir avisos`; update unsubscribe to `Dejar de recibir avisos`.
2. Change `recording_watch_cta` to `Abrir clase` and `recording_play_aria` to `Abrir clase grabada`.
3. Add/fix Spanish `thereisnorecordingtoshow`: `Todavía no hay grabaciones.`
4. Add filtered-empty copy: `No hay grabaciones que coincidan con los filtros.`
5. Change AI status chips to `Análisis en curso`, `Análisis en cola`, `Análisis no disponible`, `Error de análisis`.

P1:

1. Change `recording_new` to `Nueva`.
2. Change `ai_badge_label` from `Resumen IA` to `IA` with a clearer title/aria label.
3. Render keypoint count as a localized singular/plural label.
4. Shorten search placeholder to `Título, tema o resumen` and visible button to `Buscar`.
5. Use `recordings_pagination_info` instead of `Mostrando 1 - 5 / 12`.

P2:

1. Normalize compact date chips to lowercase/no trailing dots.
2. Add `aria-label`/`title` to `+N` overflow chips.
3. Change `hub_tab_summary` to `Resumen`.
4. Add state-specific visibility action labels.
5. Standardize ellipsis usage in visible loading/placeholders.

## B. Recommended Card/List Toggle Plan

Implement a per-user persisted toggle using Moodle user preferences, with a server-rendered branch for `cards` vs `list`.

The toggle should sit in the recordings controls next to `Ordenar por`, styled as a small segmented control labelled `Vista` with `Tarjetas` and `Lista`. It should preserve existing `rpage`, `rorder`, `rq`, and `topic` state when clicked, set `mod_googlemeet_recordings_view`, then redirect to the same page without `rview`.

Render only the selected view. Keep the current card grid for `cards`; add a compact two-line list row for `list` with date, title, duration, AI status/keypoint count, up to two topics plus `+N`, primary `Abrir clase`, Drive link, and teacher actions. Reuse the same full-width sibling AI panel row in both modes.

This gives the owner the requested user choice without duplicating interactive DOM, keeps Moodle URLs clean, works without JavaScript, and leaves room for an AJAX preference setter later if instant switching becomes important.
