# UX Review: Expanded AI Panel Grid Fix

Reviewed: `styles.css` working tree diff and `templates/recordingstable.mustache` AI toggle handler.

The JS confirms the important premise: the click handler resolves `btn.closest('.googlemeet-recording-card')` and adds/removes `googlemeet-ai-expanded` on the card element itself. Since `.googlemeet-recording-card` is a direct child of `.googlemeet-recordings-grid`, `grid-column: 1 / -1` is applied to the actual grid item, which is the correct CSS Grid target.

## Answers

1. Yes, `grid-column: 1 / -1` on `.googlemeet-ai-expanded` is the right quick fix. It makes the expanded card span all explicit grid columns at 2-column and 3-column breakpoints, and it is a no-op in the single-column mobile layout. The expected auto-placement reflow is acceptable: when a card changes span, following cards may move to later cells/rows. That is not a CSS bug; it is the cost of making one item full-row in an auto-placed grid. With default sparse auto-flow, the browser should not backfill later cards above it in a way that breaks visual reading order. `align-items: start` remains appropriate because it prevents equal-height stretching across the row. The date group heading already spans `1 / -1`, so it is compatible with this pattern.

2. Stretching the whole card is acceptable for this fix. The 88px media strip becoming full-width is not ideal, but it is not a blocker: it preserves the card's visual context and avoids DOM/template surgery. A cleaner structure would be to split the expanded AI panel into its own grid item immediately after the card, with JS toggling that sibling panel and giving only the panel `grid-column: 1 / -1`. That would keep the recording card compact and make the AI analysis a full-width detail row. It is a better medium-term option, but too much for a small CSS fix.

3. `max-width: 70rem` is good for readability. I would center it with `margin-left: auto; margin-right: auto;` so the content does not look accidentally narrow and pinned to the left inside a very wide full-row card. If Moodle page widths are normally modest, this will rarely matter, but centering is the more intentional full-width treatment.

4. The transcript should not block this fix. The current CSS already caps `.googlemeet-ai-transcript-text` at `max-height: 200px` with `overflow-y: auto`, so the full transcript is not dumping unbounded height in the current working tree. Longer term, a collapse/expand transcript affordance would be better than a permanent small scroll box, but it should be a separate UX change unless live testing still shows extreme panel height from summary/keypoints/topics.

5. Responsive behavior is correct. At `<=600px`, the grid is `1fr`, so `grid-column: 1 / -1` does not change layout. At default 2 columns and `>=1280px` 3 columns, it spans the full row as intended.

6. This is already the cleanest CSS-only fix because the panel is inside the card. CSS cannot make a non-grid-item child span the parent grid without changing layout structure or using brittle positioning. Given the current markup, making the containing grid item span is the right CSS-only move.

## Recommended Tweaks

Keep the current approach and add these small refinements:

```css
.googlemeet-ai-expanded .googlemeet-ai-content {
    max-width: 70rem;
    margin-left: auto;
    margin-right: auto;
}

@media only screen and (max-width: 600px) {
    .googlemeet-ai-expanded .googlemeet-ai-content {
        max-width: none;
    }
}
```

The mobile override is optional because `max-width` will not hurt in a narrow container, but it documents that the cap is a desktop readability constraint.

## Verdict

APPROVE with tweaks:

- Center `.googlemeet-ai-expanded .googlemeet-ai-content` inside the full-width card.
- Optionally reset the max-width on mobile for clarity.
- Do not restructure the template for this fix.
- Do not add transcript collapse/scroll work as part of this change unless live testing still shows excessive height; the current CSS already scroll-caps transcript text.
