"""Run on the EC2 node through SSM. Never emit secrets or kubectl secret documents."""

import argparse
import base64
import json
import os
import pathlib
import re
import subprocess
import sys

import yaml

from aws_support import client
from preflight import validate


def kubectl(args, data=None):
    result = subprocess.run(
        ["/usr/local/bin/k3s", "kubectl", *args], input=data, text=True, capture_output=True
    )
    if result.returncode:
        raise RuntimeError("Kubernetes operation failed: " + args[0])
    return result.stdout


def apply(document):
    kubectl(["apply", "-f", "-"], json.dumps(document))


def secret(name, values, kind="Opaque"):
    apply(
        {
            "apiVersion": "v1",
            "kind": "Secret",
            "metadata": {"name": name, "namespace": "stratus2"},
            "type": kind,
            "data": {key: base64.b64encode(value.encode()).decode() for key, value in values.items()},
        }
    )


def refresh_ecr():
    authorization = client("ecr").get_authorization_token()["authorizationData"][0]
    endpoint = authorization["proxyEndpoint"].removeprefix("https://")
    secret(
        "ecr",
        {
            ".dockerconfigjson": json.dumps(
                {"auths": {endpoint: {"auth": authorization["authorizationToken"]}}}
            )
        },
        "kubernetes.io/dockerconfigjson",
    )


