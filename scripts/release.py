"""Immutable release deployment through SSM, with explicit production promotion/rollback."""

import argparse
import base64
import io
import json
import os
import pathlib
import re
import shlex
import subprocess
import tarfile
import time

from aws_support import client


def validate_manifest(manifest, *, staging):
    if set(manifest) != {
        "commit",
        "nextcloud",
        "worker",
        "protocol",
        "nextcloud_version",
        "job_schema",
        "billing_schema",
    }:
        raise ValueError("Invalid release manifest fields")
    if not re.fullmatch(r"[a-f0-9]{40}", manifest["commit"]):
        raise ValueError("A full commit SHA is required")
    prefix = "stratus2-staging-" if staging else "stratus2-"
    for component in ("nextcloud", "worker"):
        pattern = (
            r"[0-9]{12}\.dkr\.ecr\.[a-z0-9-]+\.amazonaws\.com/" + prefix + component + r"@sha256:[a-f0-9]{64}"
        )
        if not re.fullmatch(pattern, manifest[component]):
            raise ValueError("Image digest/repository does not match the target environment")
    if [manifest[k] for k in ("protocol", "nextcloud_version", "job_schema", "billing_schema")] != [
        1,
        "35.0.2.2",
        1,
        2,
    ]:
        raise ValueError("Incompatible core, job or billing schema")
    return manifest


def release_manifest(commit, nextcloud, worker, *, staging):
    return validate_manifest(
        {
            "commit": commit,
            "nextcloud": nextcloud,
            "worker": worker,
            "protocol": 1,
            "nextcloud_version": "35.0.2.2",
            "job_schema": 1,
            "billing_schema": 2,
        },
        staging=staging,
    )


def write_manifest(path, manifest):
    destination = pathlib.Path(path)
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")


def build_staging_manifest(expected_commit):
    """Build or reuse the immutable images for one clean checkout."""
    commit = subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip()
    if expected_commit and commit != expected_commit:
        raise ValueError("Check out the requested release commit before building")
    if subprocess.check_output(["git", "status", "--porcelain"], text=True).strip():
        raise ValueError("Commit the release changes before building")

    account = client("sts").get_caller_identity()["Account"]
    ecr = client("ecr")
    registry = f"{account}.dkr.ecr.{ecr.meta.region_name}.amazonaws.com"
    images = {}
    authenticated = False
    for component in ("nextcloud", "worker"):
        repository = f"stratus2-staging-{component}"
        image_id = [{"imageTag": commit}]
        try:
            details = ecr.describe_images(repositoryName=repository, imageIds=image_id)
        except ecr.exceptions.ImageNotFoundException:
            if not authenticated:
                auth = ecr.get_authorization_token()["authorizationData"][0]
                if auth["proxyEndpoint"] != f"https://{registry}":
                    raise RuntimeError("ECR login endpoint differs from the staging registry") from None
                username, separator, password = base64.b64decode(auth["authorizationToken"]).partition(b":")
                if username != b"AWS" or not separator or not password:
                    raise RuntimeError("Unexpected ECR login response") from None
                subprocess.run(
                    ["docker", "login", "--username", "AWS", "--password-stdin", registry],
                    input=password,
                    check=True,
                )
                authenticated = True
            # Archive only committed source. Ignored settings, local credentials and
            # build artifacts are outside the Docker context.
            with subprocess.Popen(["git", "archive", commit], stdout=subprocess.PIPE) as archive:
                subprocess.run(
                    [
                        "docker",
                        "buildx",
                        "build",
                        "--platform",
                        "linux/amd64",
                        "--provenance=false",
                        "--sbom=false",
                        "--push",
                        "--file",
                        f"{component}/Dockerfile",
                        "--tag",
                        f"{registry}/{repository}:{commit}",
                        "-",
                    ],
                    stdin=archive.stdout,
                    check=True,
                )
                if archive.wait() != 0:
                    raise RuntimeError("Git could not archive the release commit") from None
            details = ecr.describe_images(repositoryName=repository, imageIds=image_id)
        digest = details["imageDetails"][0]["imageDigest"]
        images[component] = f"{registry}/{repository}@{digest}"
    return release_manifest(commit, images["nextcloud"], images["worker"], staging=True)


