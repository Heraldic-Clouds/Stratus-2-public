# Photo enhancement and storage plans

## Behavior

- Free: 1 GB and AI organization. Paid: $10/month for 100 GB, plus $2/month per additional 100 GB. Additional blocks range from 0 to 99: 10 TB total costs $208/month. New storage quotas use decimal GB/TB.
- Paid users can colorize, reduce blur, improve lighting, stylize, or enter custom instructions. The backend checks the authoritative Stripe subscription before admission and generation. Manually raising a user's quota does not grant photo access.
- Organizer instructions mentioning common editing operations offer to open photo improvement with those instructions preserved. The user selects a photo and explicitly starts generation; dismissing the suggestion continues normal organization.
- No AI credits, daily account quotas, per-edit charges or monetary usage caps. The former 20-job Organizer daily cap is removed. Per-request image/batch validation and worker concurrency remain technical safeguards.
- JPEG/PNG/WebP, one photo per improvement, up to 40 MiB and 40 megapixels. Animated images are rejected. The original file and its metadata remain untouched.
- Results are private previews. **Save a copy** creates a collision-safe PNG beside the original through Nextcloud APIs, subject to storage quota. Source changes, forged capabilities and cross-user access are rejected. Saving and result submission are idempotent.
- Temporary results expire after 24 hours; saved copies stay in Files. The job records source checksum, instructions, model, release and output checksum as provenance. Metadata is stripped from the provider input/output.

## Processing

`worker.enhancement.handler` uses a separate queue and the existing OpenAI Secrets Manager ARN. Default model: `gpt-image-2`, medium quality, automatic size. Real provider/model access and quality still require staging acceptance.

Workers have no administrator or Storj credentials. They fetch an authorized owned source through expiring job-scoped HMAC capabilities. A durable claim prevents duplicate queue deliveries from repeating generation. A provider timeout can have an unknown billing outcome: generation is not automatically repeated; the user can explicitly start another edit. Output transport retries reuse the same output.

## Billing

Checkout combines one $10 base item and an optional $2 capacity item. Server-side validation rejects unexpected prices, duplicate base items and out-of-range quantities. Existing users preview a prorated charge and explicitly confirm it before a `pending_if_incomplete` subscription update. Signed lifecycle webhooks fetch current authoritative state; unpaid upgrades cannot grant extra capacity. Previously verified paid capacity is retained through its paid-through date during a pending mid-cycle upgrade. Unpaid/cancelled subscriptions return to free quota without deleting files. Unpaid trials do not grant paid access.

Existing $7 customers are not automatically repriced. Preserve the old ID as `STRIPE_LEGACY_PRICE_ID`; paid legacy users retain their legacy quota and photo access. Choosing a storage change explicitly opts into the new $10 base plus add-ons.

`scripts/configure_billing.php` prepares a separate sandbox catalog/portal/webhook and rejects live keys in staging. A temporary `STRIPE_SETUP_KEY` environment value can provide provisioning permissions while the JSON keeps a restricted runtime key. It never prints keys. Production setup requires explicit `--confirm-production`, leaves old prices/subscriptions alone, and preserves the existing webhook endpoint. Automatic tax collection is unchanged; review tax registrations before enabling Stripe Tax.

## Validation scope

Python tests cover authorized transport, duplicate claims, changed sources, ambiguous generation, output retries, image metadata and release guards. PHP domain tests cover prices, maximum capacity, malformed subscriptions, payment status and webhook signatures. Desktop/mobile Playwright tests use isolated HTTP fixtures for paid/free UI, presets, preview/save, pricing and layout.

CI includes a real Nextcloud integration test with isolated Stripe transport. Container integration, live sandbox billing, real OpenAI generation, upload/job continuity across switching, and a live rollback drill must pass before production acceptance. Payment risk cases such as refunds/disputes must also be reviewed before production payment acceptance.
