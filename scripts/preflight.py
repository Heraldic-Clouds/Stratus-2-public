"""Validate deployment credentials without revealing any values."""

import argparse
import base64
import hashlib
import json
import os
import re
import secrets
import sys
from urllib.parse import parse_qs, urljoin, urlsplit

if __package__:
    from .aws_support import client
else:
    from aws_support import client

REQUIRED = (
    "STORJ_ENDPOINT",
    "STORJ_BUCKET",
    "STORJ_ACCESS_KEY",
    "STORJ_SECRET_KEY",
    "POSTGRES_PASSWORD",
    "REDIS_PASSWORD",
    "NEXTCLOUD_ADMIN_USER",
    "NEXTCLOUD_ADMIN_PASSWORD",
    "WORKER_HMAC_SECRET",
    "SUPABASE_URL",
    "SUPABASE_PUBLISHABLE_KEY",
    "SUPABASE_OIDC_CLIENT_ID",
    "SUPABASE_OIDC_CLIENT_SECRET",
    "STRIPE_SECRET_KEY",
    "STRIPE_WEBHOOK_SECRET",
    "STRIPE_PRICE_ID",
)

CALLBACK_PATHS = ("/index.php/apps/user_oidc/code", "/apps/user_oidc/code")
REDIRECT_STATUSES = (302, 303, 307, 308)


def validate(settings, *, environment=None):
    configured_environment = settings.get("STRATUS2_ENVIRONMENT", "production")
    if environment is not None and configured_environment != environment:
        raise ValueError("App settings do not match the deployment environment")
    staging = configured_environment == "staging"
    local_auth = staging and settings.get("STRATUS2_LOCAL_AUTH") == "1"
    live_read_only = settings.get("STRATUS2_STRIPE_LIVE_READ_ONLY") == "1"
    if live_read_only and not staging:
        raise ValueError("Live read-only Stripe mode is limited to staging")
    if settings.get("STORJ_OBJECT_PREFIX") and (
        not staging or settings["STORJ_OBJECT_PREFIX"] != "stratus2-staging/urn:oid:"
    ):
        raise ValueError("Invalid staging object prefix")
    missing = [
        name
        for name in REQUIRED
        if not (local_auth and name.startswith("SUPABASE_"))
        and not (live_read_only and name in ("STRIPE_WEBHOOK_SECRET", "STRIPE_PRICE_ID"))
        and (not isinstance(settings.get(name), str) or not settings[name].strip())
    ]
    if missing:
        raise ValueError("Missing configuration fields: " + ", ".join(missing))
    for name in ("NEXTCLOUD_ADMIN_PASSWORD", "POSTGRES_PASSWORD", "REDIS_PASSWORD", "WORKER_HMAC_SECRET"):
        if len(settings[name]) < 32:
            raise ValueError(name + " must be at least 32 characters")
    if staging:
        values = [
            settings[name]
            for name in (
                "NEXTCLOUD_ADMIN_PASSWORD",
                "POSTGRES_PASSWORD",
                "REDIS_PASSWORD",
                "WORKER_HMAC_SECRET",
            )
        ]
        if len(set(values)) != 4 or any("REPLACE" in value or "GENERATE_" in value for value in values):
            raise ValueError(
                "Generate independent staging passwords and HMAC key; example placeholders are not credentials"
            )
        if live_read_only:
            if not settings["STRIPE_SECRET_KEY"].startswith(("sk_live_", "rk_live_")):
                raise ValueError("Read-only Stripe mode requires a live key")
            if not re.fullmatch(r"price_[A-Za-z0-9]+", settings.get("STRIPE_LEGACY_PRICE_ID", "")):
                raise ValueError("Configure the existing live Stripe price for staging reads")
            if settings.get("STRIPE_WEBHOOK_SECRET") or settings.get("STRIPE_WEBHOOK_ENDPOINT_ID"):
                raise ValueError("Live Stripe webhooks cannot be configured for staging")
        else:
            if not settings["STRIPE_SECRET_KEY"].startswith(("sk_test_", "rk_test_")):
                raise ValueError("Staging requires a sandbox key or explicit live read-only mode")
            if not settings.get("STRIPE_ADDON_PRICE_ID"):
                raise ValueError("Configure the staging add-on price")
    for name in ("STORJ_ENDPOINT",) if local_auth else ("STORJ_ENDPOINT", "SUPABASE_URL"):
        parsed = urlsplit(settings[name])
        if (
            parsed.scheme != "https"
            or not parsed.hostname
            or parsed.username
            or parsed.password
            or parsed.query
            or parsed.fragment
        ):
            raise ValueError(name + " must be a credential-free HTTPS URL")
    if not local_auth:
        parsed = urlsplit(settings["SUPABASE_URL"])
        if not parsed.hostname.endswith(".supabase.co") or parsed.path:
            raise ValueError("Use the hosted Supabase project origin")
    return settings


