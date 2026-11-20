"""Fail closed if a tracked/staged file contains a credential or a local secret file."""

import argparse
import pathlib
import re
import subprocess
import sys

PATTERNS = [
    rb"sk-(?:proj-|svcacct-)?[A-Za-z0-9_-]{24,}",
    rb"\b(?:AKIA|ASIA)[A-Z0-9]{16}\b",
    rb"\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{20,}\b",
    rb"\bwhsec_[A-Za-z0-9]{20,}\b",
    rb"\bsb_secret_[A-Za-z0-9_-]{20,}\b",
    rb"\bgh[pousr]_[A-Za-z0-9]{30,}\b",
    rb"github_pat_[A-Za-z0-9_]{30,}",
    rb"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----",
]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--staged", action="store_true")
    args = parser.parse_args()
    command = (
        ["git", "diff", "--cached", "--name-only", "--diff-filter=ACMR", "-z"]
        if args.staged
        else ["git", "ls-files", "-z"]
    )
    files = subprocess.check_output(command).decode().strip("\0").split("\0")
    failures = []
    for name in filter(None, files):
        path = pathlib.PurePosixPath(name)
        if (path.name.startswith(".env") and path.name != ".env.example") or path.name in {
            "terraform.tfstate",
            "terraform.tfvars",
            "backend.hcl",
            "secrets.json",
        }:
            failures.append((name, "private local file"))
            continue
        if not args.staged and not pathlib.Path(name).is_file():
            continue
        data = (
            subprocess.check_output(["git", "show", ":" + name])
            if args.staged
            else pathlib.Path(name).read_bytes()
        )
        if any(re.search(pattern, data) for pattern in PATTERNS):
            failures.append((name, "credential pattern"))
    for name, reason in failures:
        print(f"Blocked: {name} ({reason}; values suppressed)")
    if failures:
        return 1
    print(
        f"PASS: scanned {len(list(filter(None, files)))} files; no credential patterns or private settings found"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
