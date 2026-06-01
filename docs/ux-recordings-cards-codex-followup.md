# Follow-up UX assessment: refined recordings card layout

Verdict: **ship cards, but change the card approach before implementation.**

The refined Direction B is much stronger than the original card grid. The fixed media zone, text clamps, equal-height flex cards, date-group headings, and explicit AI status chips address the most obvious failure modes. I no longer reject cards on the original "ragged heights" objection alone.

But I still would not ship the exact proposed card. For weekly exam-prep recordings with near-identical titles and no thumbnails, a large 16:9 branded gradient is too much visual real estate for too little information. It makes the page look like a video gallery while the real task is still a study index: find the right class by date, topic, status, and summary.

## 1. Does fixed 16:9 + line-clamp resolve the ragged-height objection?

**Yes, mechanically. No, strategically.**

The fixed 16:9 media zone and clamps solve the specific layout defect I objected to:

- Cards will align vertically.
- Summary/topic variance will not create masonry-like ragged rows.
- Date-group headings can span full rows cleanly.
- Footer CTAs can stay pinned, so actions are predictable.

That is a real improvement.

However, it introduces new costs:

- **Fewer recordings above the fold.** A 3-column desktop grid with 16:9 media zones spends roughly half of each card on a placeholder that carries almost no unique information. The first viewport may show 3-6 classes instead of a denser chronological set.
- **Date scanning is still weaker than a list.** Month headings help, but within a month the user's eye must zig-zag across rows and columns. A vertical list preserves one chronological axis. A grid asks students to scan left-to-right and then down, which is slower for "find the class from May 14" behavior.
- **Line-clamp hides differentiators.** Clamping is necessary in a grid, but for near-identical titles it can hide the few words that distinguish one class from another. If every title starts with "Clase online oposiciones..." the useful part may be pushed out of view.
- **Equal height does not mean equal comprehension.** The layout becomes orderly, but not necessarily more scannable.

So: the refined design fixes raggedness, but it does not fully fix the product problem that led me to recommend a compact media-list.

## 2. Is the big gradient media zone a good use of space without thumbnails?

Mostly **no**.

A centered play affordance is useful. A large decorative gradient pretending to be a thumbnail is not. Without a real image, slide preview, teacher face, or generated chapter frame, the media zone repeats the same message on every card: "this is playable." After the first card, that becomes decorative noise.

For this content type, the media area should earn its space by carrying information. Until real Drive thumbnails exist, I would reduce it or make it more data-rich.

Better options:

1. **Compact media strip, preferred.**
   Use a shorter fixed-height strip, around `72-96px` desktop, not full 16:9. Keep the centered play button, duration chip, "Nuevo", and "Resumen IA", but add the strongest date signal inside the strip: large day number + month. This turns the visual block into a date/play anchor instead of a decorative banner.

2. **Hybrid card with left date/play rail.**
   Keep a card container, but put a fixed-width left rail with play, date, duration, and status. Body content sits to the right. This preserves the owner's "card" preference while recovering much of the list's scanability.

3. **16:9 only when there is a real thumbnail.**
   Once `thumbnailLink` is persisted and reliable, the 16:9 zone becomes defensible. Until then, use the gradient as a fallback, not as the default dominant surface.

If the owner insists on full 16:9 now, make the media zone less decorative: include the short date prominently in the zone and vary the visual signal by month/topic/status, not by arbitrary gradient styling.

## 3. Best version of the card approach

If cards are the product decision, I would ship this version:

### Grid behavior

- Desktop: use **2 columns by default**, not 3, unless testing shows titles/topics remain readable at the site's real content width.
- Wide desktop only: allow 3 columns at a generous breakpoint, e.g. `min-width: 1280px`.
- Tablet: 2 columns.
- Mobile: 1 column.
- Keep date-group headings full-row, but add enough top margin before a new month and less margin after the heading so the heading clearly owns the following cards.

Reason: 3 columns plus 16:9 placeholders makes each card content area narrow, which worsens near-identical title scanning. Two columns gives titles, dates, and chips room to do useful work.

### Media zone

Recommended media zone if no thumbnail:

- Height: fixed compact strip, not full 16:9. Suggested desktop `88px`, mobile `72px`.
- Left/center: play button, large enough to be obvious but not dominant.
- Bottom-right: duration.
- Top-left: "Nuevo" only when true.
- Top-right: "Resumen IA" only when completed.
- Include a strong date mark: day number and short month, e.g. `14 may`, either in the media zone or immediately below it as the first body row.

