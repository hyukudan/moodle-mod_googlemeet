# Final UX/UI QA: Recordings List

Context reviewed: `styles.css` and `templates/recordingstable.mustache`. The deployed behavior described matches the current implementation: the card media strip is `position: relative`, the new and AI badges are absolutely positioned, the CTA and four teacher actions share one flex row, topic chips are rendered as `3 + overflow` but the chip container is height-clamped, and the expanded AI panel is nested inside the card while `.googlemeet-ai-expanded` makes the card span the full grid row.

## 1. "Nuevo" Badge Overlaps Play Circle

This is a must-fix. The current CSS puts `.googlemeet-card-newbadge` at `left: 8px; top: 8px`, while the play circle sits in normal flex flow at the left side of the same 88px media strip. On an 88px strip, top-left badge plus vertically centered 40px play control collide.

Best fix: keep the play circle as the primary left-side affordance and move the status badges into a dedicated top-right badge stack. This preserves the visual hierarchy: play/date are the card identity; badges are metadata.

Recommended CSS:

```css
.googlemeet-card-media {
    display: grid;
    grid-template-columns: 40px minmax(0, 1fr);
    grid-template-rows: 1fr;
    align-items: center;
    column-gap: 0.6rem;
    padding: 1.5rem 0.75rem 0.55rem;
}

.googlemeet-card-play {
    grid-column: 1;
}

.googlemeet-card-date {
    grid-column: 2;
    min-width: 0;
    margin-left: 0;
}

.googlemeet-card-newbadge {
    left: auto;
    right: 8px;
    top: 8px;
}

.googlemeet-card-aibadge {
    right: 8px;
    top: 30px;
}

.googlemeet-card-duration {
    right: 8px;
    bottom: 6px;
}
```

If both "Nuevo" and "Resumen IA" are common, stacking them top-right is better than making them compete with the play button. A slightly cleaner variant is to add a wrapper in the template, for example `.googlemeet-card-badges`, but the CSS-only fix above is enough.

## 2. "Ver clase" Wraps In 289px Cards

This is also a must-fix. In `templates/recordingstable.mustache`, the footer contains one text CTA plus up to four icon actions. In `styles.css`, `.googlemeet-card-footer` is a single flex row with `justify-content: space-between`; at roughly 289px wide, that row does not have enough room.

Best fix: make the CTA stable and move teacher icon actions to a second row when needed. Do not let the primary action wrap to two lines. The "Ver clase" button is the highest-priority action and should remain a compact one-line target.

Recommended CSS:

```css
.googlemeet-card-footer {
    flex-wrap: wrap;
    align-items: center;
}

.googlemeet-card-cta {
    white-space: nowrap;
    flex: 0 0 auto;
}

.googlemeet-card-actions {
    margin-left: auto;
    flex: 0 0 auto;
}

@media only screen and (min-width: 1280px) {
    .googlemeet-card-footer {
        align-items: flex-start;
    }

    .googlemeet-card-actions {
        width: 100%;
        justify-content: flex-end;
        margin-left: 0;
    }
}
```

Because the 3-column desktop cards are the tightest desktop case, the media query targets that layout. Another acceptable option is always two footer rows for teacher cards. I would not hide actions, because teachers need Drive, visibility, edit, and AI controls, but those should be secondary to the class entry action.

## 3. Topic Overflow "+N" Is Clipped

Recommendation: reduce visible chips to two in the card data layer and always render `+N` as the third chip. Keep the card clamp. Do not raise or remove the clamp.

Reasoning:

- The card is an index surface, not the full taxonomy view.
- Long AI topics are phrase-like, not short tags, so 3 visible topics plus overflow does not reliably fit into two rows.
- Removing the clamp makes card heights uneven and pushes the footer around.
- Dropping the overflow chip entirely loses useful information that more topics exist.

Best behavior for cards: show at most two topic chips plus `+N`. The overflow chip should be guaranteed visible. This is better fixed server-side where `aitopicsvisible` and `aitopicsoverflow` are prepared, but CSS can support it:

```css
.googlemeet-card-topics {
    max-height: none;
    display: flex;
    flex-wrap: nowrap;
    overflow: hidden;
}

.googlemeet-card-topics .googlemeet-ai-topic-tag {
    max-width: min(11rem, 45%);
    flex: 0 1 auto;
}

.googlemeet-card-topics .googlemeet-topic-overflow {
    flex: 0 0 auto;
    max-width: none;
}
```