def outputs(environment):
    directory = "infra/staging" if environment == "staging" else "infra/runtime"
    result = json.loads(subprocess.check_output(["terraform", "-chdir=" + directory, "output", "-json"]))
    return {key: value["value"] for key, value in result.items()}


def ssm(instance, commands):
    service = client("ssm")
    command_id = service.send_command(
        InstanceIds=[instance],
        DocumentName="AWS-RunShellScript",
        Parameters={"commands": ["set -eu", "umask 077", *commands], "executionTimeout": ["2400"]},
    )["Command"]["CommandId"]
    for _ in range(250):
        try:
            result = service.get_command_invocation(CommandId=command_id, InstanceId=instance)
        except service.exceptions.InvocationDoesNotExist:
            time.sleep(2)
            continue
        if result["Status"] == "Success":
            return result.get("StandardOutputContent", "")
        if result["Status"] in ("Failed", "TimedOut", "Cancelled", "Cancelling"):
            raise RuntimeError(
                "Release operation failed; inspect restricted SSM logs. Production was not assumed healthy."
            )
        time.sleep(5)
    raise RuntimeError("Release operation exceeded its deadline")


def package(manifest, settings):
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as archive:
        tracked = subprocess.check_output(["git", "ls-files", "-z", "scripts", "deploy"]).decode().split("\0")
        for name in filter(None, tracked):
            path = pathlib.Path(name)
            if path.is_file() and not path.is_symlink() and path.suffix in (".py", ".yaml", ".json"):
                archive.add(path, arcname=path.as_posix())
        for name, value in [("release-manifest.json", manifest), ("deploy-settings.json", settings)]:
            data = json.dumps(value).encode()
            member = tarfile.TarInfo(name)
            member.size, member.mode = len(data), 0o600
            archive.addfile(member, io.BytesIO(data))
    return buffer.getvalue()


def prepare_workers(functions, image):
    service = client("lambda")
    versions = {}
    for kind, function in functions.items():
        try:
            service.get_alias(FunctionName=function, Name="active")
        except service.exceptions.ResourceNotFoundException:
            prior = service.publish_version(FunctionName=function)["Version"]
            service.create_alias(FunctionName=function, Name="active", FunctionVersion=prior)
        # Migrate any legacy unqualified mapping before updating $LATEST. Work
        # remains on the old published alias until the web release is healthy.
        config = service.get_function_configuration(FunctionName=function)
        for mapping in service.list_event_source_mappings(FunctionName=function)["EventSourceMappings"]:
            if mapping["FunctionArn"] == config["FunctionArn"]:
                service.update_event_source_mapping(UUID=mapping["UUID"], FunctionName=function + ":active")
                for _ in range(60):
                    if service.get_event_source_mapping(UUID=mapping["UUID"])["State"] == "Enabled":
                        break
                    time.sleep(2)
                else:
                    raise RuntimeError("Queue mapping did not migrate to the versioned alias")
        result = service.update_function_code(FunctionName=function, ImageUri=image, Publish=True)
        service.get_waiter("function_updated_v2").wait(FunctionName=function)
        versions[kind] = result["Version"]
    return versions


def switch_workers(functions, versions):
    service = client("lambda")
    for kind, function in functions.items():
        service.update_alias(FunctionName=function, Name="active", FunctionVersion=versions[kind])


