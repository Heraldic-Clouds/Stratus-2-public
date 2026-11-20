"""Prepare/switch compatible Nextcloud application slots; never run database migrations."""

import argparse
import copy
import json
import pathlib

import yaml

from host_deploy import apply, kubectl, refresh_ecr, secret
from aws_support import client
from preflight import validate

STATE = pathlib.Path("/etc/stratus2-release-state.json")


def save_state(state):
    temporary = STATE.with_suffix(".tmp")
    temporary.write_text(json.dumps(state))
    temporary.chmod(0o600)
    temporary.replace(STATE)


def deployment(image, slot, commit, *, cron=False, configuration_revision="baseline"):
    original = next(
        d
        for d in yaml.safe_load_all(
            (pathlib.Path(__file__).resolve().parents[1] / "deploy/kubernetes.yaml").read_text()
        )
        if d.get("kind") == "Deployment" and d["metadata"]["name"] == "nextcloud"
    )
    result = copy.deepcopy(original)
    name = "nextcloud-cron" if cron else "nextcloud-" + slot
    labels = {"app": "nextcloud-slot", "slot": "active" if cron else slot, "role": "cron" if cron else "web"}
    result["metadata"]["name"] = name
    result["spec"]["selector"]["matchLabels"] = labels
    pod = result["spec"]["template"]
    pod["metadata"] = {
        "labels": labels,
        "annotations": {"stratus2/release": commit, "stratus2/release-protocol": "1"},
    }
    container = pod["spec"]["containers"][0]
    container["image"] = image
    container["env"] = [
        {"name": "STRATUS2_SLOT_MODE", "value": "1"},
        {"name": "STRATUS2_RELEASE", "value": commit},
    ]
    container["volumeMounts"] = [
        {"name": "code", "mountPath": "/var/www/html"},
        {"name": "html", "mountPath": "/var/www/html/config", "subPath": "config"},
        {"name": "html", "mountPath": "/var/www/html/data", "subPath": "data"},
        {
            "name": "html",
            "mountPath": "/var/www/html/custom_apps/stratus2_ai/js",
            "subPath": "stratus2-assets/js",
        },
        {
            "name": "html",
            "mountPath": "/var/www/html/custom_apps/stratus2_ai/css",
            "subPath": "stratus2-assets/css",
        },
    ]
    # Snapshot config/secrets per release: preparing green cannot reconfigure blue.
    for ref in container["envFrom"]:
        if "secretRef" in ref:
            ref["secretRef"]["name"] = "nextcloud-" + commit[:12] + "-" + configuration_revision[:8]
        if "configMapRef" in ref:
            ref["configMapRef"]["name"] = "nextcloud-" + commit[:12] + "-" + configuration_revision[:8]
    if cron:
        container["command"] = ["/usr/local/bin/stratus2-entrypoint", "cron"]
        for probe in ("startupProbe", "readinessProbe", "livenessProbe"):
            container.pop(probe, None)
        container["resources"] = {
            "requests": {"cpu": "100m", "memory": "256Mi"},
            "limits": {"cpu": "1", "memory": "1Gi"},
        }
    pod["spec"]["containers"] = [container]
    pod["spec"]["volumes"].append({"name": "code", "emptyDir": {}})
    return result


def wait(name):
    kubectl(["-n", "stratus2", "rollout", "status", "deployment/" + name, "--timeout=900s"])


