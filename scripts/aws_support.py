"""Shared AWS configuration; credentials use the normal SDK provider chain."""

import os

import boto3
from botocore.config import Config

CONFIG = Config(connect_timeout=5, read_timeout=30, retries={"total_max_attempts": 3, "mode": "standard"})


def client(service):
    return boto3.client(
        service,
        config=CONFIG,
        region_name=os.environ.get("AWS_REGION") or os.environ.get("AWS_DEFAULT_REGION"),
    )