Preferred implementation: change the PHP preparation from `3 + overflow` to `2 + overflow` for the compact card view, then use a single-row or two-row layout where the overflow chip cannot be clipped.

## 4. Expanded Card Leaves A Grid Gap

This needs improvement. The current behavior is understandable technically, but it reads broken visually when a middle card expands and leaves a hole in the original row. The cause is structural: the AI panel lives inside the card, then `.googlemeet-ai-expanded { grid-column: 1 / -1; }` changes that same card's grid placement after layout.

Best fix: render the expanded AI panel as its own full-width grid item immediately after the card's logical row, instead of making the card itself span all columns. That means the original card stays in its cell, the grid remains dense and predictable, and the details area appears as a full-width disclosure below the row.

Recommended direction:

- Keep each `.googlemeet-recording-card` as a normal one-column card.
- On expand, insert or reveal a sibling `.googlemeet-ai-panel-row` with `grid-column: 1 / -1`.
- Place it after the current row, not necessarily directly after the card DOM node if that card is column 1 or 2 in a 3-column grid.

Minimal CSS for the sibling approach:

```css
.googlemeet-recording-card.googlemeet-ai-expanded {
    grid-column: auto;
}

.googlemeet-ai-panel-row {
    grid-column: 1 / -1;
}
```

If the AI detail view is intended to be rich and teacher-only, the better product direction is to move full AI review/editing into the recording hub and keep the list expansion to a compact preview. The current full-width inline panel is useful but heavy for an index page.

## 5. Expanded "Temas" Block Is Too Saturated

Quieter style recommended. It is the detail view, so it can show more topics than the card, but the current `.googlemeet-ai-topic-badge` uses saturated blue filled pills for every long phrase. With many topics, that becomes a large blue block and competes with the summary and key points.

Recommended CSS:

```css
.googlemeet-ai-topic-badge {
    background: #f1f3f4;
    color: #3c4043 !important;
    border: 1px solid #dadce0;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 500;
    max-width: 18rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.googlemeet-ai-topics-tags {
    gap: 6px;
    max-height: 6.5rem;
    overflow: hidden;
}

.dark .googlemeet-ai-topic-badge {
    background: #2a2c2e;
    color: #c4c7c5 !important;
    border-color: #3c4043;
}
```

If the full list is important, add an explicit "show all" affordance later. For now, cap it. Long phrase topics should not dominate the expanded panel.

## 6. Overall Visual QA

The page is close to a clean, professional class index. The card model is solid: date grouping, compact metadata, summary preview, visible primary action, and teacher controls all make sense. The current issues are mostly collision and density problems caused by fitting teacher controls and AI metadata into compact cards.

Additional visual nits I would fix:

- The media strip currently has no real thumbnail, so the blue gradient repeats heavily. It is acceptable for now, but keep it restrained because every card starts with the same visual block.
- `.googlemeet-recording-has-ai` adds a left blue border to the card while the media strip and AI badge are also blue. Consider making the AI state less emphatic once the badge is present.
- Card border radius is `12px`; Moodle UIs often feel cleaner at `8px`. This is not a blocker, but 8px would look more native and less app-like.
- The expanded panel uses `border-radius: 12px` inside a 12px card and several nested white sections. This is visually heavier than the list needs. If inline expansion remains, reduce the panel styling and make it feel like a disclosure, not a second page inside the card.

## Priority

P0 must-fix before ship:

- Fix "Nuevo" badge collision with the play circle.
- Stop "Ver clase" from wrapping to two lines.
- Ensure the topic overflow `+N` chip is visible or change compact cards to `2 + overflow`.
- Fix or remove the middle-card full-width expansion behavior that leaves a grid hole.

P1 nice-to-have:

- Quiet the expanded "Temas" pills and cap the block height.
- Reduce duplicate blue emphasis on AI cards.
- Consider 8px card/panel radii for a more Moodle-native feel.
- Decide whether the full AI detail panel belongs in the recording hub rather than inline in the list.

## Verdict

SHIP WITH FIXES.

The core structure is professional enough, but the measured overlap, wrapped primary CTA, clipped overflow indicator, and expansion gap are visible quality issues. Fix the P0 items and this can ship confidently.
