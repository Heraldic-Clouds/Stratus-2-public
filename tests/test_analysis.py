import io
from unittest.mock import Mock

import numpy as np
import pytest
from PIL import Image

from worker.analysis import cache_key, default_category, describe_batch, safe_stem, thumbnail
from worker import handler


@pytest.mark.parametrize(
    ("mime", "expected"),
    [
        ("image/jpeg", "Photos"),
        ("image/svg+xml", "Graphics"),
        ("video/mp4", "Videos"),
        ("application/pdf", "Documents"),
        ("application/vnd.openxmlformats-officedocument.wordprocessingml.document", "Documents"),
        ("application/octet-stream", "Other"),
    ],
)
def test_mime_routing(mime, expected):
    assert default_category(mime) == expected


def test_filename_cannot_escape_folder():
    assert safe_stem("../../My Dog!\nignored") == "my_dog_ignored"
    assert safe_stem("") == "file"
    assert len(safe_stem("a" * 500)) == 100


def test_blur_phash_and_preview_dimensions():
    blank = io.BytesIO()
    Image.new("RGB", (1600, 1200), "white").save(blank, "JPEG")
    preview, phash, score = thumbnail(blank.getvalue())
    assert len(phash) == 16
    assert score == 0
    with Image.open(io.BytesIO(preview)) as image:
        assert max(image.size) <= 768
    rng = np.random.default_rng(42)
    sharp = io.BytesIO()
    Image.fromarray(rng.integers(0, 256, (256, 256, 3), dtype=np.uint8)).save(sharp, "PNG")
    assert thumbnail(sharp.getvalue())[2] > 80


def test_cache_includes_content_instructions_and_model():
    key = cache_key("a", "one", "m1")
    assert key != cache_key("b", "one", "m1")
    assert key != cache_key("a", "two", "m1")
    assert key != cache_key("a", "one", "m2")


def test_batch_planning_supplies_image_and_directory_instructions():
    client = Mock()
    client.responses.create.return_value.output_text = '{"action":"edit"}'
    result = describe_batch(client, b"preview", "Colorize black and white photos", "Family/a.jpg", "fixture")
    assert result["action"] == "edit"
    request = client.responses.create.call_args.kwargs
    assert "Family/a.jpg" in request["input"][0]["content"][0]["text"]
    assert request["input"][0]["content"][1]["type"] == "input_image"
    assert request["text"]["format"]["schema"]["additionalProperties"] is False


def test_batch_planning_supports_non_image_files():
    client = Mock()
    client.responses.create.return_value.output_text = '{"action":"move"}'
    describe_batch(client, None, "Organize my documents", "Family/a.pdf", "fixture")
    assert len(client.responses.create.call_args.kwargs["input"][0]["content"]) == 1


def test_sqs_only_retries_failed_records(monkeypatch):
    processor = Mock(side_effect=[None, RuntimeError("private detail"), None])
    monkeypatch.setattr(handler, "process", processor)
    result = handler.handler({"Records": [{"messageId": str(i), "body": "{}"} for i in range(3)]}, None)
    assert result == {"batchItemFailures": [{"itemIdentifier": "1"}]}


def test_inactive_jobs_do_not_fetch_content_or_call_ai(monkeypatch):
    monkeypatch.setenv("NEXTCLOUD_URL", "https://stratus.example.com")
    http = Mock()
    http.get.return_value.status_code = 410
    ai = Mock()
    handler.process({"job_id": "a" * 32, "file_id": 4, "token": "scope"}, http=http, client_factory=ai)
    ai.assert_not_called()
    assert http.get.call_count == 1


def test_duplicate_delivery_does_not_reanalyze(monkeypatch):
    monkeypatch.setenv("NEXTCLOUD_URL", "https://stratus.example.com")
    http = Mock()
    http.get.return_value.status_code = 200
    http.get.return_value.json.return_value = {"complete": True}
    ai = Mock()
    handler.process({"job_id": "a" * 32, "file_id": 4, "token": "scope"}, http=http, client_factory=ai)
    ai.assert_not_called()
    http.post.assert_not_called()


def test_no_consent_prevents_file_download(monkeypatch):
    monkeypatch.setenv("NEXTCLOUD_URL", "https://stratus.example.com")
    http = Mock()
    http.get.return_value.status_code = 200
    http.get.return_value.json.return_value = {"consent": False}
    with pytest.raises(ValueError, match="consent"):
        handler.process({"job_id": "a" * 32, "file_id": 4, "token": "scope"}, http=http)
    assert http.get.call_count == 1


@pytest.mark.parametrize(
    "url", ["http://stratus.example.com", "https://token@stratus.example.com", "https://stratus.example.com?token=secret"]
)
def test_worker_requires_fixed_secure_origin(monkeypatch, url):
    monkeypatch.setenv("NEXTCLOUD_URL", url)
    with pytest.raises(ValueError):
        handler.api_base()
