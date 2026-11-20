# Stratus-2

Nextcloud-based decentralized storage at **https://2strat.us**, with owner-approved AI organization, Storj primary storage, Supabase identity and Stripe storage subscriptions.

This repository extends Nextcloud through `stratus2_ai`, `stratus_transfer` and Python Lambda workers. It does not patch Nextcloud core. The deployment uses one `t3.xlarge`, single-node K3s, PostgreSQL and Redis, a Terraform-managed public ALB, ACM, SQS, ECR, Secrets Manager and encrypted S3 backups. It does not provision EKS, RDS, NAT gateways, EFS or ElastiCache.

## Photo enhancement

The app supports optional paid photo enhancement with Stripe subscriptions. See [photo enhancement](docs/photo-enhancement.md) for how the worker and billing integration fit together.

## Direct transfers

**Transfers** provides short-lived, owner-scoped browser-to-Storj downloads, with progress, retries and pause/resume for supported browsers. AWS handles authentication, permissions and metadata. Native Nextcloud downloads remain available for other clients. Direct uploads remain disabled because the installed Nextcloud release has no supported safe staged-upload finalization interface. Existing uploads retain Nextcloud's metadata, quota, versions and trash lifecycle. See [transfer safety, flags, metrics and verification](docs/transfers.md).

## Organizer

Select an owned folder, edit the instructions, grant image-processing consent and start analysis. Lambda downloads files through expiring, job-scoped Nextcloud capabilities, calculates SHA-256, generates small previews, measures blur, calculates perceptual hashes and requests structured OpenAI descriptions. Results are cached per user and content/instruction/model combination.

The app displays proposed file locations before approval. It moves files exclusively through Nextcloud APIs, preserves extensions, adds file-ID suffixes to prevent collisions and saves a durable undo manifest before each move. Exact duplicates and possible blur go to `to_delete`; similar images are flagged for review. Nothing is permanently deleted. Matching `photo.peoplewelcome.json` sidecars move with their files and retain their original content for undo.

Jobs accept at most 500 files and files up to 512 MiB. There is no daily AI account-use cap. Blur suggestions use preview images and must be reviewed; they are not a quality guarantee. The MVP is designed for a single node, with automatic container restart and recovery from a durable job manifest.

## Start here

- [Run the isolated development stack](docs/deployment.md).
- [Configure your own providers](docs/providers.md) for a hosted installation.

## Repository map

| Path | Purpose |
| --- | --- |
| `apps/stratus2_ai` | Nextcloud UI, job authorization, review/apply/undo, billing and worker capabilities |
| `apps/stratus_transfer` | Scoped direct Storj downloads, resume controls, safe Nextcloud fallback and transfer estimates |
| `worker` | Read-only, retryable SQS Lambda analysis |
| `nextcloud` | Pinned Nextcloud image, branding, proxy, object storage and OIDC configuration |
| `infra/bootstrap` | Protected state bucket, ECR, GitHub OIDC role and empty secret containers |
| `infra/runtime` | Single-node AWS runtime, TLS/DNS, queues, workers, backups and alerts |
| `deploy` | Persistent Kubernetes workloads and database network policy |
| `scripts` | Deployment preflight, secret-safe publishing, SSM rollout and backup |

## Validation

```sh
python -m venv .venv
# Activate the environment for your shell.
pip install -r requirements-dev.lock
ruff check worker scripts tests
pytest -q
php tests/php/domain.php
php tests/php/transfer.php
node --test tests/js/transfer.test.mjs
terraform fmt -check -recursive infra
terraform -chdir=infra/bootstrap init -backend=false
terraform -chdir=infra/bootstrap validate
terraform -chdir=infra/runtime init -backend=false
terraform -chdir=infra/runtime validate
```

CI also builds the actual Nextcloud image against an isolated pinned Moto S3 primary store, enables the apps, performs file-ID moves/sidecar changes/undo, tests transfer scope and concurrent accounting, and restores a PostgreSQL dump into a fresh database. Test account commands are disabled outside isolated test mode. Live Storj/browser checks are recorded separately in the transfer documentation.

## Credential handling

`.env.local`, application secret JSON files, Terraform state/plans, private keys and local tooling are ignored by Git and excluded from Docker build contexts. CI scans tracked content and staged-content scanning is available with `python scripts/check_secrets.py --staged`. Deployment templates use short-lived OIDC credentials restricted to the operator's `production` environment. Application secret values are supplied outside Terraform and never enter its state. Lambda receives only the OpenAI secret and queue permissions, with no Nextcloud administrator or Storj credentials.

Single-node K3s workloads share a host trust boundary. PostgreSQL/Redis cannot initiate network connections; Nextcloud needs instance-role access for the queue. A future multi-node migration should introduce workload-specific AWS identities and database HA before adding replicas.

Made by [Heraldic](https://www.heraldic.cloud).

License: AGPL-3.0-or-later. Nextcloud and bundled dependencies retain their own licenses.
