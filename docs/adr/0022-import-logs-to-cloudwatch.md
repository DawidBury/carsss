# ADR 0022 — Import logs to CloudWatch Logs through the Docker `awslogs` driver

Status: accepted · Date: 2026-09-06 · Implements the log side of ADR 0009, extends ADR 0021

## Context

The import runs unattended. `cars-import.timer` fires at 01:00 UTC, `deploy/host/import.sh`
queues ~28 892 engine pages, four workers drain the queue for ~3.8 h, and the script then runs
`compose --profile import rm -sf worker`. **The containers are deleted, and with them their
`json-file` logs.** By the time anyone looks, the entire record of the night is gone.
`make remote-logs` follows containers that are alive; it cannot show a run that finished.

ADR 0009 already decided that a skipped record lives in a structured log and not in a
`ingestion_rejection` table, and it named CloudWatch Logs Insights as the place that log would be
read. That half of ADR 0009 was never built: ADR 0021 struck CloudWatch Logs off the cost sheet
when it replaced ECS/Fargate with one EC2 host. ADR 0009 also rejects a run counter in
`import_run` (last entry under "Odrzucone alternatywy") — a counter incremented by 28 892
transactions serialises every worker on one row — so **"how much did tonight's import do" has to
be a log query and cannot become a SQL query.**

Four gaps were measured in the tree before this ADR:

1. **Nothing is logged when a page is written successfully, and nothing is logged on the 304
   path.** The outcome the owner wants to count is the one outcome that is silent.
2. **`ImportRecordHandler::__invoke` has no `catch`.** A failing page is reported only by
   Messenger, on the `messenger` channel, without `runId` and without `ref`. Two of those
   failures are routine rather than exceptional (`SQLSTATE 23505` when two sitemap URLs converge
   on one canonical page, `SQLSTATE 40P01` on a deadlock — `src/Import/CLAUDE.md` pkt 5c and
   pułapki 1–2), so "which page blew up" is a question that gets asked.
3. **`symfony/monolog-bundle` is not installed.** `Psr\Log\LoggerInterface` therefore resolves to
   `Symfony\Component\HttpKernel\Log\Logger`, which takes its minimum level from
   `SHELL_VERBOSITY`: default `ERROR`, `-v` → `NOTICE`, `-vv` → `INFO`. The worker runs with
   `-v`. **The two `info` skip logs that do exist are dropped in production today**, and the
   output is plain text, which Logs Insights cannot decompose into fields.
4. Nothing observes ADR 0017's forced refetch, although `src/Import/CLAUDE.md` pkt 10 instructs
   the operator to notice when those go from single to mass.

Cost is a first-class constraint here: the whole host costs ~USD 14–20 per month (ADR 0021) and
the CloudWatch free allowance is 5 GB ingested per month.

## Decision

### 1. Transport: the Docker `awslogs` log driver, on the `worker` service only

The Docker daemon reads the container's stdout/stderr and calls `PutLogEvents` itself, signing
with the EC2 instance role that already exists (`aws_iam_role.app`). **The application gains no
AWS dependency**: no SDK, no credentials, no region lookup, not one line under `src/` or
`config/` that names an AWS resource. The boundary in `infra/CLAUDE.md` — "`src/` and `config/`
contain no `.tf` file, no ARN and no AWS resource name" — survives untouched, and so does the
promise that the only contract between the two sides is the environment-variable table.

No new environment variable. The region (`eu-central-1`) and the group name (`/cars/worker`) are
written as literals in `compose.prod.yaml`. One account, one region, one environment, and
`compose.prod.yaml` is explicitly not part of `infra/` (`infra/CLAUDE.md`, "Za co odpowiada"), so
it is the right place for a deployment-facing literal. The alternative is not "a variable" but a
new row in the environment-variable contract table — the one narrow interface between the
application and the infrastructure — bought so the host can inject a constant. That table is
supposed to grow only when the two sides genuinely need to agree on something that varies; this
does not vary. The cost is a coupling honoured by hand, and it is acceptable only because
breaking it stops the worker container at startup rather than corrupting anything
(`infra/CLAUDE.md`, decision 10).

Delivery mode is `non-blocking`. In the default blocking mode a slow or unreachable CloudWatch
endpoint blocks the writing process — a logging outage would stall a 3.8 h import. Losing log
lines is the correct failure here; losing the run is not. The ring buffer keeps its 1 MB default,
which is roughly 3 000 of our lines.

