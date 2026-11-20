from unittest.mock import Mock
from urllib.parse import urlencode

import pytest

from scripts.preflight import CALLBACK_PATHS, validate_live_logout, validate_live_oidc, validate_oidc, validate_token_authentication


ORIGIN = "https://example.supabase.co"
APP = "https://stratus.example.com"
SETTINGS = {"SUPABASE_URL": ORIGIN, "SUPABASE_OIDC_CLIENT_ID": "nextcloud-client"}


def response(data=None, *, status=200, location=None):
    return Mock(
        status_code=status,
        headers={"Location": location} if location else {},
        json=Mock(return_value=data),
    )


def provider_responses(*, keys=None, authorization=None, pretty_authorization=None):
    return Mock(
        side_effect=[
            response({"issuer": ORIGIN + "/auth/v1", "id_token_signing_alg_values_supported": ["ES256"]}),
            response({"keys": keys if keys is not None else [{"alg": "ES256", "use": "sig"}]}),
            authorization
            or response(
                status=302, location=APP + "/index.php/apps/stratus2_ai/auth?authorization_id=request-id"
            ),
            pretty_authorization
            or response(
                status=302, location=APP + "/index.php/apps/stratus2_ai/auth?authorization_id=request-id"
            ),
        ]
    )


def test_public_discovery_does_not_hide_disabled_oauth_server():
    get = provider_responses(authorization=response({"error_code": "feature_disabled"}, status=404))
    with pytest.raises(ValueError, match="Enable Supabase OAuth Server"):
        validate_oidc(SETTINGS, get=get)


def test_discovery_algorithms_require_an_active_asymmetric_key():
    get = provider_responses(keys=[])
    with pytest.raises(ValueError, match="no active asymmetric signing key"):
        validate_oidc(SETTINGS, get=get)


def test_invalid_client_or_callback_blocks_deployment():
    get = provider_responses(authorization=response({"error": "invalid_client"}, status=400))
    with pytest.raises(ValueError, match="register the Nextcloud client/callback"):
        validate_oidc(SETTINGS, get=get)


@pytest.mark.parametrize(
    "location",
    [
        "http://localhost:3000/oauth/consent?authorization_id=request-id",
        APP + "/incorrect-path?authorization_id=request-id",
        APP + "/index.php/apps/stratus2_ai/auth",
    ],
)
def test_consent_must_use_the_production_auth_page(location):
    get = provider_responses(authorization=response(status=302, location=location))
    with pytest.raises(ValueError, match="Set Supabase Site URL"):
        validate_oidc(SETTINGS, get=get)


def test_ready_provider_validates_the_callback_without_visiting_the_site():
    get = provider_responses()
    validate_oidc(SETTINGS, get=get)
    requests = get.call_args_list[2:]
    assert [call.kwargs["params"]["redirect_uri"] for call in requests] == [APP + path for path in CALLBACK_PATHS]
    assert all(call.kwargs["allow_redirects"] is False for call in requests)
    assert all(call.kwargs["params"]["code_challenge_method"] == "S256" for call in requests)
    assert all(call.args[0].startswith(ORIGIN + "/auth/v1/") for call in get.call_args_list)


def test_registered_front_controller_callback_does_not_hide_rejected_pretty_callback():
    get = provider_responses(pretty_authorization=response({"msg": "invalid redirect_uri"}, status=400))
    with pytest.raises(ValueError, match="register the Nextcloud client/callback"):
        validate_oidc(SETTINGS, get=get)
    assert get.call_args.kwargs["params"]["redirect_uri"] == APP + "/apps/user_oidc/code"


def live_responses(*, callback=CALLBACK_PATHS[1], overrides=None, authorization=None, login=None, provider=ORIGIN):
    params = {
        "client_id": SETTINGS["SUPABASE_OIDC_CLIENT_ID"],
        "response_type": "code",
        "redirect_uri": APP + callback,
        "code_challenge_method": "S256",
        "code_challenge": "a" * 43,
        "state": "fresh-state",
        "nonce": "fresh-nonce",
        "scope": "openid email profile",
    }
    params.update(overrides or {})
    return Mock(side_effect=[
        response({"url": provider, "clientId": SETTINGS["SUPABASE_OIDC_CLIENT_ID"]}),
        login or response(status=302, location="/apps/user_oidc/login/1"),
        response(status=303, location=ORIGIN + "/auth/v1/oauth/authorize?" + urlencode(params)),
        authorization or response(status=302, location=APP + "/index.php/apps/stratus2_ai/auth?authorization_id=request-id"),
    ])


@pytest.mark.parametrize("callback", CALLBACK_PATHS)
def test_live_check_exercises_nextclouds_generated_callback_with_session_and_no_automatic_redirects(callback):
    get = live_responses(callback=callback)
    validate_live_oidc(get=get)
    assert len(get.call_args_list) == 4
    assert get.call_args_list[1].args[0] == APP + "/index.php/login"
    assert get.call_args_list[2].args[0] == APP + "/apps/user_oidc/login/1"
    assert get.call_args.args[0].startswith(ORIGIN + "/auth/v1/oauth/authorize?")
    assert all(call.kwargs["allow_redirects"] is False for call in get.call_args_list)


def test_live_check_fails_if_the_actual_browser_callback_is_rejected():
    get = live_responses(authorization=response({"msg": "invalid redirect_uri"}, status=400))
    with pytest.raises(ValueError, match="register the Nextcloud client/callback"):
        validate_live_oidc(get=get)


