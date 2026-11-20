# Backup and recovery

This MVP has one node in one Availability Zone. Reboots retain EBS state; loss of that node or AZ requires recovery. It is not a multi-AZ service.

The daily backup enables Nextcloud maintenance mode, creates a PostgreSQL custom-format dump, saves Nextcloud configuration and Kubernetes resource definitions, then disables maintenance mode. It uploads a checksum manifest and the archive to a private, versioned, encrypted S3 bucket. A CloudWatch alarm reports missing successful backups. Runtime secrets are kept in Secrets Manager; configuration archives may contain sensitive Nextcloud keys and must remain access-controlled.

## Recovery prerequisites

- The encrypted backup archive and its checksums.
- The Nextcloud image digest recorded in its manifest.
- The same primary Storj bucket and an agreed way to recover objects to the metadata restore point.
- The original Secrets Manager credentials, Nextcloud config salt and instance secret.
- A clean recovery target; never restore over a running production database.

## Clean deployment drill

1. Select a backup and record its timestamp/image. Do not print configuration contents.
2. Create an isolated replacement node/cluster after cost approval. Keep DNS and outbound worker processing disconnected from production during the drill. A separate node costs extra while it exists.
3. Start PostgreSQL and Redis using the original database/Redis secrets. Create an empty database; do not run a fresh Nextcloud installation before restoring metadata.
4. Download the encrypted S3 archive over TLS into a root-only temporary directory. Validate archive member paths before extraction and verify the recorded SHA-256 checksums.
5. Restore `postgres.dump` with `pg_restore --exit-on-error -U nextcloud -d nextcloud` into that empty database.
6. Restore the Nextcloud `config` directory to the clean application PVC, retain maintenance mode, then deploy the exact image digest from the backup manifest with the original runtime secrets. Do not replace primary storage with a different bucket or automatically update Nextcloud during this step.
7. Reconcile the Storj recovery point using the provider's supported recovery process. Confirm Nextcloud can read every sampled file, including deleted/versioned files from the backup window. Never run an independent organizer over primary objects.
8. Through Nextcloud, validate file IDs, ownership, shares, quotas, versions, sidecar associations and undo history. Run `occ maintenance:data-fingerprint` to tell sync clients that state was restored.
9. Leave production DNS unchanged until the drill is successful. For a real cutover, stop writes on the old instance, take a final consistent backup, switch the ALB target/DNS, then disable maintenance mode on the recovered service.
10. Record measured RPO/RTO and securely remove temporary archives. Remove the temporary recovery target only after verification and approval to discard it.

CI proves that a PostgreSQL dump can restore into a fresh database and that organizer undo works with real Nextcloud files. **It does not prove Storj object recovery, an EC2 replacement, whole-cluster recovery or public DNS/TLS cutover.** Those checks remain required before production acceptance.
