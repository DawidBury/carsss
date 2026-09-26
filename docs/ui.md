# UI

How `design/Wyszukiwarka.dc.html` becomes components, what fills the spec sheet, how values are
formatted in Polish, and the combobox contract.

The sketch is read for **intent**. Its `<script type="text/x-dc">` block runs against
`design/mock-api.js`, whose data model is fictional; nothing in it is ported literally.

## Components

| Component | Sketch region | Owns |
|---|---|---|
| `app/SearchScreen.vue` | the whole page | the four pieces of client state, URL sync |
| `app/BlueprintPanel.vue` | `.card.blueprint` + four `.corner` | the frame, four call sites |
| `search/SearchCombobox.vue` | field, `Wyczyść`, match list | combobox roles, keyboard, live region |
| `browse/CascadingSelects.vue` | the three selects | brand → model → variant cascade |
| `spec-sheet/SpecSheet.vue` | result card | header, three groups, share link |
| `spec-sheet/SpecRow.vue` | one `k` / `v` row | label, value, `null` rendering |
| `app/ErrorPanel.vue` | error card | message, retry |
| `app/EmptyState.vue` | examples card | example queries |

The loading card and the payload debug panel from the sketch are **not** built. The payload panel
was a scaffolding device for agreeing a backend contract that now exists and is documented
upstream. The skeleton loader is kept — it is the loading state of the spec sheet.

Layout, spacing, type scale, the blueprint frame and the three-group shape are taken from the
sketch unchanged. `design/styles.css` is vendored to `src/app/styles.css` as-is.

## The spec sheet

### Header

| Slot | Source | Example |
|---|---|---|
| kicker | `version.model.brand.name` · `version.model.name` | `Audi · A4` |
| title | `version.name` | `1.9 TDI 110KM 81kW 1995-2000` |
| meta | generation/body · gearbox · drive | `B5 · Sedan · manualna · przedni` |

The meta line repeats the gearbox and drive that also appear as rows in group two. This is
deliberate: the title is a version name and is therefore ambiguous — one version has several
variants — so the heading needs the two attributes that tell them apart. Measured: 124 of Audi
A4's 193 versions have more than one variant.

The meta line's first segment follows the API's exclusivity rule: either `generation` and
`bodyStyle`, or `unresolvedSegment`, or neither. Measured across every version: 73.0 % have
generation and body, 17.8 % have an unresolved segment, 9.2 % have none. When there is none the
segment is omitted — not rendered as a dash, because the header is prose, not a table.

`version.generation` and `version.bodyStyle` come from the source unnormalised and with **mixed
casing** — `B5`, `Sedan`, `Avant`, `Limousine Facelifting`, but also `rs4`, `rs4 avant`. They are
rendered **verbatim**. Title-casing them would be inventing a fact about a label the source did not
normalise, and would turn `rs4` into `Rs4`.

No id is displayed. The sketch's `resultMeta` printed `id: bmw/e91/325xi-at`; those were readable
slugs, and ours are opaque UUIDs that mean nothing to a person.

### Groups

Three groups, as drawn. The third is renamed from `Osiągi` to `Osiągi i eksploatacja` because it
now also carries the two capacities the backend serves and the sketch did not have.

| Group | Row label | Source | Format |
|---|---|---|---|
| **Silnik** | Pojemność skokowa | `version.engineDisplacementCcm` | `1 896 cm³` |
| | Moc | `version.powerHp` | `110 KM (81 kW)` |
| | Rodzaj paliwa | `version.fuelType` | `diesel` |
| **Napęd i skrzynia** | Skrzynia biegów | `gearbox` | `manualna` |
| | Napęd | `drive` | `przedni` |
| **Osiągi i eksploatacja** | 0–100 km/h | `acceleration0To100S` | `11,3 s` |
| | Spalanie średnie | `fuelConsumptionL100Km` | `4,9 l/100 km` |
| | Pojemność zbiornika | `fuelTankL` | `62 l` |
| | Pojemność bagażnika | `bootCapacityL` | `440 l` |

