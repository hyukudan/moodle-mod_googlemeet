# Live Recordings List UX Fix Assessment

## Assessment

Yes. I agree with the product owner's two reported problems, and the measured page data points to a clear cause:

1. The top filter wall is caused by rendering every distinct AI topic from the activity as a chip.
   - `locallib.php` fetches all recordings before pagination so filters work correctly across the whole activity.
   - `googlemeet_collect_topics()` in `lib.php` returns every distinct non-empty topic, sorted naturally, with no cap, grouping, frequency threshold, or length normalization.
   - `templates/recordingstable.mustache` renders every `{{#alltopics}}` item inside `.googlemeet-recordings-topicfilter`.
   - With 117 long topics, the chip UI becomes a 1121px wall between search and results.

2. The over-long cards are caused by rendering the full `aitopics` array on every card.
   - `googlemeet_list_recordings()` assigns `aitopics = json_decode($ai->topics) ?: []`, so every stored topic is passed through.
   - `templates/recordingstable.mustache` renders all `{{#aitopics}}` in `.googlemeet-card-topics`.
   - `.googlemeet-card-topics` is `display: flex; flex-wrap: wrap; gap: 0.25rem;` with no cap or max height.
   - The topics are not compact tags. They are long descriptive phrases, so each chip often occupies a full row.
   - The inline JavaScript path that updates card topics after AI generation also appends every returned `data.topics` item, so a server-side template cap alone would not fully defend the live interaction.

There is one additional contributing factor: `.googlemeet-recordings-grid` uses `align-items: stretch`. In CSS Grid this makes every card in a row match the tallest card. It does not create the 1484px worst-case card, but it spreads the worst-case height to otherwise shorter cards and makes the whole row look broken.

## Per-Card Topics UX

The card should remain a scan-and-decide preview, not the full AI topic inventory. The right UX is:

- Show at most 3 visible topic chips per card.
- Add a quiet `+N mas` overflow chip when more topics exist.
- Keep the full topic list in the recording hub and/or teacher AI panel, where detailed analysis belongs.
- Make the card topic row visually secondary and bounded to one or two lines at most.

Recommended implementation:

- In PHP, derive `aitopicsvisible` as the first 3 topics and `aitopicsoverflowcount` as `max(0, count(aitopics) - 3)`.
- Update the Mustache card to render `aitopicsvisible`, not `aitopics`.
- Render a final non-clickable overflow chip like `+24 mas` when `aitopicsoverflowcount > 0`.
- Apply the same cap in the inline JavaScript after AI generation or regeneration.
- Add a defensive CSS max-height for `.googlemeet-card-topics` of roughly 2 chip rows, with `overflow: hidden`, so malformed or manually edited legacy data cannot break the card.

I would not move topics entirely out of the card. One to three short topical signals are valuable for recognition in an exam-prep class list. The failure is volume and verbosity, not the presence of topics.

I would not rely only on clamping the container height. A clamp hides data without explaining that more exists. The `+N mas` chip is clearer and gives the user confidence that the card is intentionally summarized.

## Top Filter UX

The 117-topic filter should not be a chip bar. At this scale, chips are the wrong control, especially because the labels are long descriptive phrases.

Recommended control:

- Replace the full chip bar with a compact select/dropdown labeled by the existing filter area, using one option per topic.
- Keep the free-text search as the primary discovery control.
- Show the active selected topic as filter state near the search field, with the existing clear-filters action.

Why select/dropdown:

- It collapses 117 choices into one row.
- It supports exact topic filtering without flooding the page.
- It works better than top-K chips for long-phrase topics because top-K would hide many valid exact filters and create an arbitrary "why is my topic missing?" problem.
- It is simpler and more Moodle-native than building a custom searchable combobox.

If there is appetite for a P1 refinement, make the select searchable with Moodle's existing form/select enhancement if available in this context. But the P0 fix should be a normal `<select name="topic">` plus submit/apply behavior, because it immediately removes the 1121px wall.

I would not remove topic filtering entirely. The server-side filter-before-pagination implementation is useful and mostly correct. The presentation control is the broken part.

## Root Cause: Gemini Topics Are Too Verbose

Yes, the Gemini prompt should be fixed. The current prompts in `classes/gemini_client.php` ask for "main topics/themes" or "Educational topics/themes covered" but do not specify cardinality, length, or tag style. The model is therefore producing curriculum-outline phrases instead of tags.

Change each analysis prompt that emits `topics` to specify:

- Topics must be short tags, not sentences.
- Return 3 to 6 topics maximum.
- Each topic should be 1 to 3 words where possible.
- Hard cap around 40 characters per topic.
- Prefer stable course vocabulary over procedural descriptions.
- No duplicates or near-duplicates.
- Put detailed descriptions in `summary` or `keypoints`, not `topics`.

Sketch:

```text
3. Topics: 3-6 short study tags in the same language as the video.
   Each topic MUST be 1-3 words where possible and no more than 40 characters.
   Do NOT write full sentences, procedural descriptions, or long legal headings.
   Prefer compact labels suitable for UI chips, e.g. "Caducidad", "LPAC", "Procedimiento sancionador".
```

And update the JSON example:

```json
"topics": ["Caducidad", "LPAC", "Procedimiento sancionador"]
```

This prompt fix is necessary but not sufficient. Existing stored analyses will still contain long topics until regenerated or manually edited, and manual teacher edits can also introduce long topics. The UI cap and CSS defense are still required.

## Grid Stretch

Fix it. Change `.googlemeet-recordings-grid` from `align-items: stretch` to `align-items: start`.

This prevents one pathological card from forcing the rest of its row to the same height. It will not solve long cards by itself, but it reduces the visual blast radius and makes the grid behave like independent preview cards.

If equal-height cards are still desired later, use bounded internal regions rather than stretching the entire card to unbounded content.

## Other UX Changes To Make Now

- Keep the current pagination. The live data confirms pagination works at 5 cards per page; the issue is density inside the page, not total record count.
- Preserve the compact 88px media strip. It is not the problem and it keeps the list scannable.
- Keep title and summary clamps. The measured title and summary heights are acceptable.
- Keep topic chips visually quiet in cards. They should read as metadata, not primary actions.
- Include the selected topic in sort and pagination URLs consistently. The sort links already include `topic`; verify pagination does too when implementing the dropdown.
- Consider topic frequency counts in the dropdown label later, e.g. `Caducidad (12)`, but only after normalizing topic generation. With the current long phrases, frequency may be too fragmented to help.
- Avoid adding another large expandable filter panel above the cards. The user's first useful content should be visible immediately after search/sort controls.

## Prioritized Fix List

### P0

1. Cap card topics to 3 visible chips plus `+N mas`.
2. Apply the same cap in the inline JavaScript AI-update path.
3. Add a defensive max-height/overflow rule to `.googlemeet-card-topics`.
4. Replace the full topic chip filter wall with a compact `<select name="topic">` control.
5. Change `.googlemeet-recordings-grid` to `align-items: start`.
6. Add/adjust tests for topic limiting context if implemented in PHP helpers.

### P1

1. Update all Gemini analysis prompts that emit `topics` so topics are short UI tags: 3-6 max, 1-3 words, roughly 40 characters max, no sentences.
2. Consider a migration/admin action to regenerate old analyses, or document that old recordings keep legacy long topics until regenerated.
3. Add frequency-aware topic ordering for the dropdown once topics are normalized.
4. Optionally add a searchable enhanced select if the activity can regularly exceed 50 normalized topics.
