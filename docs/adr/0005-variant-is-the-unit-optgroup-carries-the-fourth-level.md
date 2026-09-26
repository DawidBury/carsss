# 0005 — The variant is the user-facing unit; `<optgroup>` carries the fourth level

## Context

The backend taxonomy has four levels: `brand → model → version → variant`. The sketch was drawn
against three (`marka → model → wersja`).

The variant is the only level at which a car is fully determined. Acceleration, gearbox and drive
live on the variant; power, displacement, boot capacity and fuel type live on the version above it.

Measured on Audi A4: 354 variants across 193 versions. 124 of those versions have more than one
variant, and **111 of them have variants with different 0–100 times**. Version
`1.9 TDI 110KM 81kW 1995-2000` has two variants — manual 11.3 s and automatic 12.8 s.
`S4 Limousine 3.0 TFSI 333KM 245kW 2009-2011` has manual 5.1 s / 9.7 l and automatic 5.3 s /
9.4 l.

Collapsing the fourth level therefore does not lose a nuance — it displays a wrong number for the
majority of versions.

## Decision

The variant is the unit of everything user-facing: the search result row, the spec sheet, the
share URL. The version is a grouping label and never a destination.

The drawn layout of three selects is kept. The third select, labelled `Wersja` as drawn, lists
**variants grouped by version** using native `<optgroup>`:

```
<optgroup label="1.9 TDI 110KM 81kW 1995-2000">
  <option>manualna · przedni</option>
  <option>automatyczna · przedni</option>
</optgroup>
```

The group label is the version name; each option is one variant, identified by its gearbox and
drive. Ordering rules are in `docs/ui.md`.

## Consequences

- Four taxonomy levels are expressed in three controls, with no change to the drawn layout and no
  invented widget.
- `<optgroup>` is native: keyboard navigation, type-ahead and screen-reader group announcement come
  free and correct.
- Every number the spec sheet shows belongs to the car the user actually selected.
- A version with a single variant still renders a group containing one option. Slightly redundant,
  and deliberately so — two shapes for the same thing is worse than one redundant one.
- The search result row must render gearbox and drive, or two hits from one version become
  indistinguishable (`docs/ui.md`).

## Rejected alternatives

**A fourth select.** The most literal reading, and it breaks the approved three-column grid. Worse,
it would be populated for the majority of versions and empty-but-present for the rest, so the
layout would either shift or carry a permanently half-dead control.

**Collapse to the version and show one representative variant's numbers.** Simplest, and wrong: it
shows a 12.8 s car as an 11.3 s car for 111 of Audi A4's 193 versions. This is the failure the
whole decision exists to prevent.

**A flat third select listing variants with the version name repeated in every label.** Works, and
`<optgroup>` is strictly better: it says the same thing without repeating the version name once per
variant, and it gives assistive technology the grouping explicitly.

**A segmented control for the variant, appearing under the selects once a version is picked.**
A fourth control in disguise, appearing conditionally, shifting the layout on most selections.

**Show all of a version's variants side by side as a comparison.** A different and larger product
than the one that was designed. Not asked for.
