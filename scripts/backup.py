"""Consistent metadata/configuration snapshot with Nextcloud writes paused."""

import datetime
import fcntl
import hashlib
import json
import pathlib
import subprocess
import sys
import tarfile
import tempfile

from aws_support import client


def command(args, destination=None):
    with destination.open("wb") if destination else tempfile.TemporaryFile() as out:
        result = subprocess.run(args, stdout=out, stderr=subprocess.DEVNULL)
    if result.returncode:
        raise RuntimeError("Backup operation failed")


def occ(*args):
    command(
        [
            "k3s",
            "kubectl",
            "-n",
            "stratus2",
            "exec",
            active_deployment(),
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
            *args,
        ]
    )


def active_deployment():
    state = pathlib.Path("/etc/stratus2-release-state.json")
    if state.exists():
        active = json.loads(state.read_text()).get("active")
        if active and active["slot"] in ("blue", "green"):
            return "deployment/nextcloud-" + active["slot"]
    return "deployment/nextcloud"


def main():
    try:
        lock = pathlib.Path("/run/stratus2-release.lock").open("w")
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        settings = json.loads(pathlib.Path("/etc/stratus2-deploy.json").read_text())
        timestamp = datetime.datetime.now(datetime.UTC).strftime("%Y%m%dT%H%M%SZ")
        with tempfile.TemporaryDirectory() as work:
            work = pathlib.Path(work)
            occ("maintenance:mode", "--on")
            try:
                command(
                    [
                        "k3s",
                        "kubectl",
                        "-n",
                        "stratus2",
                        "exec",
                        "statefulset/postgres",
                        "--",
                        "pg_dump",
                        "-U",
                        "nextcloud",
                        "-d",
                        "nextcloud",
                        "-Fc",
                    ],
                    work / "postgres.dump",
                )
                command(
                    [
                        "k3s",
                        "kubectl",
                        "-n",
                        "stratus2",
                        "exec",
                        active_deployment(),
                        "-c",
                        "nextcloud",
                        "--",
                        "tar",
                        "czf",
                        "-",
                        "-C",
                        "/var/www/html",
                        "config",
                    ],
                    work / "config.tar.gz",
                )
                command(
                    [
                        "k3s",
                        "kubectl",
                        "-n",
                        "stratus2",
                        "get",
                        "deployment,statefulset,service,pvc,configmap,networkpolicy",
                        "-o",
                        "yaml",
                    ],
                    work / "kubernetes.yaml",
                )
                (work / "manifest.json").write_text(
                    json.dumps(
                        {
                            "timestamp": timestamp,
                            "image": settings["image"],
                            "sha256": {
                                name: hashlib.sha256((work / name).read_bytes()).hexdigest()
                                for name in ("postgres.dump", "config.tar.gz", "kubernetes.yaml")
                            },
                        }
                    )
                )
            finally:
                occ("maintenance:mode", "--off")
            archive = work / "backup.tar.gz"
            with tarfile.open(archive, "w:gz") as package:
                for name in ("postgres.dump", "config.tar.gz", "kubernetes.yaml", "manifest.json"):
                    package.add(work / name, arcname=name)
            client("s3").upload_file(
                str(archive),
                settings["backup_bucket"],
                "backups/" + timestamp + ".tar.gz",
                ExtraArgs={"ServerSideEncryption": "AES256"},
            )
        client("cloudwatch").put_metric_data(
            Namespace="Stratus2", MetricData=[{"MetricName": "BackupSuccess", "Value": 1}]
        )
        print("Encrypted metadata/configuration backup completed")
        return 0
    except Exception as exc:
        print("Backup failed: " + type(exc).__name__, file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
