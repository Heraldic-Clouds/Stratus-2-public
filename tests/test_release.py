import base64
import copy
import importlib
import json
import pathlib
import sys
from unittest.mock import MagicMock, Mock

import pytest


@pytest.fixture
def modules(monkeypatch):
    monkeypatch.syspath_prepend(str(pathlib.Path(__file__).resolve().parents[1] / "scripts"))
    return importlib.import_module("release"), importlib.import_module("host_release")


def manifest():
    registry = "123456789012.dkr.ecr.us-west-2.amazonaws.com/stratus2-staging-"
    return {
        "commit": "a" * 40,
        "nextcloud": registry + "nextcloud@sha256:" + "1" * 64,
        "worker": registry + "worker@sha256:" + "2" * 64,
        "protocol": 1,
        "nextcloud_version": "35.0.2.2",
        "job_schema": 1,
        "billing_schema": 2,
    }


def test_production_rejects_staging_repositories_and_mutable_tags(modules):
    release, _ = modules
    with pytest.raises(ValueError, match="repository"):
        release.validate_manifest(manifest(), staging=False)
    bad = manifest()
    bad["worker"] = bad["worker"].split("@")[0] + ":latest"
    with pytest.raises(ValueError, match="digest"):
        release.validate_manifest(bad, staging=True)


def test_incompatible_database_or_billing_protocol_is_rejected(modules):
    release, _ = modules
    bad = manifest()
    bad["billing_schema"] = 1
    with pytest.raises(ValueError, match="schema"):
        release.validate_manifest(bad, staging=True)


def test_staging_build_reuses_tag_and_builds_missing_image_from_committed_source(modules, monkeypatch):
    release, _ = modules
    expected = manifest()
    ecr = Mock()
    ecr.meta.region_name = "us-west-2"
    ecr.exceptions.ImageNotFoundException = type("ImageNotFound", (Exception,), {})
    ecr.describe_images.side_effect = [
        {"imageDetails": [{"imageDigest": "sha256:" + "1" * 64}]},
        ecr.exceptions.ImageNotFoundException(),
        {"imageDetails": [{"imageDigest": "sha256:" + "2" * 64}]},
    ]
    registry = "123456789012.dkr.ecr.us-west-2.amazonaws.com"
    ecr.get_authorization_token.return_value = {
        "authorizationData": [
            {"proxyEndpoint": f"https://{registry}", "authorizationToken": base64.b64encode(b"AWS:fixture")}
        ]
    }
    sts = Mock()
    sts.get_caller_identity.return_value = {"Account": "123456789012"}
    monkeypatch.setattr(release, "client", {"sts": sts, "ecr": ecr}.__getitem__)
    monkeypatch.setattr(release.subprocess, "check_output", Mock(side_effect=[expected["commit"] + "\n", ""]))
    archive = MagicMock()
    archive.__enter__.return_value = archive
    archive.wait.return_value = 0
    start_archive = Mock(return_value=archive)
    docker = Mock()
    monkeypatch.setattr(release.subprocess, "Popen", start_archive)
    monkeypatch.setattr(release.subprocess, "run", docker)

    assert release.build_staging_manifest(expected["commit"]) == expected
    start_archive.assert_called_once_with(
        ["git", "archive", expected["commit"]], stdout=release.subprocess.PIPE
    )
    login, build = docker.call_args_list
    assert login.args[0] == ["docker", "login", "--username", "AWS", "--password-stdin", registry]
    assert login.kwargs["input"] == b"fixture"
    assert build.kwargs["stdin"] is archive.stdout
    assert build.args[0][-2:] == [f"{registry}/stratus2-staging-worker:{expected['commit']}", "-"]


def test_web_slots_keep_application_code_isolated_and_do_not_run_cron(modules):
    _, host = modules
    document = host.deployment(manifest()["nextcloud"], "green", "a" * 40, configuration_revision="c" * 32)
    spec = document["spec"]["template"]["spec"]
    assert len(spec["containers"]) == 1
    mounts = spec["containers"][0]["volumeMounts"]
    assert {"name": "code", "mountPath": "/var/www/html"} in mounts
    assert {m.get("subPath") for m in mounts if m["name"] == "html"} == {
        "config",
        "data",
        "stratus2-assets/js",
        "stratus2-assets/css",
    }
    assert spec["containers"][0]["envFrom"][0]["secretRef"]["name"].endswith("-cccccccc")
    assert any(v.get("emptyDir") == {} for v in spec["volumes"])