def smoke(slot):
    # status.php alone cannot detect an unregistered app or broken DI/routes.
    command = """php -r '$c=stream_context_create(["http"=>["header"=>"Host: ".getenv("STRATUS2_HEALTH_HOST")]]);$s=json_decode(file_get_contents("http://127.0.0.1/status.php",false,$c),true);if (!$s["installed"] || $s["maintenance"] || $s["needsDbUpgrade"]) exit(1);' """
    kubectl(["-n", "stratus2", "exec", "deployment/nextcloud-" + slot, "--", "sh", "-ec", command])
    kubectl(
        [
            "-n",
            "stratus2",
            "exec",
            "deployment/nextcloud-" + slot,
            "--",
            "su",
            "-p",
            "www-data",
            "-s",
            "/bin/sh",
            "-c",
            'php /var/www/html/occ app:list --output=json | php -r \'$a=json_decode(stream_get_contents(STDIN),true);if (!isset($a["enabled"]["stratus2_ai"])) exit(1);\'',
        ]
    )

    billing_command = r"""php -r '
        chdir("/var/www/html");
        require "lib/base.php";
        $app = new \OCA\Stratus2AI\AppInfo\Application();
        $container = $app->getContainer();
        $stripe = $container->get(\OCA\Stratus2AI\Service\StripeGateway::class);
        if ($stripe::paymentsEnabled()) {
            $container->get(\OCA\Stratus2AI\Service\BillingAccount::class)->validatePrices();
        } else {
            $price = $stripe->request("GET", "prices/".getenv("STRIPE_LEGACY_PRICE_ID"));
            if (empty($price["livemode"]) || empty($price["active"])) exit(1);
        }
    '"""
    kubectl(
        [
            "-n",
            "stratus2",
            "exec",
            "deployment/nextcloud-" + slot,
            "--",
            "su",
            "-p",
            "www-data",
            "-s",
            "/bin/sh",
            "-c",
            billing_command,
        ]
    )


def prepare(manifest, settings):
    state = json.loads(STATE.read_text()) if STATE.exists() else {"active": None, "previous": None}
    slot = "green" if state["active"] and state["active"]["slot"] == "blue" else "blue"
    if state["active"] is None:
        legacy = json.loads(kubectl(["-n", "stratus2", "get", "deployment/nextcloud", "-o", "json"]))
        if (
            legacy["spec"]["template"]["metadata"].get("annotations", {}).get("stratus2/release-protocol")
            != "1"
        ):
            raise ValueError("Bootstrap the compatible release baseline once before enabling slots")
    # Both new slots use isolated code while only shared config/data persist.
    version = client("secretsmanager").get_secret_value(SecretId=settings["app_secret_arn"])
    app = validate(json.loads(version["SecretString"]), environment=settings["environment"])
    manifest = {**manifest, "configuration_revision": version["VersionId"]}
    snapshot = "nextcloud-" + manifest["commit"][:12] + "-" + version["VersionId"][:8]
    for kind in ("secret", "configmap"):
        if kind == "secret":
            secret(snapshot, {**app, "REDIS_HOST_PASSWORD": app["REDIS_PASSWORD"]})
            continue
        document = json.loads(kubectl(["-n", "stratus2", "get", kind, "nextcloud", "-o", "json"]))
        document["metadata"] = {"name": snapshot, "namespace": "stratus2"}
        if kind == "configmap":
            document["data"].update(
                {
                    "STRATUS2_RELEASE": manifest["commit"],
                    "PHOTO_ENHANCEMENT_ENABLED": "1",
                    "PHOTO_QUEUE_URL": settings["photo_queue_url"],
                    "STRATUS2_LOCAL_AUTH": "1" if app.get("STRATUS2_LOCAL_AUTH") == "1" else "0",
                }
            )
        apply(document)
    # The original database ingress policy is retained for the baseline deployment.
    apply(
        {
            "apiVersion": "networking.k8s.io/v1",
            "kind": "NetworkPolicy",
            "metadata": {"name": "application-slots", "namespace": "stratus2"},
            "spec": {
                "podSelector": {
                    "matchExpressions": [{"key": "app", "operator": "In", "values": ["postgres", "redis"]}]
                },
                "policyTypes": ["Ingress"],
                "ingress": [
                    {
                        "from": [{"podSelector": {"matchLabels": {"app": "nextcloud-slot"}}}],
                        "ports": [{"protocol": "TCP", "port": 5432}, {"protocol": "TCP", "port": 6379}],
                    }
                ],
            },
        }
    )
    refresh_ecr()
    apply(
        deployment(
            manifest["nextcloud"],
            slot,
            manifest["commit"],
            configuration_revision=manifest["configuration_revision"],
        )
    )
    wait("nextcloud-" + slot)
    if settings["environment"] == "staging" and app.get("STRATUS2_LOCAL_AUTH") != "1":
        command = """set -eu
php /var/www/html/occ app:enable user_oidc
php /var/www/html/occ user_oidc:provider Supabase --clientid="$SUPABASE_OIDC_CLIENT_ID" \\
  --clientsecret-env=SUPABASE_OIDC_CLIENT_SECRET \\
  --discoveryuri="https://staging.example.com/index.php/apps/stratus2_ai/auth/oidc/discovery" \\
  --endsessionendpointuri="https://staging.example.com/index.php/apps/stratus2_ai/auth/logout" \\
  --mapping-uid=sub --mapping-email=email --mapping-display-name=email --unique-uid=1 --group-provisioning=0 \\
  --scope="openid email profile" >/dev/null
php /var/www/html/occ config:app:set user_oidc allow_multiple_user_backends --type=string --value=1
"""
        kubectl([
            "-n", "stratus2", "exec", "deployment/nextcloud-" + slot, "--",
            "su", "-p", "www-data", "-s", "/bin/sh", "-c", command,
        ])
    smoke(slot)
    state["candidate"] = {**manifest, "slot": slot}
    save_state(state)
    return state


