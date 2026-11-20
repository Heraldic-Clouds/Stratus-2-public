# StratusTransfer

StratusTransfer adds direct, resumable downloads for authenticated owners of unencrypted files in the existing Storj primary store. It uses the current Nextcloud/Supabase session. It does not replace Nextcloud, change the primary bucket, or modify file-cache/database tables.

## Upload safety decision

The deployed release was inspected before implementation: Nextcloud **35.0.2.2**, AWS PHP SDK **3.398.3**, primary backend `OC\Files\ObjectStore\S3`. Nextcloud's chunked WebDAV upload implementation owns allocation, multipart tokens, permissions, locks, quota updates, write hooks, versions and completion. This release has no supported direct-upload/staged-object adoption interface.

**Direct uploads remain disabled.** A flag cannot override this restriction. No write URL, staging object or fake finalization endpoint is exposed. Nextcloud's existing upload path remains responsible for multipart/resume, cancellation, retries, concurrent quota enforcement, metadata and versions. Registering a separately uploaded Storj object would require changing that lifecycle or writing private metadata, which is outside this update's safety constraints. Merely copying a staged object to an existing `urn:oid:` key is unsafe.

Relevant inspected source: [35.0.2 ObjectStoreStorage](https://github.com/nextcloud/server/blob/v35.0.2/lib/private/Files/ObjectStore/ObjectStoreStorage.php), [DAV chunk finalization](https://github.com/nextcloud/server/blob/v35.0.2/apps/dav/lib/Upload/ChunkingPlugin.php), [object store interface](https://github.com/nextcloud/server/blob/v35.0.2/lib/public/Files/ObjectStore/IObjectStore.php). Upstream's [direct-transfer proposal](https://github.com/nextcloud/server/issues/65096) is an open proposal, not an installed supported API.

## Direct downloads

Open **Transfers** in the Nextcloud navigation. Chromium-based browsers with File System Access and Web Locks can stream ranges directly to a user-selected file. Other browsers, desktop/mobile clients, public shares, encrypted files and unsupported storage wrappers continue using Nextcloud. Readable shared files have a Nextcloud fallback; direct URLs are currently owner-only. Existing Files, WebDAV and AI Organizer routes remain in place.

The server resolves IDs inside the signed-in user's file tree, checks readability and ownership, rejects encryption and unknown storage wrappers, and lets the inspected core storage resolve the object key. Only a single-object `GetObject` request is signed, with `If-Match` included in the signature. A HEAD request verifies the stored size; a renewed URL must retain both Nextcloud and Storj ETags. No client-supplied bucket or object key is accepted. Native file IDs, shares, ETags, versions, trash, quotas and PeopleWelcome sidecars are not rewritten.

The normal Files Trashbin wrapper was inspected and only changes deletion behavior. Files Lock 35.0.1 was also inspected: reads remain allowed, while writes are checked against locks. That independently updatable wrapper is allowed only when its source matches the reviewed SHA-256; an app update otherwise falls back to Nextcloud. The AI app's dependency autoloader is appended after Nextcloud's loader so a newer bundled dependency cannot partially replace shared storage libraries.

Links expire after 120 seconds by default, bounded to 30–300 seconds. Each renewal rechecks access and metadata. Revocation does not invalidate an already-issued bearer URL before its expiry. Expiry prevents starting a new storage request; an already accepted request can finish afterward. Disabling the feature stops new links and renewals. Presigned URLs necessarily contain the access-key identifier and signature; permanent secret keys, account passwords and access grants are never sent to the browser. URLs are neither persisted in resume records nor logged by the app.

The browser uses 64 MiB range requests, streams bounded chunks to disk, and rejects missing/incorrect Content-Range, ETag changes, ignored ranges and excess bytes. Interrupted reads retry from bytes already written. Pause closes the output before recording a resume point; resume checks the local file size/modified time and remote identity. Unexpected browser termination before the output closes may require restarting. Cancel leaves any partial local file for the user to remove. Local resume handles/metadata live in same-origin IndexedDB and are separated by Nextcloud user ID. The browser needs permission to reuse a saved handle.

Downloading consumes no storage quota; users above quota can still read their files. The upload planning endpoint checks folder creation permission and available quota, then explicitly returns the supported Nextcloud path. The actual upload's concurrency and quota checks remain in native DAV.

## Storj CORS

The existing gateway was probed without changing its configuration. GetBucketCors returned wildcard origins/headers/exposed headers and allowed GET/PUT/HEAD; OPTIONS from `https://stratus.example.com` accepted Range and If-Match. Presigned requests use `credentials: omit`, so storage cookies and Nextcloud credentials are not forwarded. The Transfers page allows only the configured Storj origin in connect-src and sends no referrer.

Browser GET responses must expose ETag and Content-Range. If a gateway later stops supporting the required CORS behavior, direct saving fails safely and offers the existing Nextcloud download link. No AWS byte proxy or new CDN is introduced to work around CORS. Production storage endpoints must be HTTPS Storj origins. Plain HTTP is permitted only when both isolated CI test-mode flags are set.

## Flags and metrics

Nextcloud app configuration, managed through `occ config:app:set stratus_transfer KEY --value=VALUE`:

| Key | Default | Meaning |
| --- | --- | --- |
| `direct_downloads` | `0` | `1` permits supported direct downloads |
| `direct_uploads` | `0` | Reserved; unsupported on this release even if set to `1` |
| `url_ttl_seconds` | `120` | Clamped to 30–300 seconds |
| `egress_usd_per_gb` | `0.09` | Planning rate, clamped to 0–1 USD per decimal GB |

Manage transfer flags through Nextcloud configuration and retain existing storage when updating the application.

The page records browser-reported saved positions separately for direct and AWS-proxied downloads initiated here. Locked, owner-scoped receipts make progress retries idempotent. Contending requests wait for up to five seconds; status and renewal reads do not rewrite the receipt object. Receipt positions cannot exceed the authorized file size. No signed URLs are stored. A failed statistics update cannot turn a fully saved download into a failed download. Estimates are direct saved bytes × configured rate and exclude free allowances, tiers, taxes and discounts. These untrusted browser reports are **not billing measurements**, physical retransmission totals, or measurements of other Files/WebDAV/AI traffic. Downloads started through the fallback link outside this component are not counted.

## Validation

Local tests cover interrupted ranges, retries, pause/resume, stale ETags, ignored/wrong ranges, URL renewal, endpoint/wrapper rejection and accounting bounds. CI installs real Nextcloud against an isolated pinned Moto S3 server and verifies signed object preconditions, direct range reads, owner/ticket isolation, safe upload fallback, quota checks and metadata preservation. Four independent PHP processes exercise real concurrent receipt updates and verify that retries do not inflate totals. Existing AI organization, duplicates, sidecars and undo checks run against the same S3-backed installation.

The operator-run `tests/browser/transfer-live.mjs` harness requires explicit `--confirm-live-tests`, an existing disposable account named `stratus_transfer_verify_` followed by ten lowercase hex digits, and local ignored `.tools/transfer-verification.json` with its `uid`. The account must contain zero-filled `100MB.bin`, `1GB.bin` and `10GB.bin` in `Transfer verification`. Its disposable password is derived in memory from the existing protected `WORKER_HMAC_SECRET` and the fixture UID; neither the password nor settings may be supplied as command-line arguments. Prepare and remove fixtures through native Nextcloud APIs, never through raw managed-object writes or database edits.

The harness uses real Chromium, real Storj CORS/ranges and real browser file writes. It replaces only the chooser with an origin-private FileSystem handle in a fresh disk-backed test profile, checks every saved byte, pauses/reloads/resumes the 1 GB transfer, checks metadata and rejects forged receipts/missing CSRF. The profile is deleted after the run. Private browser contexts are unsuitable because their origin-storage quota is smaller than the largest fixture. Chrome DevTools records request destinations, actual credential headers and response-byte counts without saving signed URLs. `--file=100MB.bin --verify-metrics-outage --verify-expiry` also checks a failed statistics update, signed-header/method enforcement, a valid link becoming unusable after expiry, native WebDAV ranges, concurrent small native uploads and capability fallback. Method binding is tested with a read-only HEAD request. The unsupported-capability navigation is intercepted before its bulk request; the separate native range check exercises real WebDAV.

Run it only from an authorized operator checkout with the prepared fixtures, protected local settings, installed Playwright Chromium and at least 12 GB free disk. It is deliberately excluded from ordinary CI. Ignored local reports contain byte counts and booleans, never signed URLs or credentials. Native chooser interaction and physical desktop/mobile clients still need manual validation.
