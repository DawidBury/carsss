# 0009 — Polish text and formatting live in one module

## Context

Code and documentation are English; the interface is Polish. `gearbox`, `drive` and `fuelType` are
closed English enums, and the API explicitly refuses to emit the source's Polish labels —
`gearbox_unmapped_label` and `drive_unmapped_label` never leave the backend, and an unrecognised
label reaches us as `null`.

So the English-to-Polish mapping has to happen somewhere on this side, and numbers have to be
formatted for Polish conventions: comma decimals, non-breaking group separators, `9,4 s`,
`1 995 cm³`.

## Decision

`src/format` is the only module outside `.vue` templates that may contain Polish strings. It holds
the enum label maps and the number formatters, and nothing else. It imports nothing from `src/`.

Enum maps are typed as `Record<Gearbox, string>` over the union, so a member added upstream is a
build failure rather than an `undefined` on screen.

Numbers go through `Intl.NumberFormat('pl-PL')`. Separators are never hand-written.

## Consequences

- One place to look for any label, and one place to change for a second language, if that ever
  arrives. That is not a reason to build an i18n layer now — it is a reason for this boundary to
  exist, which costs nothing.
- `api/` stays free of display concerns: the transport layer never produces Polish.
- Templates hold the sentences and headings that are structurally part of the layout — labels,
  placeholders, the no-results message. Hoisting those into a catalogue would produce a key file
  nothing else reads and make the templates unreadable.
- `Intl` handles what a regex gets wrong: Polish uses a non-breaking group separator, and modern
  ICU emits U+202F rather than the U+00A0 the design mock used. Writing separators by hand
  guarantees a mismatch with the platform's own numbers.
- `null` rendering is one function in this module, so "unknown" looks identical in every row
  (`docs/ui.md`).

## Rejected alternatives

**`vue-i18n`.** A dependency, a message catalogue and a plugin for one locale that is never
switched. If a second locale is ever wanted, this module is the seam it plugs into.

**Labels inline where they are used.** Guarantees the same enum is translated twice, differently,
and that a new enum member is missed in one of the two places.

**Asking the backend for Polish labels.** It refuses on purpose — those are the source's words, and
leaking them ties the API to a scraper it is designed to be able to replace.

**Hand-rolled number formatting with `toFixed` and a regex.** Cheaper to write and wrong: it fixes
the separator character at authoring time and gets `1 995` versus `1995` inconsistently between
fields.
