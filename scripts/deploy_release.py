"""Upload a reviewed release bundle and deploy via SSM, without opening SSH or Kubernetes."""

import argparse
import io
import json
import pathlib
import re
import subprocess
import sys
import tarfile
import time

from aws_support import client


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--image", required=True)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--direct-downloads", choices=("keep", "enabled", "disabled"), default="keep")
    args = parser.parse_args()
    if not re.fullmatch(r"[a-f0-9]{40}", args.commit):
        parser.error("Expected a full commit SHA")
    outputs = json.loads(subprocess.check_output(["terraform", "-chdir=infra/runtime", "output", "-json"]))

    def value(name):
        return outputs[name]["value"]

    settings = {
        "image": args.image,
        "app_secret_arn": value("app_secret_arn"),
        "queue_url": value("queue_url"),
        "backup_bucket": value("backup_bucket"),
        "region": __import__("os").environ.get("AWS_REGION", "us-west-2"),
    }
    if args.direct_downloads != "keep":
        settings["direct_downloads"] = args.direct_downloads == "enabled"
    path = pathlib.Path("artifacts/release.tar.gz")
    path.parent.mkdir(exist_ok=True)
    with tarfile.open(path, "w:gz") as package:
        files = subprocess.check_output(["git", "ls-files", "scripts", "deploy"]).decode().splitlines()
        for name in files:
            if pathlib.Path(name).is_file():
                package.add(name, arcname=name)
        config = json.dumps(settings).encode()
        entry = tarfile.TarInfo("deploy-settings.json")
        entry.size = len(config)
        entry.mode = 0o600
        package.addfile(entry, io.BytesIO(config))
    key = "releases/" + args.commit + ".tar.gz"
    client("s3").upload_file(
        str(path), value("backup_bucket"), key, ExtraArgs={"ServerSideEncryption": "AES256"}
    )
    # SSM receives only bucket/key and paths, never a credential.
    commands = [
        "set -eu",
        "umask 077",
        "cloud-init status --wait >/dev/null",
        "mkdir -p /opt/stratus2",
        "/opt/stratus2-venv/bin/python -c \"import boto3;boto3.client('s3').download_file('"
        + value("backup_bucket")
        + "','"
        + key
        + "','/tmp/stratus2-release.tar.gz')\"",
        "tar -xzf /tmp/stratus2-release.tar.gz -C /opt/stratus2 --no-same-owner",
        "/opt/stratus2-venv/bin/python /opt/stratus2/scripts/host_deploy.py --settings /opt/stratus2/deploy-settings.json",
    ]
    ssm = client("ssm")
    instance = value("instance_id")
    for _ in range(120):
        info = ssm.describe_instance_information(Filters=[{"Key": "InstanceIds", "Values": [instance]}])
        if any(item["PingStatus"] == "Online" for item in info["InstanceInformationList"]):
            break
        time.sleep(10)
    else:
        raise RuntimeError("EC2 has not connected to Systems Manager")
    command_id = ssm.send_command(
        InstanceIds=[instance],
        DocumentName="AWS-RunShellScript",
        Parameters={"commands": commands, "executionTimeout": ["2400"]},
    )["Command"]["CommandId"]
    for _ in range(250):
        try:
            result = ssm.get_command_invocation(CommandId=command_id, InstanceId=instance)
        except ssm.exceptions.InvocationDoesNotExist:
            time.sleep(2)
            continue
        if result["Status"] == "Success":
            print("Application deployed successfully through Systems Manager")
            return 0
        if result["Status"] in ("Failed", "Cancelled", "TimedOut", "Cancelling"):
            raise RuntimeError("Node rollout failed; review restricted SSM command output")
        time.sleep(10)
    raise RuntimeError("Node rollout exceeded its deadline")


if __name__ == "__main__":
    sys.exit(main())
