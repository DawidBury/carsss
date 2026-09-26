# 0010 — Paging for the variant select is bounded and admits it

## Context

The third select needs every variant of the selected model, because the backend has no
`GET /v1/versions` and versions must be derived client-side (ADR 0004).

`GET /v1/variants` pages by opaque cursor. Keyset paging is **sequential** — page *n+1* needs the
cursor from page *n* — so *k* pages is *k* round trips that cannot be parallelised.

Measured: mean 16 variants per model, median 4, p95 58, max 354. At `limit=200`, one request
covers all but one model and two cover every one of them. A model far above the p95 would take
tens of requests and megabytes to fill one dropdown.

## Decision

The paging loop uses `limit=200` and follows `page.next` for **at most 5 pages (1 000 variants)**.
If `page.next` is still non-`null` at that point it stops and resolves
`{ variants, truncated: true }`.

When `truncated` is true the third select is **disabled** and reads
`— za dużo wersji, użyj wyszukiwarki —`.

## Consequences

- The select can never issue more than five sequential requests or hold more than 1 000 rows,
  whatever the import grows to. The worst case is bounded before it exists.
- At the measured distribution the bound is never reached: the largest model is 354 variants, two
  pages.
- As the import grows the bound covers the p95 model and rejects the handful above it, which is
  where the select was unusable anyway — nobody scrolls thousands of options.
- The failure is **visible**. The user is told the list is too long and is pointed at the search
  field, which has no such limit and finds these cars in one request.
- The number 5 is a measured judgement, not a constant to tune blindly. It is here, in one place,
  with the measurement that produced it.

## Rejected alternatives

**Unbounded paging.** Correct in principle and unusable in practice: tens of sequential requests
before a dropdown opens, on the models most likely to be asked for.

**Truncate silently at one page.** The worst option. The user sees a plausible list that omits the
car they want, with no indication anything is missing, and concludes the database lacks it.

**Load pages lazily as the user scrolls the select.** A native `<select>` has no scroll event to
hook, so this requires replacing it with a custom listbox — rebuilding keyboard behaviour,
type-ahead and screen-reader semantics that `<optgroup>` gives for free (ADR 0005), for the tail of
one distribution.

**Filter server-side to shrink the set — by `fuelType` or `gearbox` before paging.** Adds controls
the design does not have, and still cannot guarantee a bound: a model far above the p95 has more
than 1 000 petrol variants on its own.

**Ask the backend for `GET /v1/versions`.** The endpoint it would need. It is refused upstream with
its reasons (`../carsss/docs/api.md` §2), the backend is not ours to change, and this ADR is the
frontend's record of what that refusal costs: one bounded loop and one disabled select.
