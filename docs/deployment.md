# Deployment

Production runs on a single EC2 instance with the same Docker Compose topology as the local
stack. The reasoning, the price comparison against ECS/Fargate and the rejected alternatives
are in ADR 0021 — this file is the runbook only.

## What runs where

| | |
|---|---|
| Host | one EC2 `t4g.small` (2 vCPU Graviton, 2 GB) in a public subnet, `eu-central-1` |
| TLS | Caddy, automatic Let's Encrypt certificates for `API_DOMAIN`, `FRONT_DOMAIN` and its `www` alias |
| Database | `postgres:17-alpine` container, data on the root EBS volume |
| Queue | `rabbitmq:3.13-alpine` container |
| API | `php-fpm` container behind Caddy, on `API_DOMAIN` |
| Frontend | the Vue SPA from `../carsss_front`, built on the workstation and served as static files from `FRONT_DOMAIN` |
| Import | four `messenger:consume` containers, Compose profile `import`, running only during an import |
| Shell access | SSH tunnelled through SSM Session Manager; port 22 is not open in the security group |

## Prerequisites on the workstation

```bash
brew upgrade awscli
brew install --cask session-manager-plugin
aws configure
```

The frontend is built on the workstation, so Node is needed there too. The repository pins it
in `.nvmrc`; the deploy script selects it with `nvm use` because a default shell may resolve
`node` to a version far too old for Vite.

An SSH key pair is needed for the tunnelled session; generate one if `~/.ssh/id_ed25519.pub`
does not exist:

```bash
ssh-keygen -t ed25519
```

## One-time bootstrap

### 1. State bucket

The Terraform backend cannot create its own bucket, so it is made once by hand:

```bash
aws s3 mb s3://cars-terraform-state-<something-unique> --region eu-central-1
aws s3api put-bucket-versioning \
    --bucket cars-terraform-state-<something-unique> \
    --versioning-configuration Status=Enabled
```

### 2. Infrastructure

```bash
cd infra/terraform/envs/production
cp backend.hcl.example backend.hcl
cp terraform.tfvars.example terraform.tfvars
```

Fill in the bucket name in `backend.hcl`, and `api_domain` plus `alert_email` in
`terraform.tfvars`. Then:

```bash
terraform init -backend-config=backend.hcl
terraform apply
```

`terraform output` prints the instance id, the elastic IP and the backup bucket name.

### 3. DNS

Create one record at the DNS provider, taking the address from `terraform output dns_record`:

```
api.example.com.   A  <elastic ip>
example.com.       A  <elastic ip>
www.example.com.   A  <elastic ip>
```

In Cloudflare the record must stay **DNS only (grey cloud)**. With the proxy enabled,
Caddy cannot complete the HTTP-01 challenge and no certificate is issued.

### 4. Secrets on the host

```bash
export CARS_INSTANCE_ID=$(terraform -chdir=infra/terraform/envs/production output -raw instance_id)
deploy/remote.sh
```

On the host, create `/opt/cars/.env.prod.local` from `.env.prod.local.example`. The file must
stay shell-sourceable — the import and backup scripts read it with `.` — and must contain the
same passwords in `POSTGRES_PASSWORD`/`RABBITMQ_PASSWORD` as in `DATABASE_URL` and
`MESSENGER_TRANSPORT_DSN`. Generate the secrets with:

```bash
openssl rand -hex 32
```

Set `BACKUP_BUCKET` to the value of `terraform output backup_bucket`, and `API_DOMAIN` plus
`FRONT_DOMAIN` to the domains from step 3. `CARS_API_KEY` stays empty until step 6. Then
`chmod 600 /opt/cars/.env.prod.local`.

### 5. First deploy

```bash
make deploy
```

The target syncs the working tree to `/opt/cars`, builds the image, starts the database and
the queue, **runs the migrations as a separate invocation**, and only then starts the API and
Caddy. The first build takes several minutes because the PHP extensions are compiled on the
host.

### 6. API key and schedule

```bash
deploy/remote.sh "cd /opt/cars && docker compose --env-file .env.prod.local -f compose.prod.yaml exec -T api php bin/console app:api-key:create front"
```

