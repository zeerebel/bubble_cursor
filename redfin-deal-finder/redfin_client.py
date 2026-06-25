"""
redfin_client.py
----------------
Fetches listings from Redfin's (unofficial) search endpoints, normalizes them,
and scores each one as a potential "deal".

IMPORTANT REALITY CHECK
=======================
Redfin has no official public API and actively blocks data-center IP addresses.
This module therefore works best when run from a normal residential connection
(your home computer). If a live request is blocked or fails for any reason, the
module transparently falls back to the bundled `sample_data.csv` so the app
still runs and you can see exactly how it behaves -- the UI will clearly label
results as "demo data" in that case.

If live access is blocked even from home, plug in a scraper API (e.g. Apify or
HasData) inside `_fetch_live_csv()` -- it just needs to return CSV rows with the
same columns Redfin's "Download All" export uses.
"""

from __future__ import annotations

import csv
import io
import os
import statistics
from typing import Any

import requests

BASE = "https://www.redfin.com/stingray/"
# A browser-like UA. Redfin is more tolerant of these, but it is NOT a guarantee.
HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
        "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
    ),
    "Accept": "text/csv,application/json,*/*",
    "Accept-Language": "en-US,en;q=0.9",
}
# Redfin prefixes its JSON responses with this guard string.
_JSON_GUARD = "{}&&"

SAMPLE_CSV = os.path.join(os.path.dirname(__file__), "sample_data.csv")

# UI home-type -> (Redfin uipt code, substring used to match the CSV "PROPERTY TYPE")
HOME_TYPES: dict[str, tuple[str, str]] = {
    "house": ("1", "single family"),
    "condo": ("2", "condo"),
    "townhouse": ("3", "townhouse"),
    "multifamily": ("4", "multi"),
    "land": ("5", "land"),
}


# --------------------------------------------------------------------------- #
# Normalization
# --------------------------------------------------------------------------- #
def _num(value: Any) -> float | None:
    """Parse a possibly-messy numeric CSV cell into a float (or None)."""
    if value is None:
        return None
    s = str(value).strip().replace("$", "").replace(",", "").replace("+", "")
    if s == "" or s.lower() in {"n/a", "na", "—", "-"}:
        return None
    try:
        return float(s)
    except ValueError:
        return None


def _normalize_row(row: dict[str, str]) -> dict[str, Any]:
    """Map a Redfin 'Download All' CSV row to a clean dict."""
    # The CSV's URL column header is verbose; grab whichever key starts with URL.
    url = ""
    for key, val in row.items():
        if key.strip().upper().startswith("URL"):
            url = (val or "").strip()
            break
    return {
        "address": (row.get("ADDRESS") or "").strip(),
        "city": (row.get("CITY") or "").strip(),
        "state": (row.get("STATE OR PROVINCE") or "").strip(),
        "zip": (row.get("ZIP OR POSTAL CODE") or "").strip(),
        "price": _num(row.get("PRICE")),
        "beds": _num(row.get("BEDS")),
        "baths": _num(row.get("BATHS")),
        "sqft": _num(row.get("SQUARE FEET")),
        "lot_size": _num(row.get("LOT SIZE")),
        "year_built": _num(row.get("YEAR BUILT")),
        "dom": _num(row.get("DAYS ON MARKET")),
        "ppsf": _num(row.get("$/SQUARE FEET")),
        "hoa": _num(row.get("HOA/MONTH")),
        "property_type": (row.get("PROPERTY TYPE") or "").strip(),
        "status": (row.get("STATUS") or "").strip(),
        "url": url,
        "lat": _num(row.get("LATITUDE")),
        "lng": _num(row.get("LONGITUDE")),
    }


def _parse_csv(text: str) -> list[dict[str, Any]]:
    reader = csv.DictReader(io.StringIO(text))
    out = []
    for row in reader:
        norm = _normalize_row(row)
        if norm["address"]:
            out.append(norm)
    return out


# --------------------------------------------------------------------------- #
# Live Redfin access (best-effort; may be blocked)
# --------------------------------------------------------------------------- #
def _resolve_region(query: str, session: requests.Session) -> tuple[str, str] | None:
    """Resolve a 'zip / City, ST' query to (region_type, region_id) via autocomplete."""
    resp = session.get(
        BASE + "do/location-autocomplete",
        params={"location": query, "v": 2},
        timeout=20,
    )
    resp.raise_for_status()
    body = resp.text
    if body.startswith(_JSON_GUARD):
        body = body[len(_JSON_GUARD):]
    import json

    data = json.loads(body)
    rows = []
    for section in data.get("payload", {}).get("sections", []):
        rows.extend(section.get("rows", []))
    for row in rows:
        rid = row.get("id", "")
        # ids look like "<region_type>_<region_id>", e.g. "2_30749"
        if "_" in rid:
            rtype, _, rnum = rid.partition("_")
            if rtype.isdigit() and rnum.isdigit():
                return rtype, rnum
    return None


