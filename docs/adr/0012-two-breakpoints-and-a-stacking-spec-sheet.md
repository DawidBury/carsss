# 0012 — Two breakpoints, and the spec sheet stacks below the smaller one

## Context

The screen was built against the sketch, which was drawn at one width. Only one layout rule in the
whole codebase ever reacted to the viewport — the three selects collapsing to one column at 900px.
Everything else was fixed, and on a phone the result is not merely tight, it is unreadable.

`.spec-sheet-group` is a two-column grid, `170px minmax(0, 1fr)`, inside a panel that is inside the
screen's padding. The fixed column and the insets consume a constant **274px** of viewport
(2 × 20.4 screen padding, 2 panel border, 2 × 20.4 group padding, 170 label column, 20.4 gap), so
the column that carries every technical value a visitor came for is `viewport − 274`.

Measured in Chrome against the running dev server and live API, at forced layout widths:

| viewport | value column | tallest spec row | narrowest row label |
|---|---|---|---|
| 320 | 46 px | 108 px | **0 px** |
| 360 | 86 px | 59.4 px | **0.4 px** |
| 390 | 116 px | 59.4 px | 30.4 px |
| 430 | 156 px | 59.4 px | 70.4 px |
| 500 | 226 px | 38.3 px | 87.6 px |
| 900 | 626 px | 38.3 px | 549.3 px |

A row is 38.3px tall when it fits on one line. At 320 and 360 the label column is crushed to
**zero** and the label text is painted **on top of the value** — `Pojemność skokowa` overprints
`1 598 cm³`. That is the reported defect, and it is worse than wrapping: the numbers are not
squeezed, they are illegible.

Meanwhile the 170px column holds `SILNIK`, which is 37px of text.

There is **no horizontal overflow**. `documentElement.scrollWidth === clientWidth` at every width
tested, and the longest single unbreakable token in the catalogue — `EfficientDynamics`, 17
characters — renders inside its box with
`scrollWidth - clientWidth === 0` on `.spec-sheet-title` at 320px. Nothing needs to be clamped or
broken. The problem is entirely one of allocation.

Sweeping the width to find where rows stop wrapping, on the two cars with the widest values in the
database (BMW 320i, and the Bugatti Veyron at `1 001 KM (736 kW)` / `24,2 l/100 km` / automatic):

| value column | rows wrapped |
|---|---|
| 210 px | 1–3 |
| 218 px | 0–2 |
| **226 px** | **0** |

**226px** is what the value column needs. Two columns deliver it only at a viewport of 500px or
wider — `500 − 274 = 226`, exactly the measurement.

## Decision

**Two breakpoints, both `max-width`, for the whole screen: 900px and 640px.**

**900px is kept as it is** and keeps its current meaning: below it the three selects are one
column. It is measured — the longest placeholder, `— za dużo wersji, użyj wyszukiwarki —`, needs
about 265px per control, and three of those plus the gaps and insets stop fitting at ~890px.
Nothing about this task changes that number.

**640px is new.** Below it the screen is a phone: the spec sheet's groups and its share row become
one column, the sheet header stacks, the display title drops from 34px to 26px, and the screen and
panel insets drop from `--space-6` to `--space-4`.

640 is chosen over the 504px floor the measurement demands because a breakpoint sitting 4px above
the failure point is not a margin. At 641px the two-column layout gives the value column 367px
against a requirement of 226px; below 640 the stacked layout gives it `viewport − 56.4`, which is
**263.6px at a 320px phone** — still above the requirement, with 37px to spare. 640 also falls in
the empty band between the widest phone in portrait (430) and the narrowest tablet (600), so no
common device sits on the edge.

The reduced insets are **load-bearing, not cosmetic**: at 320px they are the difference between
263.6px and 236.4px of row width, against a 226px requirement. Keeping `--space-6` on a phone
would leave a 10px margin.

`.search-screen`'s `min-height` changes from `100vh` to `100dvh`. On a phone `100vh` includes the
browser toolbar, so a short state — the empty state — becomes scrollable by the height of that
toolbar with nothing to scroll to. On desktop the two units are identical.

The three selects take `font-size: 16px` **inside the existing 900px block only**. iOS Safari
zooms the page when a form control with a computed font size below 16px takes focus; the search
field already sets 16px for this reason, the selects inherit 14px from `.input`, and `.input` lives
in vendored `styles.css` which is not ours to edit. Below 900px the selects are full-width, so
16px costs nothing there.