If using full 16:9 anyway:

- Add the date into the media zone.
- Keep the gradient visually quiet. Avoid making every card a large saturated blue tile.
- Do not show both a large play button and a primary "Ver clase" button with equal visual weight. Make one primary and the other clearly secondary.

### Body content

Order the body for recognition, not decoration:

1. Short date + duration + AI status row.
2. Title, clamped to 2 lines.
3. Summary preview, clamped to 2 lines only when `hasai`.
4. Topic chips, max 3, with `+N` if there are more.
5. Footer actions.

For near-identical titles, add a generated differentiator if available:

- First topic chip should be promoted visually as the "main topic".
- If AI completed, consider a one-line "topic lead" above the summary.
- If no AI completed, the short date must become the dominant differentiator.

Do not rely on title alone. These recordings likely share boilerplate names.

### Topics and status

- Topic chips should be quiet, not saturated blue pills. They should look like metadata unless they are clickable filters.
- If topic chips become filters in Phase 3, make them anchors/buttons and preserve the selected topic in pagination.
- AI statuses should be text chips, not color-only indicators.
- Failed student copy should be soft: "Resumen no disponible" rather than "failed"; teacher copy can be explicit and actionable.

### Footer and actions

- Primary CTA: `Ver clase`, full clarity.
- Secondary Drive/action icons: grouped at the end with accessible names.
- Avoid three separate links to the same hub with identical accessible purpose. If media, title, and CTA all point to the hub, their labels must be distinct enough for screen readers or one of them should be removed from the tab order.
- Teacher controls should be visually separated from student actions. Do not let edit/hide/AI controls compete with `Ver clase`.

### Hover/focus

- Hover may raise the card slightly, but do not depend on hover to reveal core actions.
- Apply visible focus to the media link, title link, CTA, Drive link, and teacher buttons.
- If the media zone is clickable, show a clear focus outline around the media zone, not only the play icon.
- Respect reduced motion: disable transform transitions under `prefers-reduced-motion: reduce`.

### Mobile

- One column.
- Prefer a compact media strip or horizontal date/play header. Full 16:9 gradient on mobile creates long repetitive scrolling.
- Keep `Ver clase` as a full-width or near-full-width button.
- Secondary actions can sit in a compact row below.
- Do not show icon-only controls without accessible labels; touch users do not get hover titles.

## 4. Accessibility and information-scent risks

Main risks:

- **Duplicate links to the same destination.** Media zone, title, and CTA all linking to `huburl` can create repetitive screen-reader navigation. Prefer title + CTA, or media + CTA. If all three remain, use specific labels: "Reproducir clase: {name}", "{name}", and "Ver clase".
- **Clickable media zone may hide the real information scent.** A big play surface says "watch now" but not "is this the right class?" The title/date/topics must be visually stronger than the placeholder.
- **Icon-only actions with `title` are insufficient.** Keep `aria-label`s and consider visible text for primary student actions on mobile.
- **Badges inside a link can create verbose accessible names.** If the media link contains duration, new, AI badge, and play, ensure the accessible name does not become a noisy concatenation. Use `aria-hidden="true"` on decorative/internal badge text if the link already has a clean `aria-label`.
- **Status chips must not rely on color.** The proposed text chips are good; preserve text labels.
- **Expandable AI panel controls need real button semantics.** The plan still keeps `href="javascript:void(0)"` in places for JS compatibility. That is not ideal. If feasible, migrate action links to `<button type="button">` while preserving classes/data attributes.
- **Student toggle risk.** If the heavy AI panel becomes teacher-only, hide the AI toggle for students. Do not leave a toggle pointing to a missing panel.

## Final verdict

**Ship cards, but change X/Y:**

- **X: do not use a large 16:9 gradient placeholder as the default dominant element without real thumbnails.** Use a compact media strip or hybrid date/play rail now; reserve full 16:9 for real Drive thumbnails.
- **Y: make date/topic differentiation stronger than the play artwork.** For weekly exam-prep classes, the card's job is recognition first, playback second.

If the owner requires the full 16:9 centered-play card exactly as written, I would still consider it shippable only as a compromise, because the fixed heights remove the original ragged-grid defect. But the best product version is a card-index hybrid: visually card-based, centered play affordance retained, with compact media and stronger date/topic information scent.
