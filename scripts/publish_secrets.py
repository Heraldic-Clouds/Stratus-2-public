"""Explicitly publish ignored local credential files to pre-created Secrets Manager entries."""

import argparse
import json
import pathlib
import sys

from aws_support import client
from preflight import validate


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--app-file", required=True)
    parser.add_argument("--openai-env", default=".env.local")
    parser.add_argument("--confirm-publish", action="store_true", required=True)
    args = parser.parse_args()
    try:
        app = validate(json.loads(pathlib.Path(args.app_file).read_text()))
        fields = {}
        for line in pathlib.Path(args.openai_env).read_text().splitlines():
            if line.strip() and not line.lstrip().startswith("#") and "=" in line:
                name, value = line.split("=", 1)
                fields[name.strip()] = value.strip().strip('"').strip("'")
        if not fields.get("OPENAI_API_KEY"):
            raise ValueError("OPENAI_API_KEY is missing")
        sm = client("secretsmanager")
        sm.put_secret_value(SecretId="stratus2/app", SecretString=json.dumps(app))
        sm.put_secret_value(
            SecretId="stratus2/openai", SecretString=json.dumps({"OPENAI_API_KEY": fields["OPENAI_API_KEY"]})
        )
        print("Updated stratus2/app and stratus2/openai. No credential values displayed.")
        return 0
    except Exception as exc:
        print("Secret publication failed: " + type(exc).__name__, file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
