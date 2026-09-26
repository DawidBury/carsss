# 0007 — The spec sheet is remapped onto the fields the API actually serves

## Context

The sketch was drawn against an imagined backend. Its three spec groups — `Silnik`,
`Napęd i skrzynia`, `Osiągi` — are filled with fields this API does not have, and it omits fields
this API does have.

The missing ones are not an oversight to be fixed upstream: `../carsss/CLAUDE.md` §4 lists exactly
what is collected, and engine code, torque, cylinder layout, top speed and production years are
deliberately not among them. The backend is read-only and not ours to change.

## Decision

Keep the visual design exactly — layout, spacing, type scale, blueprint frame, three groups.
Replace what fills it. The full row-by-row table is in `docs/ui.md`; the shape of the change:

**Dropped, not faked** — kod silnika, moment obrotowy, układ cylindrów, prędkość maksymalna, lata
produkcji.

**Added** — spalanie średnie, pojemność zbiornika, pojemność bagażnika, rodzaj paliwa.

**Group three is renamed** from `Osiągi` to `Osiągi i eksploatacja`, because it now carries the two
capacities as well as the acceleration and consumption figures.

Power is rendered as `110 KM (81 kW)` with the kW figure **derived** as `round(powerHp × 0.7355)`.

## Consequences

- The sheet has 3 / 2 / 4 rows, which fits the sketch's own placeholder hint of four rows per
  group.
- Nothing on screen is invented. A field the source does not have is absent, and a field the source
  does not know for a particular car is an em dash (`docs/ui.md`).
- Losing production years hurts least: they are frequently embedded in `version.name`
  (`1.9 TDI 110KM 81kW 1995-2000`) and so still reach the user, in the heading, unparsed.
- Losing top speed hurts most — it is the one dropped field a visitor to a car-spec site expects
  next to 0–100. Adding it is a backend scraping change, not a frontend one, and it is a decision
  for the project owner to raise over there.
- Deriving kW is safe because it is a unit conversion of a value we hold, not a new fact. It agrees
  with the figures the source itself embeds in version names (`110KM 81kW` → our 81 kW).

## Rejected alternatives

**Show the sketch's fields as `—` until the backend collects them.** Rows that are permanently
empty for every car are not "unknown values", they are a promise the product does not keep.

**Ask the backend to collect the missing fields.** Out of scope by construction; the backend is
built and read-only. If the owner wants top speed, that is a change over there with its own ADR,
and this document is where the frontend's need for it is recorded.

**Fold the two capacities into a fourth group.** Would break the approved three-group shape for two
rows. Renaming one group label is a far smaller departure than adding a section.

**Show kW only, or KM only.** Polish car listings use KM as the primary unit and kW alongside it,
and the source itself prints both. Dropping either loses readers.
