# 0002 — No store; client state lives in the screen component

## Context

Server state — brands, models, variants, search hits — is `vue-query`'s, and is not duplicated
anywhere. What remains is client state: the search text, the selected brand id, the selected model
id and the selected variant id. Four values.

The component tree is one level deep: `SearchScreen` renders `SearchCombobox`,
`CascadingSelects` and `SpecSheet` as siblings.

## Decision

No Pinia and no global reactive singleton. The four values are `ref`s in `app/SearchScreen.vue`,
passed down as props and changed through emitted events.

`provide`/`inject` is also rejected.

## Consequences

- One dependency fewer, and one fewer place where state can live. There is exactly one answer to
  "where does the selected variant come from".
- State is destroyed with the component, so there is no stale-state-on-remount class of bug.
- Every dependency is visible in a component's props. A reviewer reads the template and knows what
  a child can touch.
- If the tree deepens — a toolbar three levels down needing the selection — the honest fix is to
  reconsider the tree first. A store is reconsidered only if that fails.

## Rejected alternatives

**Pinia.** A store nothing but one component reads is a defect. It would add a global singleton
whose lifetime is the page rather than the screen, devtools plumbing, and the standing temptation
to copy server state into it — the failure this project is most exposed to, since `vue-query`
already owns that data and a second copy immediately disagrees with the first.

**`provide` / `inject` with a typed `InjectionKey`.** Cheaper than Pinia and needs no dependency,
but it hides a dependency that a prop states outright, and it is only worth its opacity when
prop-drilling is genuinely deep. Through one level it is drilling nothing.

**A plain reactive module singleton (`export const state = reactive({…})`).** All of Pinia's
downsides, none of its tooling, and it survives hot reload in ways that produce confusing sessions.
