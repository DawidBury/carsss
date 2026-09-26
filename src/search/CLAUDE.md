# `search` — the combobox

## Owns

The primary path to a car (ADR 0004): the text field, its query, its keyboard behaviour and its
accessibility contract.

Files: `queries.ts`, `navigation.ts`, `SearchCombobox.vue`.
Full ARIA, keyboard and copy contract: `docs/ui.md`. Debounce, cancellation and cache policy:
`docs/api-client.md`.

## Boundaries

- Imports `api` and `format`. Never `browse` or `spec-sheet`.
- Owns no selection. It emits the chosen `SearchHit` upward; `app/SearchScreen.vue` decides what
  that means.
- Does not fetch the variant. A hit is a suggestion row, not a car.
- The debounce lives in `queries.ts`, next to the query it protects — not in the component and not
  in `app`.

## Decisions not to reverse

- **`aria-activedescendant`, not roving focus.** DOM focus never leaves the input. Moving focus into
  the list breaks typing, which is the entire point of a combobox.
- **The suggestion row always renders gearbox and drive.** Not decoration. The search unit is a
  variant, so two hits from one version are otherwise identical — measured, `q=bmw` returns 20 hits
  covering 15 versions, one of them three times. Removing these fields produces a list with
  duplicate-looking rows.
- **No paging, ever.** `GET /v1/search` is capped at 20 and ranked by relevance, not by a total
  order. There is no stable basis for a cursor and nobody pages suggestions.
- **No filters on the search request.** The endpoint accepts none; sending one is a `400`.
- **The live region announces settled results only.** Announcing pending state fires a message per
  keystroke and makes the field unusable with a screen reader.

## Traps

- **Forward `signal` to `fetch`.** `vue-query` supplies it; if the query function drops it, nothing
  is ever cancelled and a slow earlier response can overwrite a fast later one. This is the most
  likely bug in this module.
- **`Home` and `End` move the caret, not the active option.** It is a text field first. This
  deliberately departs from the ARIA listbox pattern and must not be "fixed".
- **Hover must not change the active option.** A mouse resting over the list while someone types
  with the keyboard would otherwise steal the active row.
- **`Escape` twice.** First closes and keeps the text; second clears. Clearing on the first press
  destroys work when the user only wanted the list out of the way.
- **Below two characters the query is disabled, not sent.** The API accepts a one-character `q`, but
  one letter matches most of the catalogue.
- **Zero results does not mean a typo.** The server already corrects typos; today's zero results are
  usually a partial import (`q=golf` finds nothing because that model is not loaded yet). The copy
  says so — do not replace it with "check your spelling".
- **Never seed `['variant', id]` from a hit.** A hit has no acceleration, consumption or engine
  fields. See ADR 0008.