Rows are always rendered, in this order, whether or not the value is `null`. A sheet whose shape
changes per car cannot be scanned or compared.

`kW` is **derived**, not served: `round(powerHp × 0.7355)`. This is a unit conversion of a value we
have, not an invented fact, and it matches the figures the source itself embeds in version names
(`110KM 81kW`). The constant lives in `src/format` with the rest of the formatting.

### Fields the sketch showed that do not exist

Dropped, not faked. The backend does not collect them **by design** (`../carsss/CLAUDE.md` §4), so
no amount of frontend work produces them:

| Sketch field | Why it is gone |
|---|---|
| Kod silnika (`N46B20`) | not collected |
| Moment obrotowy (`200 Nm @ 3 600`) | not collected |
| Układ (`R4, benzyna, wolnossący`) | not collected |
| Prędkość maks. (`215 km/h`) | not collected |
| Lata produkcji (`2005–2012`) | not collected as a field; often present inside `version.name` |

Added, because the API serves them and the sketch had nowhere for them: fuel consumption, fuel
tank capacity, boot capacity, fuel type.

### Share link

The `Link GET` row renders `location.origin + location.pathname + '?v=' + variant.id`. The
`Kopiuj link` button writes it with `navigator.clipboard.writeText` and shows `Skopiowano` for
1 800 ms, as drawn. ADR 0006.

## Polish formatting

All of it lives in `src/format` and nowhere else. This is the only module allowed to contain
Polish strings outside a `.vue` template. ADR 0009.

### Numbers

`Intl.NumberFormat('pl-PL')`, never hand-rolled separators. Polish uses a comma decimal separator
and a non-breaking group separator; `Intl` produces both correctly and handles the cases a regex
does not.

| Field | Fraction digits | Rendered |
|---|---|---|
| `acceleration0To100S` | exactly 1 | `9,4 s`, `11,0 s` |
| `fuelConsumptionL100Km` | exactly 1 | `4,9 l/100 km` |
| `powerHp` | 0, up to 1 if fractional | `110 KM (81 kW)` |
| `engineDisplacementCcm` | 0 | `1 896 cm³` |
| `fuelTankL`, `bootCapacityL` | 0 | `62 l` |

Acceleration and consumption keep a trailing `,0` — a spec sheet where one row reads `11` and the
next `9,4` looks like two different precisions. `powerHp` is typed as a float and is integral in
every current version, so it is rendered without a decimal unless one is actually present;
`110,0 KM` would be noise.

### Enum labels

The only place these four closed enums become Polish.

| `gearbox` | | `drive` | | `fuelType` | |
|---|---|---|---|---|---|
| `manual` | manualna | `front` | przedni | `petrol` | benzyna |
| `automatic` | automatyczna | `rear` | tylny | `diesel` | diesel |
| | | `all` | 4×4 | `hybrid` | hybryda |
| | | | | `electric` | elektryczny |

The map is exhaustive over the union type, so adding a member upstream fails the build rather than
rendering `undefined`.

### `null`

`null` means *the source does not know*. It is never `0`, never `""`, never a hidden row.

- In a spec row: an em dash `—`, muted, with a visually hidden `brak danych` so a screen reader
  says something meaningful instead of "dash".
- In the header meta: the segment is omitted.
- In a select option label: the attribute is omitted from the label, never rendered as `—`.

This is not a theoretical case. Measured across every variant: boot capacity is `null` for
16.3 %, fuel tank 11.2 %, generation and body style 23.4 % each, consumption 4.5 %, drive 1.0 %,
gearbox once. Only `acceleration0To100S` and `version.fuelType` are never `null`.

## The search combobox

The field is a combobox over a listbox of variant suggestions, following the ARIA APG combobox
pattern with `aria-activedescendant`. **DOM focus never leaves the input.**

### Roles

