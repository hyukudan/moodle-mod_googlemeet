**Pre-Deploy Review: mod_googlemeet Recordings UI Phase AB**

Verdict: **SHIP-WITH-FIXES**

The `rview` preference handler, PHP view preference defaults, template branch balance, and full-width sibling AI panel structure are broadly sound. I do not see a redirect loop, open redirect, duplicate `googlemeet-ai-panel-{{id}}` IDs, or a card/list branch nesting blocker.

**Findings**

1. **HIGH** - [templates/recordingstable.mustache](/home/preparaoposiciones/desarrollo/moodle-mod_googlemeet/templates/recordingstable.mustache:673): live AI generation/status completion updates only card view, not list view.

   Problem: `generateAiAnalysis()` and `checkAnalysisStatus()` both resolve the item with:

   ```js
   $('.googlemeet-recording-card[data-recordingid="' + recordingid + '"]')
   ```

   In list view this returns an empty jQuery object. The AI panel content itself can render, but the row-level UI is left stale: the list row keeps the old processing/pending chip, does not get `googlemeet-recording-has-ai`, does not get the AI badge/keypoint/topic updates, and the toggle remains inactive/`data-hasai="0"`. This is especially visible for background completion while a teacher is using the new list view.

   Exact fix: select either presentation and update common descendants, with list-specific badge placement handled separately:

   ```js
   var item = $('.googlemeet-recording-card[data-recordingid="' + recordingid + '"], ' +
       '.googlemeet-recording-listitem[data-recordingid="' + recordingid + '"]');
   ```

   Then pass `item` to `displayAiAnalysis()`. Inside `displayAiAnalysis()`, update `.googlemeet-card-meta` and `.googlemeet-ai-toggle-btn` as common targets, but branch summary/topic insertion:

   - cards: update/create `.googlemeet-card-summary` and `.googlemeet-card-topics` inside `.googlemeet-card-body`.
   - list: remove status chips from `.googlemeet-listitem-meta`, insert keypoint/topic chips there, and add `.googlemeet-card-aibadge` inside `.googlemeet-listitem-title`.

   Apply the same selector change at [templates/recordingstable.mustache](/home/preparaoposiciones/desarrollo/moodle-mod_googlemeet/templates/recordingstable.mustache:985).

2. **MEDIUM** - [templates/recordingstable.mustache](/home/preparaoposiciones/desarrollo/moodle-mod_googlemeet/templates/recordingstable.mustache:578): visibility toggle updates the icon and hidden class, but leaves `aria-label` stale and uses the card hidden badge markup in list mode.

   Problem: the initial markup has good per-state labels (`recording_hide_from_students` / `recording_show_to_students`), but after AJAX success the button HTML changes without updating `aria-label`. In list mode, hiding a row prepends `.googlemeet-recording-hidden-badge`, which is the old card-style absolute badge, instead of toggling the existing list title badge (`.googlemeet-listitem-hidden-tag`). This makes the new visibility a11y state incorrect after the first toggle and can produce awkward visual output in list view.

   Exact fix: in the success callback, set the opposite next-action label after each state transition:

   ```js
   if (response.visible) {
     btn.attr('aria-label', '{{# str }} recording_hide_from_students, mod_googlemeet {{/ str }}');
   } else {
     btn.attr('aria-label', '{{# str }} recording_show_to_students, mod_googlemeet {{/ str }}');
   }
   ```

   Also branch the hidden badge update:

   - card: keep/remove `.googlemeet-recording-hidden-badge`.
   - list: add/remove `.googlemeet-listitem-hidden-tag` inside `.googlemeet-listitem-title`.

**Checked OK**

- [view.php](/home/preparaoposiciones/desarrollo/moodle-mod_googlemeet/view.php:70): `rview` is handled before output, accepts only `cards|list` via `PARAM_ALPHA` plus explicit validation, sets a same-site user preference, and redirects to `/mod/googlemeet/view.php` without `rview`. I do not see a redirect loop or open-redirect risk.
- [view.php](/home/preparaoposiciones/desarrollo/moodle-mod_googlemeet/view.php:74): redirect params preserve only `id`, positive `rpage`, non-empty `rorder`, non-empty `rq`, and non-empty `topic`. Dropping `rview` is correct. The no-sesskey GET preference flip is acceptable for this low-impact per-user view setting.
- [locallib.php](/home/preparaoposiciones/desarrollo/moodle-mod_googlemeet/locallib.php:510): `isviewcards` / `isviewlist` are mutually exclusive and default back to cards for bad stored prefs. Toggle URLs preserve current page/order/query/topic through `moodle_url`.
- [templates/recordingstable.mustache](/home/preparaoposiciones/desarrollo/moodle-mod_googlemeet/templates/recordingstable.mustache:125): one recordings loop with card/list branches is structurally balanced. The shared AI panel row is outside both presentation branches and remains one per recording, so `googlemeet-ai-panel-{{id}}` stays unique.
- [templates/recordingstable.mustache](/home/preparaoposiciones/desarrollo/moodle-mod_googlemeet/templates/recordingstable.mustache:80): the view toggle radiogroup is acceptable for links styled as radios: `role="radiogroup"`, two `role="radio"` children, and `aria-checked` values match the PHP booleans.
- [templates/recordingstable.mustache](/home/preparaoposiciones/desarrollo/moodle-mod_googlemeet/templates/recordingstable.mustache:861): the keypoint plural JS path should substitute correctly. Moodle renders the string with `{$a}` as the third helper argument, leaving a literal `{$a}` in the translated output, and the JS `.replace('{$a}', keypointsCount)` then fills the live count.
- [styles.css](/home/preparaoposiciones/desarrollo/moodle-mod_googlemeet/styles.css:985): `.googlemeet-ai-panel-row` is collapsed with `display:none`, opens as a full-width grid item in card view via `grid-column: 1 / -1`, and behaves as a full-width block child in list view. No grid hole issue remains.
- [styles.css](/home/preparaoposiciones/desarrollo/moodle-mod_googlemeet/styles.css:1400): the card media grid and top-right badge stack address the `"Nueva"`/AI/play overlap. The `+N` overflow chip is explicitly non-shrinking and should remain visible.

**Must-Fix Before Ship**

1. Fix live AI row updates to target both `.googlemeet-recording-card` and `.googlemeet-recording-listitem`.
2. Update visibility toggle `aria-label` after AJAX success and use list-specific hidden badge markup in list mode.
