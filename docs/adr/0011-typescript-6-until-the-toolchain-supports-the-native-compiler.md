# 0011 — TypeScript 6.0.3, until the toolchain supports the TS 7 native compiler

## Context

The project owner pinned "TypeScript 7 (`strict`)" as part of a stack that was not the architect's
to reopen. During implementation the developer found that pin unusable and escalated it rather than
swapping it silently. This ADR reverses part of an owner decision and is written loudly for that
reason.

`typescript@7.0.2` is the native compiler rewrite, not a repackaging of the classical one. Verified
against the npm registry rather than taken on report — its entire `exports` map is:

```
"."                     -> ./lib/version.cjs
"./package.json"        -> ./package.json
"./unstable/{sync,async,fs,proto,ast,ast/is,ast/factory,
              ast/utils,ast/scanner,ast/visitor,ast/clone}"
```

Three consequences follow, and they are one root cause rather than three bugs:

- There is no `./lib/tsc` subpath.
- The root export `"."` resolves to a **version stub**, and `main` is absent entirely. `require('typescript')` no longer yields the compiler API.
- The `tsserver` binary is gone; only `tsc` remains.

So every tool that reaches for the classical API breaks:

| Tool | How it breaks |
|---|---|
| `vue-tsc@3.3.11` | `node_modules/vue-tsc/index.js` requires `typescript/lib/tsc` and `typescript/lib/quickstart` directly |
| `@typescript-eslint/typescript-estree@8.69.0` | `Cannot read properties of undefined (reading 'Cjs')` — the same missing root API |

`typescript@6.0.3` is the last stable pre-rewrite release (no `exports` field, classical layout,
`main: ./lib/typescript.js`, both `tsc` and `tsserver` bins). With it, all four commands in this
project's own verification block pass: clean `vue-tsc --noEmit`, 112 tests, clean `eslint`, and a
120.93 kB build.

## Decision

Pin `typescript@6.0.3`. The root `CLAUDE.md` stack table is corrected to match, because it stated a
version the project does not and cannot use.

The owner's actual requirement — `strict` type checking, verified in CI — is fully met by 6.0.3.
The version *number* was never the requirement; type safety was, and TS 7 currently delivers less
of it here, not more.

## Consequences

- `vue-tsc --noEmit` and typed ESLint both work, so the verification block in the root `CLAUDE.md`
  remains executable as written. This is the decisive point: a pin that makes the project's own
  definition of "done" unreachable is not a stack decision, it is an aspiration.
- No source change and no `tsconfig` change is implied. `strict` is unaffected, and nothing in
  `src/` depends on a compiler version.
- The project forgoes the native compiler's speed. On a codebase this size that is worth nothing
  measurable — the full check is already seconds.
- **The docs no longer claim a version that is not installed.** A stack table asserting TypeScript 7
  while `typescript@6.0.3` is on disk is exactly the drift the root `CLAUDE.md` forbids, and it is
  the kind that survives for months because nobody re-reads a table they think they know.
- The owner should be told this pin moved. It is recorded here rather than absorbed quietly.

### Trigger for revisiting

Both of these, together — neither alone is sufficient:

1. `vue-tsc` reads TypeScript through the TS 7 API instead of `require('typescript/lib/tsc')`.
2. `@typescript-eslint/typescript-estree` supports the TS 7 API.

At that point the upgrade is a version bump plus a re-run of the verification block. Until then it
is a downgrade in everything the project actually checks.

## Rejected alternatives

**Keep TypeScript 7 and drop `vue-tsc` from the verification block.** Trades the only type check
that understands `.vue` single-file components for a version number. Types in templates and
`<script setup>` blocks would stop being checked at all — in a codebase whose entire contract with
the backend is expressed in types, and where `null` handling is the main correctness risk.

**Keep TypeScript 7 and drop typed linting.** Same trade, smaller. The typed rules are the ones that
catch misuse of nullable API fields, which is this project's most likely defect class
(`docs/ui.md` — `null` rates measured between 1 % and 23 % across the response).

**Keep TypeScript 7 and type-check with `tsc` alone, skipping `.vue` files.** Checks the files least
likely to be wrong and skips the ones most likely to be. The signature files under `src/` are
already trivially checkable; the components are where the nullable fields are consumed.

**Use a TS 7 pre-release (`7.1.0-dev.*`).** Same missing classical API, plus a nightly's
instability. The blocker is the API surface, not a bug awaiting a fix.

**Wait for the ecosystem before implementing anything.** The project would stop for a toolchain
change that has no bearing on its design.