The key is printed once. Put it in `/opt/cars/.env.prod.local` as `CARS_API_KEY` and restart
Caddy, which injects it into every `/api/*` request coming from the SPA:

```bash
deploy/remote.sh "cd /opt/cars && docker compose --env-file .env.prod.local -f compose.prod.yaml up -d caddy"
```

This ordering is forced: the key cannot exist before the API runs, and the SPA cannot call the
API before the key is in Caddy's environment. Issue a second key for your own `curl` use rather
than reusing this one.

```bash
deploy/remote.sh "/opt/cars/deploy/host/install-schedule.sh"
```

The schedule installs two systemd timers: the import at 01:00 UTC and the database dump at
06:00 UTC. The gap holds a full ~3.8 h import plus the search-index refresh.

## Everyday operations

| Command | Effect |
|---|---|
| `make deploy` | sync, build, migrate, restart |
| `make remote-import` | full import: queue, four workers, wait for the queue to drain, close the run, refresh the search index |
| `make remote-status` | `app:import:status` on the host |
| `make remote-logs` | follow container logs |
| `make remote-shell` | interactive shell on the host |

`CARS_INSTANCE_ID` must be exported in the shell for all of them.

The import script starts the workers and removes them afterwards, because four idle workers hold
roughly 400 MB that the 2 GB host needs elsewhere. It waits for three consecutive empty readings
of the queue one minute apart before closing the run, and gives up after nine hours with a
non-zero exit status.

Three properties of that script matter operationally.

**It refuses to start while `import_run` holds a `running` row**, before it touches anything —
message `an import run is already running`, exit status 1, nothing started and nothing removed.
`make remote-import` bypasses the timer, so this is what keeps a daytime run from colliding with a
nightly one still in flight; trap 11 explains why the shutdown handler cannot be safe without it,
and what it costs.

**The workers come up before `app:import:start`**, so a container that cannot start — a logging
driver that will not initialise is the realistic case, trap 10 — fails the script before an
`import_run` row exists.

**A shutdown handler runs on every exit path**, including `SIGINT` and `SIGTERM`
(`systemctl stop cars-import`, a host shutdown mid-run): it removes the worker containers and,
when the exit status is non-zero, closes the still-`running` row with
`app:import:finish <id> --failed`. Without it a timeout or a mid-run failure would leave a
`running` row that blocks every later `app:import:start` on
`import_run_one_running_per_source`.

## Logs

`api`, `caddy`, `database` and `rabbitmq` log to `json-file`, rotated at `10m × 3`, and are read
live with `make remote-logs`. **The import workers do not.** They ship to CloudWatch Logs, because
`import.sh` deletes their containers when the run ends and a container's `json-file` log dies with
it — a finished import would otherwise have left no record at all. The reasoning, the level and
the cost arithmetic are in ADR 0022; this section is how to use it.

