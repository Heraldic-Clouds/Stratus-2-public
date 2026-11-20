# Development and deployment

Run the isolated development stack with Docker Compose. Supply disposable local values for TEST_DB_PASSWORD and TEST_ADMIN_PASSWORD, then run docker compose -f compose.test.yaml up --build. The test installation uses an S3 emulator and is available on localhost:8080.

For a hosted installation, configure the Terraform modules under infra with your own AWS account, DNS zone, OIDC repository subject and image digests. Review the plan before creating resources. Set up the external services through the [provider guide](providers.md). Store real configuration outside Git.

Use placeholder domains in examples. Configure and review origin and OAuth allowlists for your deployment before enabling sign-in. Store additional Nextcloud admin CIDRs in a private file named z-stratus2-admin-ranges.config.php in the persistent Nextcloud config directory. Retain loopback for local recovery and verify allowed and disallowed requests before exposing the service.

The repository runs validation with isolated credentials. Operators should configure their own protected release pipeline. The [recovery notes](recovery.md) describe backup and restore requirements.
