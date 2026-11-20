# Provider configuration

Use your own AWS Secrets Manager entries for provider credentials and supply their resource identifiers through deployment configuration.

- **Storj**: Set STORJ_ENDPOINT, STORJ_BUCKET, STORJ_REGION, STORJ_ACCESS_KEY and STORJ_SECRET_KEY. Use a dedicated private bucket and a bucket-scoped credential. Keep the existing bucket for any initialized Nextcloud instance.
- **Supabase**: Set SUPABASE_URL, SUPABASE_PUBLISHABLE_KEY, SUPABASE_OIDC_CLIENT_ID and SUPABASE_OIDC_CLIENT_SECRET. Configure a confidential OAuth client with the exact Nextcloud callback URLs, PKCE and the token authentication method supported by your provider.
- **Stripe**: Set STRIPE_SECRET_KEY and STRIPE_WEBHOOK_SECRET. Configure your own product prices and portal settings, and register the app webhook endpoint. Test with sandbox prices first. Preserve legacy price mappings for existing subscribers.
- **OpenAI**: Put OPENAI_API_KEY in a separate secret accessible to the image workers.

Do not commit credentials, account IDs, live price IDs, private IP ranges, infrastructure state or backup archives.
