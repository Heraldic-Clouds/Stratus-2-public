"""Paid photo edits. A durable server claim prevents SQS retries repeating generation."""

import base64
import hashlib
import io
import json
import logging
import os
import re

import requests
from openai import APIStatusError
from PIL import Image, ImageOps

from worker.handler import api_base, services

log = logging.getLogger(__name__)
MAX_BYTES = 40 * 1024 * 1024
MAX_PIXELS = 40_000_000


def validated_image(data: bytes, *, output=False) -> bytes:
    if len(data) > MAX_BYTES:
        raise ValueError("Image exceeds transport limit")
    with Image.open(io.BytesIO(data)) as photo:
        if photo.format not in (("PNG",) if output else ("JPEG", "PNG", "WEBP")):
            raise ValueError("Unsupported image format")
        if photo.width * photo.height > MAX_PIXELS or getattr(photo, "n_frames", 1) != 1:
            raise ValueError("Unsupported image dimensions or animation")
        photo.load()
        # Normalize orientation and remove metadata from the provider input/result;
        # the user's original file and its metadata are never modified.
        normalized = ImageOps.exif_transpose(photo).convert("RGBA" if "A" in photo.getbands() else "RGB")
        stream = io.BytesIO()
        normalized.save(stream, "PNG")
        result = stream.getvalue()
        if len(result) > MAX_BYTES:
            raise ValueError("Normalized image exceeds transport limit")
        return result


def process(message: dict, http=requests, client_factory=services):
    job_id = str(message["job_id"])
    if not re.fullmatch(r"[a-f0-9]{32}", job_id) or message.get("schema_version") != 1:
        raise ValueError("Invalid photo job")
    # Reuse the same strict HTTPS origin validation as the analysis worker.
    url = api_base().removesuffix("worker/") + "photo-worker/" + job_id
    headers = {"Authorization": "Bearer " + message["token"], "Accept": "application/json"}
    claim = http.post(url + "/claim", headers=headers, json={}, timeout=(5, 90), allow_redirects=False)
    if claim.status_code in (403, 404, 410):
        return
    claim.raise_for_status()
    meta = claim.json()
    if not meta.get("claimed"):
        return
    generation_started = False
    try:
        if not meta.get("consent"):
            raise ValueError("Photo consent is required")
        data = bytearray()
        with http.get(
            url + "/content", headers=headers, stream=True, timeout=(5, 90), allow_redirects=False
        ) as response:
            response.raise_for_status()
            for block in response.iter_content(1024 * 1024):
                data.extend(block)
                if len(data) > MAX_BYTES:
                    raise ValueError("Image exceeds transport limit")
        if hashlib.sha256(data).hexdigest() != meta["sha256"]:
            raise ValueError("Source changed during download")
        source = validated_image(bytes(data))
        model = os.environ.get("OPENAI_IMAGE_MODEL", "gpt-image-2")
        # A timed-out generation has an unknown billing outcome. Never let the
        # SDK or SQS transparently regenerate an image in that situation.
        editor = client_factory().with_options(timeout=600, max_retries=0)
        generation_started = True
        result = editor.images.edit(
            model=model,
            image=("photo.png", source, "image/png"),
            prompt=meta["prompt"],
            n=1,
            quality="medium",
            size="auto",
            output_format="png",
        )
        if not result.data or not result.data[0].b64_json:
            raise ValueError("No edited photo returned")
        output = validated_image(base64.b64decode(result.data[0].b64_json, validate=True), output=True)
        payload = {"image": base64.b64encode(output).decode("ascii"), "model": model}
        # Only retry persisting the exact output, never the image generation.
        for attempt in range(3):
            try:
                saved = http.post(
                    url + "/complete", headers=headers, json=payload, timeout=(5, 120), allow_redirects=False
                )
                if saved.status_code in (403, 404, 410):
                    return
                saved.raise_for_status()
                return
            except requests.RequestException:
                if attempt == 2:
                    raise
    except Exception as exc:
        ambiguous = generation_started and not (
            isinstance(exc, APIStatusError) and 400 <= exc.status_code < 500
        )
        reason = "ambiguous" if ambiguous else "failed"
        # Only a fixed reason crosses the callback; upstream errors may contain
        # prompts, credentials or image data and must not enter application logs.
        try:
            http.post(
                url + "/failure",
                headers=headers,
                json={"reason": reason},
                timeout=(5, 30),
                allow_redirects=False,
            )
        except requests.RequestException:
            pass  # Server's processing lease marks the outcome ambiguous later.
        log.warning("photo_edit_failed type=%s outcome=%s", type(exc).__name__, reason)


def handler(event, context):
    failures = []
    for record in event.get("Records", []):
        try:
            process(json.loads(record["body"]))
        except Exception as exc:
            log.warning("photo_dispatch_failed type=%s", type(exc).__name__)
            failures.append({"itemIdentifier": record["messageId"]})
    return {"batchItemFailures": failures}