| Element | Attributes |
|---|---|
| `<input>` | `role="combobox"`, `aria-expanded`, `aria-controls="<listbox id>"`, `aria-autocomplete="list"`, `aria-activedescendant="<active option id>"` when open, `autocomplete="off"`, `spellcheck="false"` |
| `<ul>` | `role="listbox"`, `id`, `aria-label="Podpowiedzi wyszukiwania"` |
| `<li>` | `role="option"`, stable `id`, `aria-selected="true"` on the active option only |
| status | `role="status"`, `aria-live="polite"`, `aria-atomic="true"`, visually hidden |

`aria-activedescendant` is removed, not set to `""`, when no option is active.

### Keyboard

| Key | Closed | Open |
|---|---|---|
| `ArrowDown` | opens, activates first | next option, wraps to first |
| `ArrowUp` | opens, activates last | previous option, wraps to last |
| `Enter` | submits nothing | selects the active option; no active option → nothing |
| `Escape` | clears the field | closes and keeps the text; a second `Escape` clears |
| `Home` / `End` | caret | caret — **not** first/last option; the field is a text field first |
| `Tab` | — | closes, keeps the text, moves focus onward |
| printable | opens on the next settled result | re-queries, resets the active option |

The active option is scrolled into view with `block: 'nearest'`. Pointer hover does **not** change
the active option — a stray mouse moving over the list while someone types with the keyboard must
not steal the active row.

Clicking outside closes the list and keeps the text.

### The live region

Announces only **settled** results, never the pending state — a message per keystroke is unusable:

- `n` results → `Znaleziono 12 podpowiedzi`
- zero → `Brak podpowiedzi`
- error → `Nie udało się pobrać podpowiedzi`

The debounce already coalesces typing, so the region updates at most once per 250 ms.

### The suggestion row

Each row renders **brand, model, version name, and the gearbox and drive**. The last two are not
decoration and may not be dropped: the search unit is a variant, so two hits from the same version
are otherwise pixel-identical. Measured — `q=audi a4` returns 20 hits covering 14 distinct
versions, and `q=bmw` returns 20 covering 15, with one version appearing three times. A list with
three identical-looking rows is a broken list.

Generation and body style are appended when present, as a second muted line, matching the
`m.title` / `m.sub` pair in the sketch.

### Where it departs from the sketch, and why

The sketch renders matches as a two-column grid of cards, below the three selects, inside the same
panel. Kept: the border, typography, spacing and accent hover of those cards. Changed: the list is
**one column, directly beneath the search field**.

Two reasons, both structural rather than aesthetic. A `role="listbox"` traversed with
`ArrowDown`/`ArrowUp` is a linear list; a two-column grid either needs 2D arrow handling that no
screen-reader user expects from a listbox, or it reads in an order that does not match what the eye
sees. And results belong next to the control that produced them — the sketch places them below two
selects the user has not touched, so the popup appears to belong to the third one.

This is the one place the drawn layout is not reproduced exactly.

## States

| State | Condition | Rendering |
|---|---|---|
| empty | no query, no variant | example queries, as drawn |
| suggestions | query settled, hits > 0 | listbox |
| no results | query settled, hits = 0 | message, as drawn, copy below |
| loading | variant query pending | skeleton card, as drawn |
| error | variant or search query failed | error panel + retry |
| gone | `?v=` returned `404` | its own message, no retry |
| result | variant loaded | spec sheet |

Suggestions and the spec sheet coexist: picking a suggestion closes the list but the field keeps
its text, so the user can reopen it and pick a different variant without retyping.

### Copy that the data forces

The sketch's no-results line reads *"Sprawdź pisownię albo zawęź wybór selectami powyżej"*. Both
halves are wrong here. The API already corrects typos, so telling the user to check spelling sends
them after a problem the server has solved; and the selects cannot narrow a text query — the search
endpoint accepts no filters at all.