def activate(state, *, rollback=False):
    candidate = state.get("previous") if rollback else state.get("candidate")
    if not candidate:
        raise ValueError("No retained compatible release exists")
    # No core/schema downgrades. Both versions must understand current jobs/billing.
    current = state.get("active")
    if current and any(
        current[key] != candidate[key]
        for key in ("protocol", "nextcloud_version", "job_schema", "billing_schema")
    ):
        raise ValueError("Release is not backward compatible; use the maintenance recovery procedure")
    if rollback and current and current["configuration_revision"] != candidate["configuration_revision"]:
        raise ValueError("Credentials/configuration changed; review compatibility before rolling back")
    apply(
        deployment(
            candidate["nextcloud"],
            candidate["slot"],
            candidate["commit"],
            configuration_revision=candidate["configuration_revision"],
        )
    )
    wait("nextcloud-" + candidate["slot"])
    smoke(candidate["slot"])
    service = json.loads(kubectl(["-n", "stratus2", "get", "service/nextcloud", "-o", "json"]))
    previous_service = copy.deepcopy(service)
    previous_state = copy.deepcopy(state)
    try:
        # One dispatcher follows the active release. Retained web slots drain
        # existing requests without running a second background dispatcher.
        if current:
            kubectl(["-n", "stratus2", "scale", "deployment/nextcloud-cron", "--replicas=0"])
        else:
            kubectl(["-n", "stratus2", "scale", "deployment/nextcloud", "--replicas=0"])
        service["spec"]["selector"] = {"app": "nextcloud-slot", "role": "web", "slot": candidate["slot"]}
        apply(service)
        state["previous"], state["active"] = current, candidate
        state.pop("candidate", None)
        save_state(state)
        apply(
            deployment(
                candidate["nextcloud"],
                candidate["slot"],
                candidate["commit"],
                cron=True,
                configuration_revision=candidate["configuration_revision"],
            )
        )
        wait("nextcloud-cron")
    except Exception:
        # Recover a failed cron/startup/switch while the previous application and
        # compatible data are still intact. Never restore a stale database.
        apply(previous_service)
        if current:
            apply(
                deployment(
                    current["nextcloud"],
                    current["slot"],
                    current["commit"],
                    cron=True,
                    configuration_revision=current["configuration_revision"],
                )
            )
            wait("nextcloud-cron")
        else:
            kubectl(["-n", "stratus2", "scale", "deployment/nextcloud", "--replicas=1"])
            wait("nextcloud")
        save_state(previous_state)
        raise
    return state


def main():
    import fcntl

    parser = argparse.ArgumentParser()
    parser.add_argument("operation", choices=["prepare", "activate", "rollback"])
    parser.add_argument("--manifest")
    parser.add_argument("--settings", default="/etc/stratus2-deploy.json")
    args = parser.parse_args()
    settings = json.loads(pathlib.Path(args.settings).read_text())
    __import__("os").environ["AWS_DEFAULT_REGION"] = settings["region"]
    with pathlib.Path("/run/stratus2-release.lock").open("w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if args.operation == "prepare":
            manifest = json.loads(pathlib.Path(args.manifest).read_text())
            prepare(manifest, settings)
        else:
            activate(json.loads(STATE.read_text()), rollback=args.operation == "rollback")
    print("Application slot operation completed; secrets were not displayed.")


if __name__ == "__main__":
    main()
