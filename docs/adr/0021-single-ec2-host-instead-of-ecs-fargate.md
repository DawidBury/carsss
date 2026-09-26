# ADR 0021 — A single EC2 host running Docker Compose, instead of ECS/Fargate

Status: accepted · Date: 2026-09-05 · Narrows ADR 0013

## Context

The owner asked for a production deployment on AWS under a hard constraint that was not
known when ADR 0013 was written: **a new account, a total budget of about USD 120, traffic
limited to the owner and a few friends, and response times comparable to the local stack.**

ADR 0013 assumed ECS/Fargate with RDS, Amazon MQ and an ALB. Priced at the smallest
supported sizes in `eu-central-1`, that shape costs about **USD 115 per month at zero
traffic**, because every component in it is billed for existing rather than for serving:

| Component | USD/month |
|---|---|
| ALB | 16.4 |
| NAT Gateway | 32.8 |
| RDS `db.t4g.micro` + 20 GB gp3 | ~16 |
| Amazon MQ `mq.t3.micro` | ~27 |
| Fargate `api-service`, 0.25 vCPU / 0.5 GB | ~10.4 |
| Fargate `worker-service` | ~10.4 |
| Secrets Manager, CloudWatch Logs | ~2 |
| **Total** | **~115** |

The whole budget buys one month. A trimmed variant — tasks in public subnets instead of a
NAT Gateway, the Doctrine transport instead of Amazon MQ — still lands near USD 55 per month.

Cost is not the only problem. On ECS the rate limiter's token bucket is per task, so ADR 0015
caps the import at `desired_count = 1`, and a full run takes **~19 h instead of ~3.8 h**.

## Decision

Run the entire stack on **one EC2 `t4g.small` instance in a public subnet**, with the same
Docker Compose topology as the local stack: PostgreSQL, RabbitMQ, PHP-FPM, four import
workers, and Caddy terminating TLS with Let's Encrypt.

| Cost of this shape | USD/month |
|---|---|
| EC2 `t4g.small`, on-demand at $0.0192/h | 14.02 |
| EC2 `t4g.small`, one-year Savings Plan, no upfront | 8.83 |
| EBS gp3, 20 GB | 1.90 |
| Public IPv4 address | 3.65 |
| Egress | 0.00 — within the 100 GB/month always-free allowance |
| `pg_dump` to S3, 30 daily dumps | 0.03 |
| **Total, on-demand / with the Savings Plan** | **~19.6 / ~14.4** |

Four import workers stay legal here, and this is the load-bearing part of the decision.
The limiter keeps its state in a filesystem cache pool under `var/share/<env>/pools`
(`cache.rate_limiter` inherits from `cache.app`, and `kernel.share_dir` is `var/share`).
All worker replicas mount the **same named volume** at `/app/var/share`, so they share one
token bucket, exactly as the local bind mount does. ADR 0015's condition for raising the
worker count — "first move the limiter state to shared storage" — is therefore satisfied
rather than bypassed, and a full run takes ~3.8 h.

The volume is mounted at `/app/var/share`, never at `/app/var`. A named volume over `/app/var`
would freeze the container cache baked into the image and every redeploy would start the
worker with a stale dependency-injection container.

## What ADR 0013 keeps

- **Terraform, not the console and not CDK.** `infra/terraform/envs/production/`.
- **Remote state in S3 with locking.**
- **One image, four run units differing only by command.** Compose services `api` and
  `worker` share `image: cars:latest`; migrations run as `compose run --rm api …`.
- **Migrations run before traffic is switched**, as a separate invocation in `deploy/deploy.sh`,
  never at container start.
- **`GET /health` outside `/v1`, unauthenticated, no database access.**
- **The environment-variable contract in `infra/CLAUDE.md` is unchanged.** No file under
  `src/` or `config/` changes because of this ADR.

## What changes

- `modules/{network,database,queue,secrets,api-service,worker-service,import-task,migration-task}`
  collapse into one environment directory with resources declared inline. A Terraform module
  with exactly one caller is the single-implementation abstraction that `CLAUDE.md` §3 forbids.
- There is **no staging environment**. Two environments double the largest line on the bill for
  a system with one operator. Adding one later means copying the directory.
- Managed backups are replaced by a nightly `pg_dump --format=custom` to S3 with 30-day
  expiry, and the root volume is kept on instance termination.
- The import is scheduled by a systemd timer on the host instead of EventBridge Scheduler.

## Consequences

- **A single point of failure and a maintenance window.** `deploy/deploy.sh` stops the API for
  a few seconds. There is no redundancy: the instance, the database and the queue die together.
  This is accepted deliberately for a system whose users are the owner and a few friends.
- **The host is stateful.** The database lives on the root EBS volume, so the volume is created
  with `delete_on_termination = false` and dumps go to S3 nightly. Terminating the instance
  leaves an orphaned volume that has to be deleted by hand.
- **2 GB of RAM is the binding constraint**, which is why workers run under the `import`
  Compose profile and exist only during an import window.
- **The scaling path is unchanged.** Should the traffic assumption ever break, ADR 0013's shape
  is still the target, and nothing in `src/` has to move to get there.

## Rejected alternatives

**The full ECS/Fargate design from ADR 0013.** Rejected on price: one month of budget, and a
19-hour import.

**Trimmed ECS/Fargate** — public subnets instead of NAT, Doctrine transport instead of
Amazon MQ. About USD 55 per month, still roughly three times this shape, still one worker,
and it trades the queue's operational behaviour away for a saving that does not close the gap.

**Amazon Lightsail, 2 GB bundle, USD 12 flat.** Cheaper than on-demand EC2 and simpler to bill,
but it has no burst-credit "unlimited" mode: a 3.8-hour import that exceeds the baseline is
throttled rather than charged a few cents. It also sits outside Terraform's usual VPC model
and cannot take a Savings Plan, which is what makes EC2 cheaper over a year.

**Lambda plus Aurora Serverless v2 scaled to zero.** The intuitive answer to "almost no
traffic", but Aurora's floor alone exceeds the cost of this whole host, and the application is
a long-lived PHP-FPM process with a queue consumer. Fitting it to Lambda is a rewrite, not a
deployment.

**Keeping the instance switched off outside working hours.** EC2 is billed only while running,
but the volume and the address are billed regardless, and the API is supposed to answer when a
friend opens it.