The real reason for zero results today is that the import is partial. `q=golf` and
`q=passat 1.9` both return nothing because Volkswagen currently has only Caddy, ID.5, Multivan,
T-Roc and Taigo. The copy says so:

> Brak wyników dla „…”. Baza jest w trakcie importu — nie ma w niej jeszcze wszystkich modeli.

### Example queries

The sketch hardcodes BMW E91, BMW E46, Audi A4 B7 and VW Golf V GTI as brand/model/version id
triples. **Three of the four do not exist in this database**, and the ids were slugs from the mock.

Example chips are therefore plain **query strings** typed into the search field, not ids, and the
set must be re-verified against the live API whenever it is edited — a chip that returns nothing is
worse than no chip. Verified at design time: `audi a4 tdi` (20 hits), `bmw` (20), `alfa romeo 156`
(20), `audi tt quattro` (9).

## The three selects

Labels stay as drawn: `Marka`, `Model / generacja`, `Wersja`. Placeholders and the disabled cascade
stay as drawn — `— najpierw marka —`, `— najpierw model —`.

`Model / generacja` is a slight lie inherited from the sketch: `GET /v1/models` returns a name only
(`A4`), and generation lives on the version below it. The label is kept because the model select is
still where a user looks for `A4`, and the generation then appears in the third select's group
labels and in the result header.

The third select carries two taxonomy levels at once:

```
Wersja
  ├─ 1.9 TDI 110KM 81kW 1995-2000     <optgroup>, the version
  │    manualna · przedni              <option>, one variant
  │    automatyczna · przedni          <option>, one variant
  └─ 2.0 TDI 140KM 103kW 2004-2008
       manualna · przedni
```

Group order is by version name using `Intl.Collator('pl', { numeric: true })`, so `1.9` sorts
before `2.0` and `A4` before `A40`. Option order within a group is `manual` before `automatic`,
then `front`, `rear`, `all` — a fixed enum order, never the API's acceleration order, which would
shuffle options between two versions that happen to overlap.

A version with one variant still gets a group with one option. Special-casing it into a bare
option would make two visually different shapes for the same thing.

When the variant query returns `truncated: true`, the select is disabled and shows
`— za dużo wersji, użyj wyszukiwarki —`. ADR 0010.

### Appearance of the dropdown

The closed control is `.input`, unchanged. The open popup is styled through
`appearance: base-select`, which keeps the native `<select>` and its keyboard behaviour while
making the popup and its `<option>`s addressable from CSS. The whole block sits behind
`@supports (appearance: base-select)`; a browser without it falls back to its own popup, which is
what every browser rendered before.

The popup takes the same vocabulary as the rest of the page: square corners, a hairline border,
`--color-bg` over `--shadow-md`. A version group label is set like the page's other kickers —
`--font-heading`, 11px, uppercase, `--color-accent-700` — with a hairline rule above it, and its
variants indent under it. The option under the keyboard cursor takes a neutral 7% tint; the option
that is currently selected takes an accent tint and a 2px accent bar on its leading edge, so the
two states never read as one. `::checkmark` is hidden because the accent bar already says it.

## Responsive layout

Two breakpoints, both `max-width`, shared by the whole screen. Why two and why these numbers:
ADR 0012. This section is the home of the values and of what changes at each one.

| | |
|---|---|
| **900px** | the three selects become one column and take 16px text |
| **640px** | the screen becomes a phone: the spec sheet stacks, insets shrink, the title drops to 26px |

Above 900px nothing below applies and the rendering is the desktop design, unchanged to the pixel.
Between 641px and 900px the spec sheet is still two columns while the selects are already one —
the two thresholds measure different things and do not coincide.

The supported floor is a **320px** viewport. There is no horizontal overflow at any width; the
defect this section fixes is a fixed 170px column starving the values beside it, not content
escaping the page.

### What changes at 640px

Every rule lives in the scoped block of the component that owns the element — there is no global
responsive stylesheet, and `src/app/styles.css` is vendored and untouched. The breakpoint value is
therefore written in three files; it is documented here once.

