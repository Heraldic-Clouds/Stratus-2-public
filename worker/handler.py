"""SQS consumer with per-record retry and job-scoped Nextcloud capabilities."""

import hashlib
import json
import logging
import os
from functools import lru_cache
from urllib.parse import urlsplit

import boto3
import requests
from botocore.config import Config
from openai import OpenAI

from worker.analysis import cache_key, default_category, describe, describe_batch, safe_stem, thumbnail

log = logging.getLogger(__name__)
log.setLevel(logging.INFO)
AWS_CONFIG = Config(connect_timeout=5, read_timeout=20, retries={"mode": "standard", "total_max_attempts": 3})


@lru_cache(maxsize=1)
def services():
    secret = boto3.client("secretsmanager", config=AWS_CONFIG).get_secret_value(
        SecretId=os.environ["OPENAI_SECRET_ARN"]
    )
    settings = json.loads(secret["SecretString"])
    return OpenAI(api_key=settings["OPENAI_API_KEY"], timeout=60, max_retries=2)


def api_base() -> str:
    base = os.environ["NEXTCLOUD_URL"].rstrip("/")
    parsed = urlsplit(base)
    if parsed.scheme != "https" or parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise ValueError("Nextcloud must have a fixed HTTPS origin")
    return base + "/index.php/apps/stratus2_ai/worker/"


def process(message: dict, http=requests, client_factory=services):
    job_id, file_id, token = str(message["job_id"]), int(message["file_id"]), message["token"]
    if len(job_id) != 32 or any(c not in "0123456789abcdef" for c in job_id):
        raise ValueError("Invalid job ID")
    headers = {"Authorization": "Bearer " + token, "Accept": "application/json"}
    url = api_base() + job_id + "/" + str(file_id)
    meta_response = http.get(url, headers=headers, timeout=(5, 30), allow_redirects=False)
    if meta_response.status_code in (404, 410):
        return
    meta_response.raise_for_status()
    meta = meta_response.json()
    if meta.get("complete"):
        return
    if not meta.get("consent"):
        raise ValueError("Owner consent is required")
    digest = hashlib.sha256()
    size = 0
    with http.get(
        url + "/content", headers=headers, stream=True, timeout=(5, 90), allow_redirects=False
    ) as content:
        content.raise_for_status()
        for block in content.iter_content(1024 * 1024):
            size += len(block)
            if size > 512 * 1024 * 1024:
                raise ValueError("File exceeds analysis limit")
            digest.update(block)
    result = {
        "sha256": digest.hexdigest(),
        "category": default_category(meta["mime"]),
        "filename": "",
        "description": "",
        "phash": "",
        "blur_score": None,
    }
    image = meta["mime"].startswith("image/") and meta["mime"] != "image/svg+xml"
    batch = meta.get("batch", False)
    if image or batch:
        key_prompt = ("batch-v1:" + meta.get("path", "") + ":" if batch else "") + meta["prompt"]
        key = cache_key(result["sha256"], key_prompt, os.environ.get("OPENAI_MODEL", "gpt-4.1-mini"))
        cached = http.get(url + "/cache?key=" + key, headers=headers, timeout=(5, 30), allow_redirects=False)
        cached.raise_for_status()
        if cached.json().get("result"):
            result = cached.json()["result"]
        else:
            data = None
            if image:
                preview = http.get(url + "/preview", headers=headers, timeout=(5, 60), allow_redirects=False)
                preview.raise_for_status()
                data, result["phash"], result["blur_score"] = thumbnail(preview.content)
            result.update(
                (describe_batch(client_factory(), data, meta["prompt"], meta.get("path", ""), os.environ.get("OPENAI_MODEL", "gpt-4.1-mini"))
                 if batch else describe(client_factory(), data, meta["prompt"], os.environ.get("OPENAI_MODEL", "gpt-4.1-mini")))
            )
            result["filename"] = safe_stem(result["filename"])
        result["cache_key"] = key
    response = http.post(
        url, headers=headers, json={"result": result}, timeout=(5, 60), allow_redirects=False
    )
    if response.status_code not in (404, 410):
        response.raise_for_status()


def handler(event, context):
    failures = []
    for record in event.get("Records", []):
        try:
            process(json.loads(record["body"]))
        except Exception as exc:
            # No prompts, file paths, URLs, tokens, or upstream exception strings in logs.
            log.warning("analysis_failed type=%s", type(exc).__name__)
            failures.append({"itemIdentifier": record["messageId"]})
    return {"batchItemFailures": failures}