@pytest.mark.parametrize("location", ["https://untrusted.example/login", APP + "/apps/files/", "//untrusted.example/login"])
def test_live_check_does_not_follow_unexpected_redirects(location):
    get = live_responses(login=response(status=302, location=location))
    with pytest.raises(ValueError, match="outside the expected sign-in routes"):
        validate_live_oidc(get=get)
    assert get.call_count == 2


@pytest.mark.parametrize("overrides", [
    {"redirect_uri": "https://untrusted.example/code"},
    {"client_id": "different-client"},
    {"response_type": "token"},
    {"code_challenge_method": "plain"},
    {"code_challenge": "short"},
    {"state": ""},
    {"nonce": ""},
    {"scope": "email profile"},
])
def test_live_check_rejects_invalid_client_callback_or_pkce_without_contacting_provider(overrides):
    get = live_responses(overrides=overrides)
    with pytest.raises(ValueError, match="unexpected client, callback or PKCE"):
        validate_live_oidc(get=get)
    assert get.call_count == 3


@pytest.mark.parametrize("provider", ["http://example.supabase.co", "https://untrusted.example", ORIGIN + "@untrusted.example", ORIGIN + "/wrong-path"])
def test_live_check_rejects_unexpected_public_provider_configuration(provider):
    get = live_responses(provider=provider)
    with pytest.raises(ValueError, match="hosted Supabase OAuth client"):
        validate_live_oidc(get=get)
    assert get.call_count == 1


def test_live_check_fails_if_nextcloud_serves_local_login_instead_of_oidc():
    get = live_responses(login=response(status=200))
    with pytest.raises(ValueError, match="did not start the Supabase sign-in flow"):
        validate_live_oidc(get=get)


def test_token_probe_uses_nextclouds_post_method_and_cannot_exchange_a_real_user_code():
    settings = {**SETTINGS, "SUPABASE_OIDC_CLIENT_SECRET": "isolated-test-secret"}
    post = Mock(return_value=response({"error": "invalid_grant", "error_description": "Invalid authorization code"}, status=400))
    validate_token_authentication(settings, post=post)
    request = post.call_args
    assert request.args[0] == ORIGIN + "/auth/v1/oauth/token"
    assert request.kwargs["allow_redirects"] is False
    assert "auth" not in request.kwargs
    assert request.kwargs["data"]["client_secret"] == settings["SUPABASE_OIDC_CLIENT_SECRET"]
    assert request.kwargs["data"]["code"].startswith("stratus2-preflight-invalid-")
    assert request.kwargs["data"]["redirect_uri"] == APP + "/apps/user_oidc/code"
    validate_token_authentication(settings, post=post)
    assert request.kwargs["data"]["code"] != post.call_args.kwargs["data"]["code"]


@pytest.mark.parametrize("result", [
    response({"error_code": "invalid_credentials", "msg": "invalid authentication method"}, status=400),
    response({"error": "invalid_client"}, status=401),
    response({"error": "invalid_grant"}, status=500),
    response({"access_token": "must-never-be-printed"}, status=200),
    response(status=302, location="https://untrusted.example/"),
])
def test_token_probe_rejects_wrong_authentication_and_unexpected_responses_without_exposing_secrets(result):
    settings = {**SETTINGS, "SUPABASE_OIDC_CLIENT_SECRET": "isolated-test-secret"}
    with pytest.raises(ValueError, match="Request body") as error:
        validate_token_authentication(settings, post=Mock(return_value=result))
    assert settings["SUPABASE_OIDC_CLIENT_SECRET"] not in str(error.value)
    assert "must-never-be-printed" not in str(error.value)


def test_live_logout_follows_the_query_free_adapter_and_exact_logout_mode():
    adapter = APP + "/index.php/apps/stratus2_ai/auth/logout?post_logout_redirect_uri=" + APP + "/&client_id=fixture"
    get = Mock(side_effect=[response(status=303, location=adapter), response(status=302, location="/apps/stratus2_ai/auth?mode=logout")])
    validate_live_logout(get=get)
    assert get.call_args_list[0].args[0] == APP + "/index.php/apps/user_oidc/sls"
    assert get.call_args_list[1].args[0] == adapter
    assert all(call.kwargs["allow_redirects"] is False for call in get.call_args_list)


@pytest.mark.parametrize("location", [
    APP + "/index.php/apps/stratus2_ai/auth?mode=logout?post_logout_redirect_uri=" + APP,
    "https://untrusted.example/auth/logout",
    APP + "/login",
])
def test_live_logout_rejects_the_original_double_question_mark_and_unexpected_endpoints(location):
    get = Mock(return_value=response(status=303, location=location))
    with pytest.raises(ValueError, match="query-free"):
        validate_live_logout(get=get)
    assert get.call_count == 1


@pytest.mark.parametrize("location", [
    APP + "/apps/stratus2_ai/auth?mode=logout?post_logout_redirect_uri=" + APP,
    APP + "/apps/stratus2_ai/auth?mode=logout&authorization_id=unexpected",
    "https://untrusted.example/auth?mode=logout",
])
def test_live_logout_adapter_cannot_forward_a_return_url_or_corrupt_the_mode(location):
    get = Mock(side_effect=[response(status=303, location=APP + "/apps/stratus2_ai/auth/logout"), response(status=302, location=location)])
    with pytest.raises(ValueError, match="exact logout mode"):
        validate_live_logout(get=get)