def settings_for(environment, values, manifest):
    origin = "https://staging.example.com" if environment == "staging" else "https://stratus.example.com"
    if not values.get("photo_queue_url") or set(values.get("worker_functions", {})) != {"analysis", "photo"}:
        raise ValueError("Provision the photo queue/functions before deploying this feature")
    return {
        "environment": environment,
        "origin": origin,
        "image": manifest["nextcloud"],
        "commit": manifest["commit"],
        "app_secret_arn": values["app_secret_arn"],
        "queue_url": values["queue_url"],
        "photo_queue_url": values["photo_queue_url"],
        "backup_bucket": values["backup_bucket"],
        "region": os.environ.get("AWS_REGION", "us-west-2"),
        "enhancement_enabled": True,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "operation", choices=["build", "manifest", "deploy", "bootstrap", "rollback", "start", "stop"]
    )
    parser.add_argument("--environment", choices=["staging", "production"], default="staging")
    parser.add_argument("--manifest", default="artifacts/release.json")
    parser.add_argument("--commit")
    parser.add_argument("--nextcloud")
    parser.add_argument("--worker")
    parser.add_argument("--confirm-production", action="store_true")
    args = parser.parse_args()
    staging = args.environment == "staging"
    if args.operation == "build":
        if not staging:
            parser.error("Build release images in staging")
        write_manifest(args.manifest, build_staging_manifest(args.commit))
        print(f"Staging image digests recorded in {args.manifest}.")
        return
    if not staging and not args.confirm_production:
        parser.error("Production requires --confirm-production; staging is the default")
    if args.operation == "manifest":
        write_manifest(
            args.manifest, release_manifest(args.commit, args.nextcloud, args.worker, staging=staging)
        )
        return
    values = outputs(args.environment)
    instance = values["instance_id"]
    if args.operation in ("start", "stop"):
        if not staging:
            raise ValueError("Power controls are available only for staging")
        getattr(client("ec2"), args.operation + "_instances")(InstanceIds=[instance])
        print("Staging power operation requested. Persistent disks remain billable.")
        return
    if args.operation == "rollback":
        state = json.loads(ssm(instance, ["cat /etc/stratus2-release-state.json"]))
        if not state.get("previous"):
            raise ValueError("There is no retained prior compatible release")
        ssm(instance, ["/opt/stratus2-venv/bin/python /opt/stratus2/scripts/host_release.py rollback"])
        switch_workers(values["worker_functions"], state["previous"]["worker_versions"])
        print("Rolled back application traffic and worker aliases; user data was retained.")
        return
    manifest = validate_manifest(json.loads(pathlib.Path(args.manifest).read_text()), staging=staging)
    # Ensure this bundle contains precisely the code that was built/tested.
    head = subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip()
    if (
        head != manifest["commit"]
        or subprocess.check_output(
            ["git", "status", "--porcelain", "--untracked-files=no"], text=True
        ).strip()
    ):
        raise ValueError("Deploy from a clean checkout of the exact release commit")
    settings = settings_for(args.environment, values, manifest)
    if args.operation != "bootstrap":
        manifest["worker_versions"] = prepare_workers(values["worker_functions"], manifest["worker"])
    key = "releases/" + manifest["commit"] + "/" + args.operation + "-bundle.tar.gz"
    client("s3").put_object(
        Bucket=values["backup_bucket"],
        Key=key,
        Body=package(manifest, settings),
        ServerSideEncryption="AES256",
    )
    download = (
        "import boto3;boto3.client('s3').download_file("
        + repr(values["backup_bucket"])
        + ","
        + repr(key)
        + ",'/tmp/stratus2-release.tar.gz')"
    )
    commands = [
        "mkdir -p /opt/stratus2",
        "/opt/stratus2-venv/bin/python -c " + shlex.quote(download),
        "tar -xzf /tmp/stratus2-release.tar.gz -C /opt/stratus2 --no-same-owner",
    ]
    if args.operation == "bootstrap":
        if not staging:
            commands.append("/opt/stratus2-venv/bin/python /opt/stratus2/scripts/backup.py")
        commands.append(
            "/opt/stratus2-venv/bin/python /opt/stratus2/scripts/host_deploy.py --settings /opt/stratus2/deploy-settings.json"
        )
    else:
        commands += [
            "/opt/stratus2-venv/bin/python /opt/stratus2/scripts/host_release.py prepare --manifest /opt/stratus2/release-manifest.json",
            "/opt/stratus2-venv/bin/python /opt/stratus2/scripts/host_release.py activate",
        ]
    ssm(instance, commands)
    if args.operation != "bootstrap":
        switch_workers(values["worker_functions"], manifest["worker_versions"])
    print(
        "Deployed "
        + manifest["commit"]
        + " to "
        + args.environment
        + ". Production was not modified by a staging operation."
    )


if __name__ == "__main__":
    main()