| | |
|---|---|
| Log group | `/cars/worker`, also printed by `terraform output worker_log_group` |
| Streams | one per container, named by container ID — no prefix (ADR 0022 §1: AL2023's `docker` package rejects `awslogs-stream-prefix`) |
| Retention | `var.log_retention_days`; the value and the reasoning are ADR 0022 §2 |
| Format | one JSON object per line, so Logs Insights discovers `message`, `level_name`, `channel` and `context.*` as fields |

**Only the workers are in `/cars/worker`.** `app:import:start` and `app:import:finish` run through
`exec -T api`, so their output goes to the `api` container's `json-file` log, and a failure of
`import.sh` itself — before any worker starts, or in the shutdown handler — goes to the host's
journal (`journalctl -u cars-import.service`). An empty group in the morning therefore means
either "the timer did not fire" or "the run never got as far as starting a worker"; those two
logs tell them apart, and this one cannot.

Following a run as it happens, which is what `make remote-logs` does for the other four services:

```bash
aws logs tail /cars/worker --follow --since 10m --region eu-central-1
```

Everything else is a Logs Insights query. Get the run id first — `make remote-status` prints it,
or `SELECT id FROM import_run ORDER BY id DESC LIMIT 1`. **Every query that accounts for a run is
scoped by `context.runId`**, because the group holds several nightly runs at once and an unscoped
query silently adds them together. Two of the queries below cannot be scoped that way — the source
adapter's warnings and Messenger's own records carry no `runId` — so for those, set the Insights
time range to the run's window instead.

**How much did last night's import do:**

```
filter channel = "import" and context.runId = 42
| stats count() by message
```

`import.version_written` plus `import.version_unchanged` is the number of pages that reached the
catalogue. The split matters: from the second run onward the `ETag` path turns almost every page
into `import.version_unchanged`, so a healthy nightly run has a small written count and a large
unchanged one. A run where both are small did not work.

**What was skipped, per run.** One record per skipped page, so this accounts for the run and
nothing is counted twice:

```
filter message = "import.record_skipped" and context.runId = 42
| stats count() by context.reason
```

The closed set of values is ADR 0009's. `no_usable_variants` at roughly 17% of pages is normal,
not a fault. Note that **`absent` is a superset**: the adapter returns "absent" both for a page the
source does not have and for a page its parser rejected, so a page dropped for an unknown fuel
token is counted here as `absent`. `implausible_value` is reserved by ADR 0009 and has no producer
in the code — it will never appear.

**Why the adapter rejected pages**, as a separate diagnostic. These are the adapter's own warnings
on the `app` channel; they carry no `runId`, they explain part of the `absent` count above, and
**they must not be added to it**:

```
filter channel = "app" and level_name = "WARNING"
| stats count() by message
```

One row of that result is not a rejection. `autocentrum: breadcrumb name does not extend the
shallower prefix, keeping the full name` is a non-fatal warning — the page is parsed and written.
Grouping by `message` is what keeps it separable: subtract that row before comparing the rest
against the `absent` count.

**Which attempts failed.** This counts **attempts, not pages** — `import.record_failed` is written
again on every redelivery, so a page that never succeeds appears here six times:

```
filter message = "import.record_failed" and context.runId = 42
| stats count() by context.exception.class
```

`UniqueConstraintViolationException` and `DeadlockException` here are expected on a first full
run — two sitemap URLs converging on one canonical page — and Messenger retries them; the retry
lands on a `304` and costs one conditional request. Any other class, or these two in the
thousands, is a real fault.

**Which pages were lost for good.** `max_retries: 5` (`config/packages/messenger.yaml`) means six
attempts, so six failures for one `ref` means the retries were exhausted:

```
filter message = "import.record_failed" and context.runId = 42
| stats count() as attempts by context.ref
| filter attempts > 5
| sort attempts desc
```

Corroborate it against Messenger's own verdict. **That record is `CRITICAL`, not `ERROR`** — a
query filtering on `ERROR` alone never sees the one line that says a page was abandoned — and it
carries no `runId`, so scope it by the time range:

```
filter level_name = "CRITICAL" and channel = "messenger"
| stats count() by context.exception.class
```

Its count should equal the number of refs from the previous query, and it is what the `failed`
queue depth in `make remote-status` grew by over the run — that queue is not emptied between runs,
so compare the delta, not the absolute number. This record carries no `ref`, which is why the refs
come from the previous query and not this one.

**One page's whole story:**

```
fields @timestamp, context.runId, message, context.reason, context.exception.class
| filter context.ref like "audi/a4"
| sort @timestamp asc
```

## Restoring the database

```bash
aws s3 ls s3://<backup bucket>/postgres/
aws s3 cp s3://<backup bucket>/postgres/cars-<stamp>.dump .
```

Copy the dump onto the host, then:

```bash
docker compose --env-file .env.prod.local -f compose.prod.yaml exec -T database \
    pg_restore -U cars -d cars --clean --if-exists < cars-<stamp>.dump
```

## Billing

A new account starts with USD 100 in credits and up to USD 100 more for completing five
onboarding activities. **Choose the paid account plan, not the free plan.** The free plan
closes the account after six months or when the credits run out, with a 90-day window to
recover the data; on the paid plan the same credits simply pay the bill, and they stay valid
for twelve months from account creation.

Terraform creates a monthly budget with alerts at 80% of actual and 100% of forecast spend.
The one-year Savings Plan that takes the instance from USD 14.02 to USD 8.83 per month is
bought in the console — it is a billing commitment, not infrastructure, so it is deliberately
not in Terraform.

## Traps

1. **`terraform destroy` does not delete the root volume.** It is created with
   `delete_on_termination = false` so that a rebuild cannot silently take the database with it.
   The orphaned volume keeps costing money and has to be deleted by hand.
2. **The AMI is pinned by `ignore_changes`.** Amazon publishes new AL2023 images constantly and
   without this every `terraform apply` would propose replacing the host, database and all.
   Moving to a new AMI is a deliberate rebuild plus a restore from a dump.
3. **`user-data.sh` runs on first boot only.** If it fails, the instance comes up without Docker
   and `make deploy` fails on the first remote command; the log is
   `/var/log/cloud-init-output.log`.
4. **Do not publish the database or queue ports.** `compose.prod.yaml` deliberately has no
   `ports:` on `database` and `rabbitmq`; the security group would let the whole internet in.
5. **`.env.prod.local` is excluded from both rsync and the image.** It exists only on the host.
   A deploy never overwrites it, and `--delete` does not remove it.
6. **The SPA never carries the API key.** `vite.config.ts` injects it in the dev-server proxy
   only, so it is absent from `dist/`. Production reproduces that server-side: Caddy strips the
   `/api` prefix and adds `X-API-Key` before passing the request to PHP. Serving the SPA from
   anywhere that cannot inject the header — a plain bucket, a CDN with no origin logic — would
   force the key into the bundle, where every visitor can read it.
7. **`front/` is excluded from the code sync.** It is written by a second rsync of the build
   output, so `--delete` on the main sync must never see it.
8. **The RabbitMQ container's `hostname` is fixed.** RabbitMQ stores its data under the node
   name, so changing the hostname loses every queued message.
9. **`docker logs` cannot read the workers, and `docker compose logs` with no service names
   fails outright** when any container in the project uses a driver that cannot be read
   (docker/compose#8136, #9119, #12980). `make remote-logs` therefore names `api`, `caddy`,
   `database` and `rabbitmq` explicitly; dropping that list makes the target fail for the whole
   stack while an import is running. The workers are read with `aws logs tail`.
10. **A misconfigured log group stops the import instead of degrading it.** A container whose
    logging driver cannot initialise never starts, so `--profile import up -d worker` fails and
    the 01:00 run fails with it. Three causes: `terraform apply` has not created `/cars/worker`,
    the instance role has lost `aws_iam_role_policy.logs`, or `compose.prod.yaml`'s literal group
    name and region no longer match what Terraform built (`infra/CLAUDE.md`, decision 10). Run
    `terraform apply` before the first import after a change to either side. The symptom is a
    Compose error naming the logging driver, not a PHP error. `import.sh` starts the workers
    **before** `app:import:start`, so this failure happens before an `import_run` row exists and
    leaves nothing to unblock; its shutdown handler closes the run as `failed` for every later
    failure.
11. **A stale `running` row blocks every later import, and that is the intended behaviour.**
    `import.sh` refuses to start whenever `import_run` holds a `running` row. It has to: nothing
    else ties the row the shutdown handler finds to the invocation that is exiting, and
    `make remote-import` runs the script directly over SSM, outside the timer. Without the guard a
    manual run started while the nightly one is in flight would fail on `app:import:start`, then
    tear down the other run's four workers and close its live row as `failed` on the way out —
    a whole night of import lost and a row that lies about its status. With the guard, and with
    `import_run_one_running_per_source` permitting at most one such row at a time, every `running`
    row the handler can see belongs to the invocation that created it.
    The price is that a run killed before its handler could run — host reboot, `SIGKILL`, an
    `oom-killer` — leaves a row that no later run clears in passing. **The operator clears it by
    hand.** Confirm the previous run is really dead (`make remote-status`, and no `worker`
    containers in `docker compose ps`), then close it:
    `app:import:finish <id> --failed`. That is the same unblocking procedure as before; what
    changed is that it is now the only one.
