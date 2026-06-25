"""
app.py -- Redfin Deal Finder web UI (runs locally on your computer).

Start it with:   python app.py      (or use run.sh / run.bat)
Then open:       http://127.0.0.1:5000

Why local? Redfin blocks data-center IPs, so this must run from your own
machine to reach live data. If a live request is blocked, the app falls back to
bundled demo data and clearly says so -- the UI still works either way.
"""

from __future__ import annotations

import json
import os

from flask import Flask, jsonify, render_template, request

import redfin_client

app = Flask(__name__)

SAVED_SEARCH_FILE = os.path.join(os.path.dirname(__file__), "saved_search.json")


def _filters_from_request(data: dict) -> dict:
    """Coerce the JSON body from the browser into the filter dict the client wants."""
    return {
        "location": (data.get("location") or "").strip(),
        "zips": (data.get("zips") or "").strip(),
        "min_price": data.get("min_price") or None,
        "max_price": data.get("max_price") or None,
        "min_beds": data.get("min_beds") or None,
        "min_baths": data.get("min_baths") or None,
        "max_dom": data.get("max_dom") or None,
        "home_types": data.get("home_types") or [],
    }


@app.get("/")
def index():
    return render_template("index.html")


@app.post("/api/search")
def api_search():
    data = request.get_json(force=True, silent=True) or {}
    filters = _filters_from_request(data)
    # allow_live can be turned off from the UI to force demo mode for a quick look.
    allow_live = bool(data.get("allow_live", True))
    result = redfin_client.search(filters, allow_live=allow_live)
    return jsonify(result)


@app.post("/api/save-search")
def api_save_search():
    """Persist the current filters so the daily digest script can reuse them."""
    data = request.get_json(force=True, silent=True) or {}
    filters = _filters_from_request(data)
    with open(SAVED_SEARCH_FILE, "w", encoding="utf-8") as fh:
        json.dump(filters, fh, indent=2)
    return jsonify({"ok": True, "saved_to": os.path.basename(SAVED_SEARCH_FILE)})


if __name__ == "__main__":
    port = int(os.environ.get("PORT", "5000"))
    print(f"\n  Redfin Deal Finder running -> http://127.0.0.1:{port}\n")
    app.run(host="127.0.0.1", port=port, debug=False)