| File | Selector | Below 640px |
|---|---|---|
| `app/SearchScreen.vue` | `.search-screen` | `padding: var(--space-6) var(--space-4) var(--space-8)` |
| | `.search-screen-panel` | `padding: var(--space-4)`, `gap: var(--space-4)` |
| `spec-sheet/SpecSheet.vue` | `.spec-sheet-group` | `grid-template-columns: minmax(0, 1fr)`, `gap: var(--space-3)`, `padding: var(--space-4)` |
| | `.spec-sheet-group-label` | `padding-top: 0` |
| | `.spec-sheet-share` | `grid-template-columns: minmax(0, 1fr)`, `gap: var(--space-2)`, `padding: var(--space-4)` |
| | `.spec-sheet-header` | `flex-direction: column`, `align-items: stretch`, `gap: var(--space-3)`, `padding: var(--space-4)` |
| | `.spec-sheet-header .btn` | `align-self: flex-start` |
| | `.spec-sheet-title` | `font-size: 26px` |
| | `.spec-sheet-loading`, `.spec-sheet-gone` | `padding: var(--space-4)` |
| `app/ErrorPanel.vue` | `.error-panel` | `padding: var(--space-4)` |

`.search-screen` also changes `min-height` from `100vh` to `100dvh` **outside** any media query.
On a phone `100vh` counts the browser toolbar, so the empty state scrolls by a toolbar's height
with nothing under it; on desktop the two units are equal.

The shrunken insets are not decoration. A stacked value column is `viewport − 56.4`; at 320px that
is 263.6px against a measured requirement of 226px. Left at `--space-6` it would be 236.4px, and
the margin would be 10px.

### What changes at 900px

| File | Selector | Below 900px |
|---|---|---|
| `browse/CascadingSelects.vue` | `.cascading-selects` | `grid-template-columns: minmax(0, 1fr)` |
| | `.cascading-selects select` | `font-size: 16px` |

Above 900px the container gives each select the ~265px that the longest placeholder —
`— za dużo wersji, użyj wyszukiwarki —` — needs; below it three columns leave ~90px each, which
fits no placeholder in any styling, native or not.

The 16px is the iOS Safari zoom threshold: Safari zooms the page when a focused form control
computes below 16px. `SearchCombobox.vue` already sets 16px on its input for this reason; the
selects inherit 14px from `.input` in vendored `styles.css`, which cannot be edited, so the size is
restated in the component. It belongs **inside** the 900px block: applied at every width it would
push the three-column minimum from ~890px to ~986px.

### What deliberately does not change

- **`spec-sheet/SpecRow.vue`.** Once the group stacks, a row has 263.6px at 320px against a 226px
  requirement, so label and value stay on one line at every supported width. The row is not
  stacked, and it needs no query of its own.
- **`search/SearchCombobox.vue`.** The field and `Wyczyść` stay side by side. The reduced insets
  take the input from 139.6px to 166.8px at 320px, which is all it needed. The suggestion list
  keeps its 360px cap; it scrolls.
- **`app/EmptyState.vue`.** The example chips already wrap.
- **The h1.** `ilejedzie.pl` is 212px at 44px and fits a 320px screen.
- **Touch targets.** Measured: the search input and `Wyczyść` are 46px, the selects 36px, the
  `Kopiuj link` button 78.3 × 32.4px. All clear the 24px of WCAG 2.5.8 Target Size (Minimum, AA).
  None is enlarged, because enlarging one changes the approved design for a criterion already met.

### Accessibility

Stacking reorders nothing. In every affected block the DOM order is already the stacked reading
order — the group label precedes its rows, the sheet heading precedes the copy button, the share
label precedes the URL — so the visual order below 640px matches the DOM order rather than
crossing it. No `order`, no `grid-area`, no `flex-direction: column-reverse` anywhere. The combobox
contract above is width-independent and is unaffected.
