"""LEGACY: Voys phone data via the older VoIPGRID platform CDR API.

NOTE: Voys Freedom (the current platform) does NOT expose this pull API. Use
``voys_export.py`` (call-list export) or ``voys_webhook.py`` (Gespreksnotificaties)
instead. This module is kept only for accounts still on the older VoIPGRID-based
stack, where the REST API exposes CDRs with timestamp, direction, talk time
(seconds) and the internal user.

Auth: token auth, header ``Authorization: Token <user>:<api_token>``.

IMPORTANT: exact endpoint path and field names vary per VoIPGRID API version and
per reseller. ``_normalize`` is the single place to adjust once you see a real
response — everything downstream depends only on the normalized ``Interaction``.
Run ``python -m team_monitor.sources.voipgrid --probe`` to dump one raw record.
"""

from __future__ import annotations

from datetime import date, datetime, timezone
from typing import Any, Iterable

from ..config import Settings
from ..models import Channel, Direction, Interaction

# Candidate endpoint. Confirm against your account's API version.
_CDR_PATH = "/api/v2/cdr/record/"


class VoipgridClient:
    def __init__(self, settings: Settings):
        self.settings = settings
        self.base = settings.voipgrid_api_url.rstrip("/")

    def _headers(self) -> dict[str, str]:
        token = f"{self.settings.voipgrid_user}:{self.settings.voipgrid_token}"
        return {"Authorization": f"Token {token}", "Accept": "application/json"}

    def fetch(self, start: date, end: date) -> list[Interaction]:
        """Fetch CDRs in [start, end] and map them onto Interactions."""
        import requests  # lazy: keeps the demo path dependency-free

        emp_by_user = self.settings.voipgrid_index()
        results: list[Interaction] = []
        url = f"{self.base}{_CDR_PATH}"
        params: dict[str, Any] = {
            "call_date__gte": start.isoformat(),
            "call_date__lte": end.isoformat(),
            "limit": 250,
        }
        while url:
            resp = requests.get(url, headers=self._headers(), params=params, timeout=30)
            resp.raise_for_status()
            payload = resp.json()
            for raw in payload.get("results", payload if isinstance(payload, list) else []):
                interaction = self._normalize(raw, emp_by_user)
                if interaction is not None:
                    results.append(interaction)
            url = payload.get("next") if isinstance(payload, dict) else None
            params = {}  # `next` already carries the query string
        return results

    def _normalize(self, raw: dict, emp_by_user: dict) -> Interaction | None:
        """Map one raw CDR record to an Interaction. ADJUST FIELD NAMES HERE."""
        user_key = str(raw.get("user") or raw.get("internal_number") or raw.get("account_id") or "")
        emp = emp_by_user.get(user_key)
        if emp is None:
            return None  # external / unmapped extension — skip

        talk_seconds = int(raw.get("atime") or raw.get("talk_time") or raw.get("duration") or 0)
        ts = _parse_ts(raw.get("call_date") or raw.get("timestamp") or raw.get("start"))

        raw_dir = str(raw.get("direction") or "").lower()
        if raw_dir.startswith("out"):
            direction = Direction.OUTBOUND
        elif raw_dir.startswith("in"):
            direction = Direction.INBOUND
        else:
            direction = Direction.INTERNAL

        return Interaction(
            employee_id=emp.id,
            employee_name=emp.name,
            channel=Channel.CALL,
            direction=direction,
            timestamp=ts,
            duration_seconds=talk_seconds,
        )


def _parse_ts(value: Any) -> datetime:
    if isinstance(value, datetime):
        return value
    if not value:
        return datetime.now(timezone.utc)
    text = str(value).replace("Z", "+00:00")
    try:
        return datetime.fromisoformat(text)
    except ValueError:
        return datetime.now(timezone.utc)


if __name__ == "__main__":  # pragma: no cover - manual probe helper
    import argparse
    import json

    from ..config import load_settings

    ap = argparse.ArgumentParser(description="Probe the VoIPGRID CDR endpoint")
    ap.add_argument("--probe", action="store_true", help="print one raw record")
    args = ap.parse_args()
    if args.probe:
        import requests

        s = load_settings()
        c = VoipgridClient(s)
        r = requests.get(f"{c.base}{_CDR_PATH}", headers=c._headers(), params={"limit": 1}, timeout=30)
        print(f"HTTP {r.status_code}")
        print(json.dumps(r.json(), indent=2)[:2000])
