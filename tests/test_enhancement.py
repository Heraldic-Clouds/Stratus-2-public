import base64
import hashlib
import io
from types import SimpleNamespace
from unittest.mock import Mock, MagicMock

import pytest
import requests
from PIL import Image

from worker import enhancement


def png():
    stream = io.BytesIO()
    Image.new("RGB", (32, 24), "blue").save(stream, "PNG")
    return stream.getvalue()


def setup(monkeypatch):
    monkeypatch.setenv("NEXTCLOUD_URL", "https://staging.example.com")
    source = png()
    http = MagicMock()
    http.post.return_value.status_code = 200
    http.post.return_value.json.return_value = {
        "claimed": True,
        "consent": True,
        "prompt": "Colorize",
        "sha256": hashlib.sha256(source).hexdigest(),
    }
    http.get.return_value.__enter__.return_value.iter_content.return_value = [source]
    ai = Mock()
    ai.return_value.with_options.return_value.images.edit.return_value = SimpleNamespace(
        data=[SimpleNamespace(b64_json=base64.b64encode(source).decode())]
    )
    return http, ai, {"job_id": "a" * 32, "token": "scoped", "schema_version": 1}


def test_success_fetches_authorized_original_and_posts_valid_png(monkeypatch):
    http, ai, message = setup(monkeypatch)
    enhancement.process(message, http=http, client_factory=ai)
    assert (
        http.post.call_args_list[0].args[0]
        == "https://staging.example.com/index.php/apps/stratus2_ai/photo-worker/" + "a" * 32 + "/claim"
    )
    assert http.post.call_args_list[-1].args[0].endswith("/complete")
    output = base64.b64decode(http.post.call_args.kwargs["json"]["image"])
    with Image.open(io.BytesIO(output)) as result:
        assert result.format == "PNG"
    assert ai.return_value.with_options.call_args.kwargs["max_retries"] == 0
    assert ai.return_value.with_options.return_value.images.edit.call_args.kwargs["prompt"] == "Colorize"


@pytest.mark.parametrize("status", [403, 404, 410])
def test_denied_unpaid_or_cancelled_jobs_never_download_or_generate(monkeypatch, status):
    http, ai, message = setup(monkeypatch)
    http.post.return_value.status_code = status
    enhancement.process(message, http=http, client_factory=ai)
    http.get.assert_not_called()
    ai.assert_not_called()


def test_duplicate_delivery_never_repeats_generation(monkeypatch):
    http, ai, message = setup(monkeypatch)
    http.post.return_value.json.return_value = {"claimed": False}
    enhancement.process(message, http=http, client_factory=ai)
    http.get.assert_not_called()
    ai.assert_not_called()


def test_generation_timeout_marks_ambiguous_without_regeneration(monkeypatch):
    http, ai, message = setup(monkeypatch)
    ai.return_value.with_options.return_value.images.edit.side_effect = TimeoutError(
        "private upstream detail"
    )
    enhancement.process(message, http=http, client_factory=ai)
    assert http.post.call_args.args[0].endswith("/failure")
    assert http.post.call_args.kwargs["json"] == {"reason": "ambiguous"}
    assert ai.return_value.with_options.return_value.images.edit.call_count == 1


def test_modified_source_is_rejected_before_ai(monkeypatch):
    http, ai, message = setup(monkeypatch)
    http.post.return_value.json.return_value["sha256"] = "b" * 64
    enhancement.process(message, http=http, client_factory=ai)
    ai.assert_not_called()
    assert http.post.call_args.kwargs["json"] == {"reason": "failed"}


def test_result_delivery_retry_does_not_regenerate(monkeypatch):
    http, ai, message = setup(monkeypatch)
    claim = http.post.return_value
    http.post.side_effect = [claim, requests.ConnectionError("unavailable"), claim]
    enhancement.process(message, http=http, client_factory=ai)
    assert ai.return_value.with_options.return_value.images.edit.call_count == 1
    assert http.post.call_args.args[0].endswith("/complete")


def test_validation_rejects_animated_images_and_transport_overflow(monkeypatch):
    monkeypatch.setattr(enhancement, "MAX_BYTES", 8)
    with pytest.raises(ValueError, match="transport"):
        enhancement.validated_image(png())


def test_exif_metadata_is_not_sent_to_provider():
    stream = io.BytesIO()
    exif = Image.Exif()
    exif[270] = "Private photo metadata"
    Image.new("RGB", (32, 24), "blue").save(stream, "JPEG", exif=exif)
    with Image.open(io.BytesIO(enhancement.validated_image(stream.getvalue()))) as normalized:
        assert not normalized.getexif()