def test_cron_selector_is_stable_across_slot_changes(modules):
    _, host = modules
    blue = host.deployment(manifest()["nextcloud"], "blue", "a" * 40, cron=True)
    green = host.deployment(manifest()["nextcloud"], "green", "b" * 40, cron=True)
    assert blue["spec"]["selector"] == green["spec"]["selector"]
    assert blue["spec"]["template"]["spec"]["containers"][0]["command"][-1] == "cron"


def test_rollback_refuses_incompatible_configuration_before_mutating_traffic(modules, monkeypatch):
    _, host = modules
    active = {**manifest(), "slot": "green", "configuration_revision": "new"}
    previous = {**manifest(), "slot": "blue", "configuration_revision": "old"}
    apply = Mock()
    monkeypatch.setattr(host, "apply", apply)
    with pytest.raises(ValueError, match="configuration"):
        host.activate({"active": active, "previous": previous}, rollback=True)
    apply.assert_not_called()


def test_unhealthy_candidate_does_not_switch_traffic(modules, monkeypatch):
    _, host = modules
    active = {**manifest(), "slot": "blue", "configuration_revision": "same"}
    candidate = {**manifest(), "slot": "green", "configuration_revision": "same"}
    mutations = []
    monkeypatch.setattr(host, "apply", lambda doc: mutations.append(doc))
    monkeypatch.setattr(host, "wait", Mock(side_effect=RuntimeError("Unhealthy candidate")))
    switch = Mock()
    monkeypatch.setattr(host, "kubectl", switch)
    state = {"active": active, "candidate": candidate}
    before = copy.deepcopy(state)
    with pytest.raises(RuntimeError):
        host.activate(state)
    switch.assert_not_called()
    assert state == before and all(doc["kind"] != "Service" for doc in mutations)


def test_production_command_requires_explicit_confirmation_before_network_access(modules, monkeypatch):
    release, _ = modules
    monkeypatch.setattr(sys, "argv", ["release.py", "deploy", "--environment", "production"])
    aws = Mock()
    monkeypatch.setattr(release, "outputs", aws)
    with pytest.raises(SystemExit):
        release.main()
    aws.assert_not_called()


def test_bundle_contains_no_local_secret_files(modules):
    import io
    import tarfile

    release, _ = modules
    with tarfile.open(fileobj=io.BytesIO(release.package(manifest(), {"environment": "staging"}))) as archive:
        names = archive.getnames()
        assert "release-manifest.json" in names
        assert not any(".env" in n or "secrets.local" in n or ".git/" in n for n in names)
        assert json.load(archive.extractfile("release-manifest.json")) == manifest()


def test_failed_dispatcher_start_restores_previous_traffic_and_journal(modules, monkeypatch):
    _, host = modules
    active = {**manifest(), "slot": "blue", "configuration_revision": "same"}
    candidate = {**manifest(), "slot": "green", "configuration_revision": "same"}
    state = {"active": active, "candidate": candidate}
    before = copy.deepcopy(state)
    service = {
        "kind": "Service",
        "spec": {"selector": {"app": "nextcloud-slot", "slot": "blue", "role": "web"}},
    }
    monkeypatch.setattr(host, "kubectl", Mock(return_value=json.dumps(service)))
    monkeypatch.setattr(host, "smoke", Mock())
    monkeypatch.setattr(host, "wait", Mock(side_effect=[None, RuntimeError("dispatcher failed"), None]))
    mutations = []
    journal = []
    monkeypatch.setattr(host, "apply", lambda document: mutations.append(copy.deepcopy(document)))
    monkeypatch.setattr(host, "save_state", lambda value: journal.append(copy.deepcopy(value)))
    with pytest.raises(RuntimeError, match="dispatcher failed"):
        host.activate(state)
    services = [doc for doc in mutations if doc["kind"] == "Service"]
    assert services[0]["spec"]["selector"]["slot"] == "green"
    assert services[-1]["spec"]["selector"]["slot"] == "blue"
    assert journal[-1] == before
