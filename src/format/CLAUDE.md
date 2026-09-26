# `format` — Polish text and numbers

## Owns

The single place where an English enum becomes a Polish word and a number becomes a string.
Also the single definition of what an unknown value looks like.

Contracts: `numbers.ts`, `labels.ts`. Rules, tables and units: `docs/ui.md`. Reasoning: ADR 0009.

## Boundaries

- Imports only `api/types` for the enum unions. Nothing else from `src/`.
- Pure functions. No Vue, no state, no I/O, no `Date.now()`.
- Does not know what a spec sheet is. It formats a number or an enum; assembling rows is
  `spec-sheet/rows.ts`.
- This module and `.vue` templates are the **only** places Polish may appear. If Polish text is
  needed in `api`, `browse` logic or `app`, the design is wrong — move the text to the template or
  the value to this module.

## Decisions not to reverse

- **`Intl.NumberFormat('pl-PL')`, never hand-written separators.** Polish groups with a
  non-breaking separator, and modern ICU emits U+202F where the design mock used U+00A0. Hard-coding
  either character guarantees a mismatch with numbers the platform formats elsewhere.
- **Enum maps are `Record<Enum, string>` over the union.** A member added upstream must fail the
  build, not render `undefined`. Never widen the key type to `string`, and never add a `default`
  branch that hides a missing case.
- **One `UNKNOWN_VALUE`.** Every unknown renders identically. A row that invents its own dash is a
  defect.
- **No i18n library.** One locale, never switched. This module is the seam if that ever changes.

## Traps

- **`null` is not zero and not empty.** `formatLitres(null)` returns `UNKNOWN_VALUE`, never `0 l`
  and never `''`. This is the module's whole reason for owning the rule.
- **Fraction digits differ per field and are not a style choice.** Acceleration and consumption keep
  a trailing `,0`; displacement and capacities have none; power has none unless the value is
  actually fractional. A sheet mixing `11` with `9,4` reads as two precisions. The table is in
  `docs/ui.md`.
- **kW is derived, never served.** `round(powerHp × 0.7355)`. The constant lives here and nowhere
  else. It is a unit conversion, not an invented fact — it reproduces the figures the source embeds
  in version names.
- **`generation` and `bodyStyle` are never passed through this module.** They are unnormalised
  source labels with mixed casing (`B5`, `Sedan`, `rs4 avant`) and are rendered verbatim.
  Title-casing them turns `rs4` into `Rs4`, which is inventing a fact.
- **`fuelType` takes no `null` branch.** It is guaranteed present. Adding one would be dead code
  suggesting a case that cannot occur.