def validate_authorization_redirect(response, app_origin):
    if response.status_code not in REDIRECT_STATUSES:
        raise ValueError("Enable Supabase OAuth Server and register the Nextcloud client/callback")
    target = urlsplit(response.headers.get("Location", ""))
    expected = urlsplit(app_origin + "/index.php/apps/stratus2_ai/auth")
    if (target.scheme, target.netloc, target.path) != (
        expected.scheme,
        expected.netloc,
        expected.path,
    ) or not parse_qs(target.query).get("authorization_id"):
        raise ValueError("Set Supabase Site URL and Authorization Path to the Stratus-2 auth page")


def validate_oidc(settings, *, get=None, app_origin="https://stratus.example.com"):
    # The node imports validate() without needing the HTTP preflight dependency.
    if get is None:
        import requests

        get = requests.get
    origin = settings["SUPABASE_URL"]
    response = get(origin + "/auth/v1/.well-known/openid-configuration", timeout=20)
    response.raise_for_status()
    discovery = response.json()
    if discovery.get("issuer") != origin + "/auth/v1":
        raise ValueError("Unexpected Supabase issuer")
    algorithms = {"RS256", "ES256"}
    if not algorithms.intersection(discovery.get("id_token_signing_alg_values_supported", [])):
        raise ValueError("Enable asymmetric Supabase OIDC signing")
    response = get(origin + "/auth/v1/.well-known/jwks.json", timeout=20)
    response.raise_for_status()
    if not any(
        key.get("alg") in algorithms and key.get("use", "sig") == "sig"
        for key in response.json().get("keys", [])
    ):
        raise ValueError("Supabase has no active asymmetric signing key")

    # Discovery is public even when the OAuth server is disabled. Exercise client
    # registration and callback validation without following the redirect or signing in.
    # Apache's pretty URLs omit index.php. Both exact callbacks must be registered;
    # testing only the front-controller form missed a real production login failure.
    for callback_path in CALLBACK_PATHS:
        verifier = secrets.token_urlsafe(48)
        challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip("=")
        response = get(
            origin + "/auth/v1/oauth/authorize",
            params={
                "response_type": "code",
                "client_id": settings["SUPABASE_OIDC_CLIENT_ID"],
                "redirect_uri": app_origin + callback_path,
                "scope": "openid email profile",
                "code_challenge": challenge,
                "code_challenge_method": "S256",
                "state": secrets.token_urlsafe(24),
                "nonce": secrets.token_urlsafe(24),
            },
            timeout=20,
            allow_redirects=False,
        )
        validate_authorization_redirect(response, app_origin)


def validate_live_oidc(*, get=None, app_origin="https://stratus.example.com"):
    """Check the actual public login redirects without signing in or sending mail."""
    if get is None:
        import requests

        get = requests.Session().get
    response = get(app_origin + "/index.php/apps/stratus2_ai/auth/config", timeout=20, allow_redirects=False)
    response.raise_for_status()
    config = response.json()
    provider = urlsplit(config.get("url", ""))
    if (
        provider.scheme != "https"
        or not provider.hostname
        or not provider.hostname.endswith(".supabase.co")
        or provider.netloc != provider.hostname
        or provider.path
        or provider.query
        or provider.fragment
        or not config.get("clientId")
    ):
        raise ValueError("Public sign-in configuration must identify the hosted Supabase OAuth client")
    endpoint = config["url"] + "/auth/v1/oauth/authorize"
    current = app_origin + "/index.php/login"
    for _ in range(4):
        response = get(current, timeout=20, allow_redirects=False)
        if response.status_code not in REDIRECT_STATUSES or not response.headers.get("Location"):
            raise ValueError("Public Nextcloud login did not start the Supabase sign-in flow")
        target_url = urljoin(current, response.headers["Location"])
        target = urlsplit(target_url)
        if target_url.split("?", 1)[0] == endpoint:
            params = parse_qs(target.query)
            if (
                target.fragment
                or params.get("client_id") != [config["clientId"]]
                or params.get("response_type") != ["code"]
                or params.get("redirect_uri") not in [[app_origin + path] for path in CALLBACK_PATHS]
                or params.get("code_challenge_method") != ["S256"]
                or len(params.get("code_challenge", [])) != 1
                or not re.fullmatch(r"[A-Za-z0-9_-]{43}", params["code_challenge"][0])
                or len(params.get("state", [])) != 1
                or len(params.get("nonce", [])) != 1
                or len(params.get("scope", [])) != 1
                or not {"openid", "email", "profile"}.issubset(params["scope"][0].split())
            ):
                raise ValueError(
                    "Public Nextcloud OAuth request has an unexpected client, callback or PKCE parameters"
                )
            response = get(target_url, timeout=20, allow_redirects=False)
            validate_authorization_redirect(response, app_origin)
            return
        expected = urlsplit(app_origin)
        if (
            (target.scheme, target.netloc) != (expected.scheme, expected.netloc)
            or target.fragment
            or not re.fullmatch(r"(?:/index\.php)?/(?:login|apps/user_oidc/login/[0-9]+)", target.path)
        ):
            raise ValueError("Public Nextcloud login redirected outside the expected sign-in routes")
        current = target_url
    raise ValueError("Public Nextcloud login exceeded the redirect limit")