def _fetch_live_csv(filters: dict[str, Any], session: requests.Session) -> str:
    """Build the gis-csv request from filters and return the raw CSV text."""
    region = _resolve_region(filters["location"], session)
    if not region:
        raise RuntimeError(f"Could not resolve location: {filters['location']!r}")
    region_type, region_id = region

    uipt = ",".join(
        HOME_TYPES[h][0] for h in filters.get("home_types", []) if h in HOME_TYPES
    ) or "1,2,3,4,5,6,7,8"

    params: dict[str, Any] = {
        "al": 1,
        "region_id": region_id,
        "region_type": region_type,
        "uipt": uipt,
        "status": 9,            # active
        "num_homes": 350,
        "ord": "redfin-recommended-asc",
        "page_number": 1,
        "sf": "1,2,3,5,6,7",
        "v": 8,
    }
    if filters.get("min_price"):
        params["min_price"] = int(filters["min_price"])
    if filters.get("max_price"):
        params["max_price"] = int(filters["max_price"])
    if filters.get("min_beds"):
        params["num_beds"] = int(filters["min_beds"])
    if filters.get("min_baths"):
        params["num_baths"] = int(filters["min_baths"])
    if filters.get("max_dom"):
        params["max_dom"] = int(filters["max_dom"])

    resp = session.get(BASE + "api/gis-csv", params=params, headers=HEADERS, timeout=30)
    resp.raise_for_status()
    text = resp.text
    if "ADDRESS" not in text.upper():
        raise RuntimeError("Redfin did not return CSV rows (likely blocked or rate-limited).")
    return text


# --------------------------------------------------------------------------- #
# Filtering (applied to both live and demo data so the UI is consistent)
# --------------------------------------------------------------------------- #
def _apply_filters(listings: list[dict], filters: dict) -> list[dict]:
    zips = {z.strip() for z in str(filters.get("zips", "")).split(",") if z.strip()}
    home_substrings = [HOME_TYPES[h][1] for h in filters.get("home_types", []) if h in HOME_TYPES]

    out = []
    for h in listings:
        if filters.get("min_price") and (h["price"] or 0) < float(filters["min_price"]):
            continue
        if filters.get("max_price") and (h["price"] or 1e12) > float(filters["max_price"]):
            continue
        if filters.get("min_beds") and (h["beds"] or 0) < float(filters["min_beds"]):
            continue
        if filters.get("min_baths") and (h["baths"] or 0) < float(filters["min_baths"]):
            continue
        if filters.get("max_dom") and (h["dom"] or 0) > float(filters["max_dom"]):
            continue
        if zips and h["zip"] not in zips:
            continue
        if home_substrings and not any(s in h["property_type"].lower() for s in home_substrings):
            continue
        out.append(h)
    return out


# --------------------------------------------------------------------------- #
# Deal scoring (transparent and explainable)
# --------------------------------------------------------------------------- #
def score_deals(listings: list[dict]) -> list[dict]:
    """Attach a 0-100 deal_score and human-readable reasons to each listing."""
    ppsfs = [h["ppsf"] for h in listings if h["ppsf"]]
    doms = [h["dom"] for h in listings if h["dom"] is not None]
    prices = sorted(h["price"] for h in listings if h["price"])

    median_ppsf = statistics.median(ppsfs) if ppsfs else None
    median_dom = statistics.median(doms) if doms else None
    # 40th-percentile price -> "among the more affordable" in this result set.
    p40 = prices[int(len(prices) * 0.4)] if prices else None

    for h in listings:
        score = 0.0
        reasons: list[str] = []

        # 1) Price per sqft below the area median -> biggest signal (up to 55 pts)
        if median_ppsf and h["ppsf"]:
            below = (median_ppsf - h["ppsf"]) / median_ppsf
            if below > 0:
                score += min(below / 0.25, 1.0) * 55  # 25%+ below median = full points
                reasons.append(f"{below*100:.0f}% below median $/sqft")

        # 2) Stale on market -> motivated seller (up to 25 pts)
        if median_dom and h["dom"] and h["dom"] > median_dom:
            over = (h["dom"] - median_dom) / median_dom
            score += min(over, 1.0) * 25
            reasons.append(f"{int(h['dom'])} days on market (stale)")

        # 3) Among the more affordable in this search (up to 20 pts)
        if p40 and h["price"] and h["price"] <= p40:
            score += 20
            reasons.append("Among the lower-priced matches")

        h["deal_score"] = round(min(score, 100))
        h["deal_reasons"] = reasons

    listings.sort(key=lambda x: x["deal_score"], reverse=True)
    return listings


# --------------------------------------------------------------------------- #
# Public entry point
# --------------------------------------------------------------------------- #
def search(filters: dict[str, Any], allow_live: bool = True) -> dict[str, Any]:
    """
    Run a search. Returns:
      { "source": "live"|"demo", "message": str, "count": int, "listings": [...] }
    """
    source = "demo"
    message = ""

    listings: list[dict] = []
    if allow_live and filters.get("location"):
        try:
            with requests.Session() as s:
                s.headers.update(HEADERS)
                text = _fetch_live_csv(filters, s)
            listings = _parse_csv(text)
            source = "live"
        except Exception as exc:  # noqa: BLE001 - we intentionally degrade gracefully
            message = (
                f"Live Redfin request failed ({type(exc).__name__}: {exc}). "
                "Showing demo data instead. Run this on your home computer, or "
                "wire a scraper API into _fetch_live_csv(). See the README."
            )

    if not listings:
        with open(SAMPLE_CSV, encoding="utf-8") as fh:
            listings = _parse_csv(fh.read())
        if source != "live":
            source = "demo"
            if not message:
                message = "Showing bundled demo data (no live Redfin request made)."

    listings = _apply_filters(listings, filters)
    listings = score_deals(listings)

    return {
        "source": source,
        "message": message,
        "count": len(listings),
        "listings": listings,
    }