**Desktop rendering is unchanged.** Every rule above is inside a `max-width` query except the
`dvh` swap, which is identical to `vh` on a desktop viewport.

The per-component rule table — which file, which selector, what it becomes — is `docs/ui.md`.

## Consequences

- The spec sheet is legible on every phone from 320px up, with a measured margin rather than a
  hairline one, and the reading order is unchanged: the group label already precedes its rows in
  the DOM, so stacking makes the visual order match the DOM order instead of contradicting it. No
  screen-reader behaviour changes.
- `SpecRow.vue` is not touched. A stacked row gets 263.6px at 320px against a 226px requirement, so
  the label-and-value line never needs to break in two. The most obviously "mobile" change is the
  one that turns out not to be needed.
- `SearchCombobox.vue` is not touched either. The field and its `Wyczyść` button stay side by side;
  the reduced insets take the input from 139.6px to 166.8px at 320px, which is the whole fix it
  needed.
- The value `640` is written into three scoped stylesheets, because CSS has no way to share a
  breakpoint without a preprocessor and this project has none. The number's **home is
  `docs/ui.md`**; the three at-rules are its use sites. Adding a build step to deduplicate one
  integer would cost more than the duplication.
- The 900px breakpoint now carries a second rule, and a trap with it: moving the 16px select text
  outside that block would push the three-column minimum from ~890px to ~986px and break the
  breakpoint it was meant to serve.
- Between 641px and 900px the screen is in a genuine third state — spec sheet in two columns,
  selects in one. That is not an oversight; it is what the two independent measurements produce,
  and it renders correctly.

## Rejected alternatives

**One breakpoint for the whole screen.** The tidiest-looking option and the wrong one. The two
thresholds are independent measurements of different things: 890px is where three controls stop
fitting side by side, 504px is where a fixed 170px column stops leaving room for a value. Collapsing
them to one number means either stacking the spec sheet at 900px — discarding a layout that works
perfectly for 260px of viewport — or holding the selects three-across down to 640px, where they get
about 200px each and no placeholder fits.

**Solve the spec-sheet grid with no media query at all,** by making the group a wrapping flex
container: `flex: 0 0 170px` on the label, `flex: 1 1 230px` on the rows. This genuinely works, is
pixel-identical on desktop, and has the real merit of reacting to the container rather than the
viewport. Rejected for two reasons. The screen must change shape at **one** width: if the spec
sheet self-collapses at its own implicit threshold while the insets, the title size and the header
stacking change at 640px, the page reforms in two stages a few pixels apart, which reads as a bug
rather than a design. And the threshold becomes an emergent consequence of three numbers and a gap
token — it cannot be stated in `docs/ui.md` without recomputing it, and it cannot be verified by
reading the file.

**Container queries on the panel.** Same two-stage reflow, plus an `inline-size` containment
context introduced for a container whose width is a fixed function of the viewport anyway. It buys
nothing measurable here.

**Redefine `--space-6` inside the media query** instead of overriding six paddings. One lever for
all of them, and tempting. But `--space-6` is also the option indent inside the `base-select`
popup and a gap in several places that were never measured at this width. Retuning a design token
to move a padding changes things nobody looked at.

**Shrink the 170px label column rather than stack.** The widest group label,
`OSIĄGI I EKSPLOATACJA`, measures 132px, so the column cannot honestly go below about 140px. That
recovers 30px against a shortfall of 180px at 320px. It does not reach.

**Fix `.input`'s 14px in `src/app/styles.css`.** It is the direct fix for the iOS zoom and it is
forbidden: the file is vendored from `design/` unchanged (`src/app/CLAUDE.md`). It is also the
wrong fix — applied at every width it would widen the three selects until they no longer fit above
900px.

**`overflow-wrap: break-word` on the title as insurance against a future long token.** Measured, no
token in any version name overflows its box at 320px. An unproven defence against a hypothetical
is the kind of thing this project treats as a defect. If a 26-character token ever lands, it is one
declaration away.

**Stack `.spec-row` itself, label above value.** The reflex mobile pattern. Unnecessary here — see
the consequence above — and it would roughly double the height of the sheet on the device with the
least vertical room.