def validate_live_logout(*, get=None, app_origin="https://stratus.example.com"):
    """Check logout redirects in a fresh anonymous session, without user tokens."""
    if get is None:
        import requests

        get = requests.Session().get
    response = get(app_origin + "/index.php/apps/user_oidc/sls", timeout=20, allow_redirects=False)
    target_url = urljoin(app_origin, response.headers.get("Location", ""))
    target = urlsplit(target_url)
    expected = urlsplit(app_origin)
    if (
        response.status_code not in REDIRECT_STATUSES
        or (target.scheme, target.netloc) != (expected.scheme, expected.netloc)
        or target.path not in ("/index.php/apps/stratus2_ai/auth/logout", "/apps/stratus2_ai/auth/logout")
        or target.fragment
    ):
        raise ValueError(
            "Set the existing Nextcloud provider's logout endpoint to the query-free /auth/logout route"
        )
    response = get(target_url, timeout=20, allow_redirects=False)
    target = urlsplit(urljoin(app_origin, response.headers.get("Location", "")))
    if (
        response.status_code not in REDIRECT_STATUSES
        or (target.scheme, target.netloc) != (expected.scheme, expected.netloc)
        or target.path not in ("/index.php/apps/stratus2_ai/auth", "/apps/stratus2_ai/auth")
        or parse_qs(target.query) != {"mode": ["logout"]}
        or target.fragment
    ):
        raise ValueError("Nextcloud logout must reach the identity sign-out page with an exact logout mode")


def validate_token_authentication(settings, *, post=None, app_origin="https://stratus.example.com"):
    """Prove client_secret_post works using a deliberately nonexistent code.

    Pinned user_oidc 8.11.0 chooses this method whenever discovery advertises it,
    even if client_secret_basic is its configured default. Authentication must
    succeed before the provider rejects the fake code with invalid_grant.
    """
    if post is None:
        import requests

        post = requests.post
    response = post(
        settings["SUPABASE_URL"] + "/auth/v1/oauth/token",
        data={
            "grant_type": "authorization_code",
            "client_id": settings["SUPABASE_OIDC_CLIENT_ID"],
            "client_secret": settings["SUPABASE_OIDC_CLIENT_SECRET"],
            "code": "stratus2-preflight-invalid-" + secrets.token_urlsafe(32),
            "redirect_uri": app_origin + "/apps/user_oidc/code",
            "code_verifier": secrets.token_urlsafe(64),
        },
        timeout=20,
        allow_redirects=False,
    )
    if response.status_code == 400 and response.json().get("error") == "invalid_grant":
        return
    raise ValueError(
        "Supabase token authentication failed: set the existing confidential OAuth client's "
        "Token Endpoint Auth Method to Request body (client_secret_post) and verify its saved secret"
    )


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--live-oidc-only",
        action="store_true",
        help="Check public login/logout redirects without reading secrets",
    )
    parser.add_argument("--app-secret", default=os.environ.get("TF_VAR_app_secret_arn", "stratus2/app"))
    parser.add_argument(
        "--openai-secret", default=os.environ.get("TF_VAR_openai_secret_arn", "stratus2/openai")
    )
    args = parser.parse_args()
    try:
        if args.live_oidc_only:
            validate_live_oidc(app_origin="https://" + os.environ.get("TF_VAR_domain", "stratus.example.com"))
            validate_live_logout(app_origin="https://" + os.environ.get("TF_VAR_domain", "stratus.example.com"))
            print(
                "Public Nextcloud login/logout and Supabase PKCE callback accepted. No user sign-in or email was performed."
            )
            return 0
        sm = client("secretsmanager")
        app = validate(json.loads(sm.get_secret_value(SecretId=args.app_secret)["SecretString"]))
        worker = json.loads(sm.get_secret_value(SecretId=args.openai_secret)["SecretString"])
        if not worker.get("OPENAI_API_KEY"):
            raise ValueError("OpenAI key is not configured")
        validate_oidc(app, app_origin="https://" + os.environ.get("TF_VAR_domain", "stratus.example.com"))
        validate_token_authentication(
            app, app_origin="https://" + os.environ.get("TF_VAR_domain", "stratus.example.com")
        )
        print(
            "Credentials, Supabase signing keys, OAuth callbacks and token authentication validated. No values displayed."
        )
        return 0
    except ValueError as exc:
        print(str(exc), file=sys.stderr)
        return 1
    except Exception as exc:
        print(
            "Preflight failed: " + type(exc).__name__ + ". Credential values were suppressed.",
            file=sys.stderr,
        )
        return 1


if __name__ == "__main__":
    sys.exit(main())
