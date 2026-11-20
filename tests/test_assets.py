import hashlib
import json
import pathlib
import subprocess


SCRIPT = pathlib.Path(__file__).resolve().parents[1] / "scripts/build_assets.mjs"


def test_browser_asset_paths_change_with_content_without_changing_app_schema(tmp_path):
    (tmp_path / "js").mkdir()
    (tmp_path / "css").mkdir()
    (tmp_path / "js" / "photos.js").write_text("first release")
    (tmp_path / "css" / "main.css").write_text("body{}")
    subprocess.run(["node", str(SCRIPT), str(tmp_path)], check=True)
    original = json.loads((tmp_path / "asset-manifest.json").read_text())
    assert original["js/photos.js"] == "photos-" + hashlib.sha256(b"first release").hexdigest()[:20]
    (tmp_path / "js" / "photos.js").write_text("second release")
    subprocess.run(["node", str(SCRIPT), str(tmp_path)], check=True)
    updated = json.loads((tmp_path / "asset-manifest.json").read_text())
    assert original["js/photos.js"] != updated["js/photos.js"]
    assert original["css/main.css"] == updated["css/main.css"]
    assert (tmp_path / "js" / (original["js/photos.js"] + ".js")).read_text() == "first release"
    assert len(updated) == 2  # Existing hashed outputs are not recursively hashed again.