`awslogs-create-group` stays at its default of `false`. If Docker were allowed to create the
group, it would create it **with no expiry**, and the retention decision below would silently not
apply.

### 2. One log group, `/cars/worker`, retention 7 days, created by Terraform

Retention is **7 days**, as accepted by the owner. A skipped page from three weeks ago is not
information anyone acts on (ADR 0009 already accepted this trade in writing), and the database —
not the log — is the durable record of what was imported. This paragraph and
`var.log_retention_days` are the only two places the number exists; everything else points here.
Its second-order effect is that the group holds up to seven nightly runs at once, so a query that
is not scoped by `runId` reads several of them — the runbook scopes every accounting query.

The IAM policy follows the shape of the existing `backups` policy: an inline
`aws_iam_role_policy` built from an `aws_iam_policy_document`, attached to `aws_iam_role.app`,
with `logs:CreateLogStream` and `logs:PutLogEvents` scoped to
`arn:aws:logs:eu-central-1:<account>:log-group:/cars/worker:*`. Those two actions are the
documented minimum for the driver. `logs:*` on `*` is not granted, and neither is
`logs:CreateLogGroup` — the driver must not be able to create a group whose retention nobody set.

Streams were meant to be named by `awslogs-stream-prefix: worker`, giving
`worker/cars-worker-<replica>/<container id>`. That option does not survive first contact with
this host: AL2023's `docker` package (`docker-25.0.16-1.amzn2023.0.4`, Amazon's own build, not
upstream Docker CE) rejects it — `Error response from daemon: unknown log opt
'awslogs-stream-prefix' for awslogs log driver` — the same class of gap `infra/CLAUDE.md` trap 2
already documents for `buildx` on this platform, and a long-standing one: the `tag` option fails
the same way on Amazon Linux builds of Docker (`docker/compose#4827`). `compose.prod.yaml` carries
only `awslogs-region` and `awslogs-group`; verified directly on the host with a throwaway
container and `aws logs describe-log-streams` before trusting it. Without a prefix or a `tag`,
the driver falls back to naming each stream by the full container ID — confirmed against
[Docker's own driver reference](https://docs.docker.com/engine/logging/drivers/awslogs/), which
also does not mention `awslogs-stream-prefix` at all. One run's four replicas therefore land in
four streams with no naming relationship to each other or to the replica number; a query across
the whole run still has to scan the group, unscoped by stream — exactly as already written for
`runId`-scoped queries in `docs/deployment.md`, which never relied on stream naming to isolate a
run.

### 3. Monolog, JSON on `php://stderr`, `info` and above in production

`symfony/monolog-bundle` `^4.0` is installed — that branch requires Symfony `^7.3 || ^8.0` and
Monolog `^3.5`, so it fits the stack and brings `#[WithMonologChannel]`, which is how the two
import classes get onto the `import` channel without a service definition. Without the bundle
there is no JSON, no per-channel control and — as measured above — no `info` records at all.
Writing a PSR-3 JSON logger by hand instead would be re-implementing Monolog, and
`config/packages/` already holds nine framework configuration files, so one more is not a new
concept.

`config/packages/monolog.yaml`:

- every handler is `type: stream`, `path: php://stderr`, so the container's log stream is the only
  sink and the transport stays the Docker driver's problem;
- production, `main`: `level: info`, `formatter: monolog.formatter.json`, `channels: ['!event',
  '!doctrine', '!deprecation', '!messenger']`;
- production, `messenger`: `level: warning`, JSON formatter, `channels: ['messenger']`;
- development: one handler, `level: debug`, line formatter, `channels: ['!event', '!doctrine']`;
- test: null handler.

`!doctrine` is not redundant with `level: info`. DBAL's logging middleware logs statements at
`debug` **and connections at `info`**, and the middleware is switched on by
`doctrine.dbal.logging`, which defaults to `%kernel.debug%`. Excluding the channel makes "no SQL
in CloudWatch" a property of the configuration instead of a side effect of `APP_DEBUG`. The same
exclusion is applied in development for a different reason: the local import throughput figure
that `docs/architecture.md` §5 rests on (1.99 pages/s, ~3.8 h) was measured without per-statement
logging, and turning it on would quietly move the benchmark. Someone who wants SQL locally
deletes `'!doctrine'` for the length of the session.

`!deprecation` is production-only: Symfony's error handler logs deprecations at `info` on that
channel, which would put a fixed per-process tax on every worker boot for a signal that belongs
in development.

**The `messenger` channel gets its own handler at `warning`, and this is the single most
expensive line in the file.** Symfony narrates every consumed message three times at `info`:
`SendMessageMiddleware` ("Received message"), `HandleMessageMiddleware` ("Message handled by") and
`Worker` ("was handled successfully"). At 28 892 messages that is ~86 600 extra records per run,
none of which carries a `runId` or a `ref` — precisely what rule 8 of "Log contract" in
`src/Import/CLAUDE.md` forbids, and it would make every `stats count() by message` query start
with an exclusion clause. Excluding
the channel from `main` and threshold-limiting it is not the same as dropping it. `warning` is the
exact right cut: at that level and above the channel emits **two records in the whole component**,
both from `SendFailedMessageForRetryListener` — a `warning` per retry and a `critical` when it
gives up on a message. That `critical` is the only statement anywhere that a page was abandoned,
so silencing the channel outright would trade noise for the loss of the one signal that matters
("Log contract" rule 4a). Note also that removing `-v` does **not** achieve any of this: with
Monolog installed the handler's threshold decides, not `SHELL_VERBOSITY`.

No `console` handler is configured. It would print every record a second time, in a second
format, into the same stream — doubling both the volume and the cost for zero information.

Stack traces are not enabled (`include_stacktraces` is left at its default of `false`). Monolog's
normaliser then renders an exception as class, message, code, file and line, which is what a
query needs; a trace per failure would be ~5 KB and is available from a local reproduction.

**Volume and cost.** The design target is **one record per page**: a page produces exactly one of
the five events in §4, and the channel rules above are what hold that ratio. ~28 892 records per
run at ~330 B, plus CloudWatch's 26 B per event, is ~10 MB per run and ~300 MB per month — about
6% of the 5 GB free allowance, and ~USD 0.2 per month if the allowance were gone.

Two multipliers were measured off that baseline, and both are excluded by configuration rather
than by hope:

| Configuration | Records per page | Per month |
|---|---|---|
| as decided | 1 | ~300 MB |
| `messenger` left at `info` | 4 | ~1.2 GB |
| `debug` with Doctrine included | 10–50 | 3–16 GB |

The middle row is a 4× bill for lines with no `runId` and no `ref`. The bottom row leaves the free
allowance entirely and reaches a monthly figure of the same order as the EBS volume. Those ratios,
not taste, are why the level is `info` and why `messenger` and `doctrine` are handled separately.

### 4. A closed set of import log events

Five events, all on a dedicated `import` Monolog channel, with the Monolog **message being the
stable event name** — one field, machine-readable, no second "event" key to drift from it. The
normative table (names, levels, fields, which reference goes in `ref`, where each is emitted)
lives in `src/Import/CLAUDE.md`, section "Log contract", and that is its only place, exactly as
ADR 0008 owns the source payload contract.

Three properties of that contract are decisions rather than details:

- **Success is two events, not one.** `import.version_written` and `import.version_unchanged` are
  counted separately, because from the second night onward the ETag path turns almost every page
  into `Unchanged`. If only writes were logged, a healthy run would look like a run that did
  nothing. Their sum is "how much of the catalogue this run touched".
- **The run is accounted for by `import.record_skipped` alone, not by `reason` across modules.**
  `App\Source` does log one skip with a `reason` field (`unknown_fuel_token`), and `warning`
  clears the `info` threshold, so it reaches CloudWatch. But the same page also produces an
  `import.record_skipped` in the core, because `SourceCatalog::fetchRecord()` reports every parser
  rejection as `Absent`: `absent` is a **superset** of the adapter's reasons, not a peer of them.
  A single `filter ispresent(context.reason)` therefore double-counts, and it also spans every run
  inside the retention window. The runbook splits this into a `runId`-scoped accounting query over
  `import.record_skipped` and a separate, unscoped diagnostic over the adapter's `app`-channel
  warnings. Closing the gap the other way — making the adapter emit one reason per page — would
  mean pushing `runId` through `fetchRecord()`, i.e. widening the port; see "What is deliberately
  out of scope".
- **`import.record_failed` counts attempts; Messenger counts losses.** The `catch` rethrows, so
  every redelivery logs again and a page that never succeeds emits six records under
  `max_retries: 5`. The moment a page is actually given up on is Messenger's `critical` record on
  the `messenger` channel, which is why §3 keeps that channel at `warning` instead of silencing
  it. A query filtering on `level_name = "ERROR"` alone would count attempts and miss every loss.

Errors are logged once **per attempt**, in `ImportRecordHandler::__invoke`, at `error`, **and
rethrown**. `src/Import/CLAUDE.md` pkt 7 stands: retries belong to Messenger, and a `catch` that
does not rethrow would silently acknowledge a message that was never written. The level is a
single `error` with no classification by SQLSTATE — deciding that 23505 is "less bad" is one
branch away from deciding the retry policy, which is not this module's job. The exception class
discriminates in the query instead.

### 5. `-v` is removed from `messenger:consume`, and the `Dockerfile`'s `worker` stage with it

The flag is removed from the `worker` command in `compose.prod.yaml`. Reasons, in order of weight:

1. With Monolog installed, the handler's `level` decides what is emitted; `SHELL_VERBOSITY` no
   longer does. The flag buys nothing. In particular it does **not** control Messenger's
   per-message narration — that is what §3's `messenger` handler is for.
2. It is a loaded gun. Add a `console` handler in a later change and `-v` starts duplicating every
   record in a human format alongside the JSON one — the doubling described in §3, arriving by
   accident.

The `Dockerfile` carried a `worker` stage with the same command and the same flag. It is deleted
rather than corrected, because nothing builds it: `compose.prod.yaml` builds `target: api` for
both `api` and `worker` and overrides `command`, `compose.yaml` builds `target: development`, and
`deploy/deploy.sh` passes no `--target`. A stage nobody builds is a second copy of the worker
command that drifts silently — it already had.

`compose.yaml` (local development) is not touched, on the owner's instruction. The flag is inert
there for reason 1.

## What is deliberately out of scope

**`App\Source` is not changed.** Its `warning` logs already reach CloudWatch: four page
rejections in `PageParser` (no canonical link, canonical outside the taxonomy root, unknown fuel
token, `config-box`/`option` count mismatch), of which only the third carries a `reason` field,
plus one non-fatal breadcrumb warning in `TaxonomyReader`. All four rejections end with the
adapter returning `absent()`, so `import.record_skipped` counts them as `absent` in the core — no
page falls out of the accounting, and none is counted twice as long as the two queries stay
separate.
Adding `runId` to them would mean putting the run identifier into `SourceCatalog::fetchRecord()`,
i.e. widening the port that ADR 0008 exists to keep narrow, in exchange for a field the adapter
cannot use. The price of not doing it is that "why was this page absent" needs a second query
instead of a `GROUP BY`; the runbook writes both out.

`implausible_value` from ADR 0009's closed list has no producer anywhere in the tree; the
plausibility check it names is not written. The reason stays in ADR 0009's list and the query
counts it the day it appears, but the runbook says outright that it will not appear today, so
nobody hunts for a category that cannot occur. That is a gap in the source adapter, not in this
log design.

**`api` does not move to CloudWatch, and neither do `caddy`, `database` and `rabbitmq`.** The
request was import visibility. An API error is visible to its caller at the moment it happens and
is reproducible on demand; a worker error happens at 01:00 with nobody watching and the evidence
is deleted an hour later. `json-file` at `10m × 3` holds weeks of API log at this traffic, and
`api` is the service an operator debugs interactively — putting it behind a driver that
`docker logs` cannot read would cost more than it returns. Caddy's access log is the largest
volume in the stack and the least interesting. If this changes, it is one `logging:` block and one
extra resource ARN.

## Consequences

- **`make remote-logs` has to name its services, and does.** The `awslogs` driver does not support
  reading, and `docker compose logs` with no service names fails for the *whole project* when one
  container uses such a driver (docker/compose#8136, #9119, #12980). The target now passes `api
  caddy database rabbitmq`; the workers are read with `aws logs tail /cars/worker --follow`.
  CloudWatch is a complement to `make remote-logs` for four services and a replacement for one.
- **A misconfigured log group stops the import instead of degrading it.** A container whose
  logging driver cannot initialise does not start. If `terraform apply` has not created
  `/cars/worker`, or the role lacks the policy, `docker compose --profile import up -d worker`
  fails and the 01:00 run fails with it. `terraform apply` therefore precedes the first import
  after this change; the runbook says so.
- **That new failure point is moved ahead of the run row, and a shutdown handler catches the
  rest.** `deploy/host/import.sh` now starts the workers **before** `app:import:start`, so a
  logging misconfiguration fails while there is still no `import_run` row to unblock. Every other
  exit path — including the pre-existing nine-hour timeout, which had the same hole, and `SIGINT`
  and `SIGTERM`, which a bare `trap … EXIT` does not catch — is covered by a handler that removes
  the worker containers and, on a non-zero status, closes the run with
  `app:import:finish <id> --failed`. Without one of the two, a failed night would leave a
  `running` row and `import_run_one_running_per_source` would reject every later run until someone
  intervened by hand.
- **The handler forced a guard at the top of the same script, and the guard has an operational
  price.** The handler closes "the newest `running` row", which is only the row it created if no
  other run is in flight — and `make remote-import` runs the script over SSM, outside the timer,
  with nothing preventing an overlap. So the script now exits before its first destructive step
  when a `running` row already exists. The consequence the runbook has to state (it does, trap 11)
  is that a run killed without a chance to run its handler leaves a row that no later run clears
  by accident; unblocking becomes a deliberate `app:import:finish <id> --failed` by the operator.
  That is the correct side of the trade: the alternative is a run that silently kills another
  run's workers and marks a live import `failed`.
- **This commit must not be deployed before `composer.lock` is refreshed.**
  `config/packages/monolog.yaml` and `config/bundles.php` reference a bundle that `composer.lock`
  and `symfony.lock` do not contain, and the `vendor` stage of the `Dockerfile` runs
  `composer install`, which fails hard on a lock file that does not satisfy `composer.json`. So
  `composer update symfony/monolog-bundle` is step 0 of the implementation — before `make deploy`,
  before any test run, before anything.
- **API logs get more verbose locally.** The production handler admits `info`, so `api`'s
  `json-file` log gains Symfony's per-request `info` records. Rotation caps it at 30 MB and it
  costs nothing.
- **The retention window is a real limit, and it holds several runs at once.** A question about a
  run from beyond it has no answer, and a query inside it that is not scoped by `runId` silently
  mixes every night still in the window — which is why the runbook scopes every accounting query.
  The database still knows what was imported (`version.last_seen_run_id`, `import_run`); what is
  lost is why individual pages were skipped. ADR 0009 accepted this in advance.
- **Counting stays out of SQL.** ADR 0009's rejection of counters is reinforced, not weakened: the
  answer to "how much did this run do" now exists, and it exists in the log where ADR 0009 put it.

## Rejected alternatives

**CloudWatch Agent on the host, tailing the `json-file` logs.** Keeps `docker logs` working for
the workers, which is the one thing the chosen option gives up. Rejected on three counts. It adds
a daemon (~50–100 MB resident) to a 2 GB host where memory is the binding constraint of ADR 0021
and four workers are already sized against it. It has to be installed by `user-data.sh`, which
runs on first boot only (`infra/CLAUDE.md`, trap 1), so shipping this change would require a
manual step on the live host and would not reproduce on a rebuild. And it needs its own
configuration file describing container log paths — a second place where the log group name lives,
which is exactly the duplication `CLAUDE.md` forbids.

**AWS SDK for PHP writing to CloudWatch from the application.** Rejected on the boundary: it puts
credentials, a region and an AWS API into `src/`, breaking the rule in `infra/CLAUDE.md` that the
application never reads or calls AWS directly. It also couples log delivery to the process being
logged — the failure this design most needs to survive is the worker dying.

**A `monolog` handler of type `service` pointing at a CloudWatch handler library** (e.g.
`maxbanton/cwh`). The same boundary violation as the SDK, plus a dependency whose maintenance is
now ours, plus per-record HTTP calls inside a loop that is already limited to 2 requests/s against
a third party.

**Counters in `import_run`, or a `import_page_result` table.** The honest alternative to logging
at all: `SELECT count(*) … GROUP BY outcome` beats any log query. Rejected because ADR 0009
("Odrzucone alternatywy") and `src/Import/CLAUDE.md` pkt 11 rejected it on a measurement, not a
preference — a counter touched by 28 892 transactions serialises four workers on one row, and a
result row per page is the `ingestion_rejection` table that grew to 240 000 rows a year and was
never read.

**Keeping `json-file` and shipping nothing, relying on `make remote-logs`.** This is the status
quo and it does not work: `import.sh` deletes the worker containers when the run ends, so the log
of a completed import does not exist to be followed.

**One log group for the whole stack, with per-service stream prefixes.** Fewer resources, but the
group's retention then has to serve Caddy's access log and the API's error log at the same time,
and every Insights query grows a stream filter. Two groups when there are two writers is cheaper
to read; today there is one writer and one group.

**`awslogs-create-group: "true"` instead of a Terraform resource.** Removes the ordering
dependency between `terraform apply` and the first import. Rejected because a group created by
the driver has no expiry, and unbounded retention on ~300 MB per month is the one way this design
becomes expensive.