def configure_timers(settings):
    destination = pathlib.Path("/etc/stratus2-deploy.json")
    destination.write_text(json.dumps(settings))
    destination.chmod(0o600)
    for name, command, schedule in (
        (
            "ecr",
            "/opt/stratus2-venv/bin/python /opt/stratus2/scripts/host_deploy.py --refresh-ecr",
            "*-*-* 00,06,12,18:00:00",
        ),
        ("backup", "/opt/stratus2-venv/bin/python /opt/stratus2/scripts/backup.py", "*-*-* 09:00:00"),
    ):
        pathlib.Path(f"/etc/systemd/system/stratus2-{name}.service").write_text(
            "[Unit]\nAfter=k3s.service\n[Service]\nType=oneshot\nEnvironmentFile=/etc/stratus2.env\n"
            "UMask=0077\nExecStart=" + command + "\n"
        )
        pathlib.Path(f"/etc/systemd/system/stratus2-{name}.timer").write_text(
            "[Unit]\nDescription=Stratus-2 scheduled "
            + name
            + "\n[Timer]\nOnCalendar="
            + schedule
            + "\nPersistent=true\n[Install]\nWantedBy=timers.target\n"
        )
    subprocess.run(["systemctl", "daemon-reload"], check=True)
    subprocess.run(
        ["systemctl", "enable", "--now", "stratus2-ecr.timer", "stratus2-backup.timer"],
        check=True,
        capture_output=True,
    )


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--settings", default="/etc/stratus2-deploy.json")
    parser.add_argument("--refresh-ecr", action="store_true")
    args = parser.parse_args()
    try:
        settings = json.loads(pathlib.Path(args.settings).read_text())
        environment = settings.get("environment", "production")
        if environment not in ("staging", "production"):
            raise ValueError("Unknown deployment environment")
        os.environ["AWS_DEFAULT_REGION"] = settings["region"]
        if args.refresh_ecr:
            refresh_ecr()
            return 0
        import fcntl

        release_lock = pathlib.Path("/run/stratus2-release.lock").open("w")
        fcntl.flock(release_lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if not re.fullmatch(
            r"[0-9]+\.dkr\.ecr\.[a-z0-9-]+\.amazonaws\.com/stratus2-(?:staging-)?nextcloud@sha256:[a-f0-9]{64}",
            settings["image"],
        ):
            raise ValueError("A digest-pinned Nextcloud ECR image is required")
        if ("/stratus2-staging-" in settings["image"]) != (environment == "staging"):
            raise ValueError("Image repository does not match the environment")
        secret_version = client("secretsmanager").get_secret_value(SecretId=settings["app_secret_arn"])
        app = validate(json.loads(secret_version["SecretString"]), environment=environment)
        if pathlib.Path("/etc/stratus2-release-state.json").exists():
            raise ValueError("Use the blue/green release workflow after slot adoption")
        apply({"apiVersion": "v1", "kind": "Namespace", "metadata": {"name": "stratus2"}})
        refresh_ecr()
        secret("database", {"POSTGRES_PASSWORD": app["POSTGRES_PASSWORD"]})
        secret("redis", {"REDIS_PASSWORD": app["REDIS_PASSWORD"]})
        secret("nextcloud", {**app, "REDIS_HOST_PASSWORD": app["REDIS_PASSWORD"]})
        apply(
            {
                "apiVersion": "v1",
                "kind": "ConfigMap",
                "metadata": {"name": "nextcloud", "namespace": "stratus2"},
                "data": {
                    "AWS_REGION": settings["region"],
                    "AWS_DEFAULT_REGION": settings["region"],
                    "POSTGRES_HOST": "postgres",
                    "POSTGRES_DB": "nextcloud",
                    "POSTGRES_USER": "nextcloud",
                    "REDIS_HOST": "redis",
                    "SQS_QUEUE_URL": settings["queue_url"],
                    "STRATUS2_TEST_MODE": "0",
                    "STRATUS2_ENVIRONMENT": environment,
                    "STRATUS2_ORIGIN": settings.get("origin", "https://stratus.example.com"),
                    "STRATUS2_HEALTH_HOST": settings.get("origin", "https://stratus.example.com").removeprefix(
                        "https://"
                    ),
                    "STRATUS2_LOCAL_AUTH": "1"
                    if environment == "staging" and app.get("STRATUS2_LOCAL_AUTH") == "1"
                    else "0",
                    "PHOTO_QUEUE_URL": settings.get("photo_queue_url", ""),
                    "PHOTO_ENHANCEMENT_ENABLED": "1" if settings.get("enhancement_enabled", False) else "0",
                    "STRATUS2_RELEASE": settings.get("commit", "unknown"),
                    "FREE_QUOTA": app.get("FREE_QUOTA", "1 GB"),
                    "PAID_QUOTA": app.get("PAID_QUOTA", "100 GB"),
                },
            }
        )
        manifest = (
            pathlib.Path("/opt/stratus2/deploy/kubernetes.yaml")
            .read_text()
            .replace("NEXTCLOUD_IMAGE", settings["image"])
        )
        for document in yaml.safe_load_all(manifest):
            if environment == "staging" and document.get("kind") == "PersistentVolumeClaim":
                document["spec"]["resources"]["requests"]["storage"] = "10Gi"
            if document.get("kind") == "Deployment" and document["metadata"]["name"] == "nextcloud":
                annotations = document["spec"]["template"]["metadata"].setdefault("annotations", {})
                annotations["stratus2/credentials-version"] = secret_version["VersionId"]
                annotations["stratus2/release-protocol"] = "1"
            apply(document)
        for workload in ("statefulset/postgres", "deployment/redis", "deployment/nextcloud"):
            kubectl(["-n", "stratus2", "rollout", "status", workload, "--timeout=1200s"])
        if "direct_downloads" in settings:
            kubectl(
                [
                    "-n",
                    "stratus2",
                    "exec",
                    "deployment/nextcloud",
                    "-c",
                    "nextcloud",
                    "--",
                    "su",
                    "-p",
                    "www-data",
                    "-s",
                    "/bin/sh",
                    "-c",
                    'php /var/www/html/occ "$@"',
                    "--",
                    "sh",
                    "config:app:set",
                    "stratus_transfer",
                    "direct_downloads",
                    "--value=" + ("1" if settings["direct_downloads"] else "0"),
                ]
            )
        configure_timers(settings)
        print("Stratus-2 rollout completed. Runtime secrets were not printed.")
        return 0
    except Exception as exc:
        print("Node deployment failed: " + type(exc).__name__, file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
