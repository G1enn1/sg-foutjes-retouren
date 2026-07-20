"""Voys Freedom — "Gespreksnotificaties" webhook receiver (automated route).

Voys can push a notification for every call in your Freedom environment to a URL
you configure (Beheer > Gespreksnotificaties). This module is a tiny, dependency
-free receiver that:

  1. accepts those POSTs,
  2. normalizes each into an ``Interaction`` (attributed via internal extension),
  3. appends it as one JSON line to a store file (``calls.jsonl``).

The daily report then reads that store (see ``load_store``) instead of pulling an
API. This is the clean, real-time route the BI integrations use.

Run:
    export TEAM_MONITOR_CONFIG=config.yaml
    export TEAM_MONITOR_STORE=data/calls.jsonl
    python -m team_monitor.sources.voys_webhook --port 8080

Then point Voys Gespreksnotificaties at  https://<jouw-host>/voys  (put it behind
HTTPS / a reverse proxy). The exact payload fields differ per account — hit the
endpoint once, inspect the logged raw body, and adjust ``normalize`` accordingly.
"""

from __future__ import annotations

import json
import os
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from typing import Any
from urllib.parse import parse_qs

from ..config import Settings, load_settings
from ..models import Channel, Direction, Interaction


def normalize(payload: dict, settings: Settings) -> Interaction | None:
    """Map one Voys notification payload to an Interaction. ADJUST KEYS HERE.

    Voys notification variables are things like caller id, dialled number,
    direction and (for completed-call events) duration. We attribute via the
    internal extension on whichever leg matches the employee mapping.
    """
    ext_index = settings.voipgrid_index()

    direction_raw = str(payload.get("direction") or payload.get("richting") or "").lower()
    if direction_raw.startswith("out") or direction_raw.startswith("uit"):
        direction = Direction.OUTBOUND
    elif direction_raw.startswith("in"):
        direction = Direction.INBOUND
    else:
        direction = Direction.INTERNAL

    caller = _digits(payload.get("caller") or payload.get("callerid") or payload.get("bron"))
    callee = _digits(payload.get("destination") or payload.get("did") or payload.get("bestemming"))
    internal = _digits(payload.get("internal_number") or payload.get("account") or "")

    emp = ext_index.get(internal)
    if emp is None:
        emp = ext_index.get(caller) if direction is Direction.OUTBOUND else ext_index.get(callee)
    if emp is None:
        emp = ext_index.get(caller) or ext_index.get(callee)
    if emp is None:
        return None

    duration = payload.get("duration") or payload.get("talk_time") or payload.get("duur") or 0
    try:
        duration = int(float(duration))
    except (TypeError, ValueError):
        duration = 0

    return Interaction(
        employee_id=emp.id,
        employee_name=emp.name,
        channel=Channel.CALL,
        direction=direction,
        timestamp=_parse_ts(payload.get("timestamp") or payload.get("start") or payload.get("datum")),
        duration_seconds=duration,
    )


def append_to_store(interaction: Interaction, store_path: str | Path) -> None:
    store_path = Path(store_path)
    store_path.parent.mkdir(parents=True, exist_ok=True)
    record = {
        "employee_id": interaction.employee_id,
        "employee_name": interaction.employee_name,
        "direction": interaction.direction.value,
        "timestamp": interaction.timestamp.isoformat(),
        "duration_seconds": interaction.duration_seconds,
    }
    with store_path.open("a", encoding="utf-8") as fh:
        fh.write(json.dumps(record) + "\n")


def load_store(store_path: str | Path) -> list[Interaction]:
    """Read the JSONL store back into Interactions (used by the report)."""
    store_path = Path(store_path)
    if not store_path.exists():
        return []
    out: list[Interaction] = []
    for line in store_path.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        r = json.loads(line)
        out.append(Interaction(
            employee_id=r["employee_id"],
            employee_name=r["employee_name"],
            channel=Channel.CALL,
            direction=Direction(r["direction"]),
            timestamp=datetime.fromisoformat(r["timestamp"]),
            duration_seconds=int(r["duration_seconds"]),
        ))
    return out


def _digits(value: Any) -> str:
    return "".join(ch for ch in str(value or "") if ch.isdigit())


def _parse_ts(value: Any) -> datetime:
    if not value:
        return datetime.now(timezone.utc)
    text = str(value)
    for fmt in ("%Y-%m-%dT%H:%M:%S", "%Y-%m-%d %H:%M:%S", "%d-%m-%Y %H:%M:%S"):
        try:
            return datetime.strptime(text, fmt)
        except ValueError:
            continue
    try:
        return datetime.fromisoformat(text.replace("Z", "+00:00"))
    except ValueError:
        return datetime.now(timezone.utc)


def make_handler(settings: Settings, store_path: str):
    class Handler(BaseHTTPRequestHandler):
        def do_GET(self):  # Voys webhooks may probe with GET; ack it.
            self._ack()

        def do_POST(self):
            length = int(self.headers.get("Content-Length", 0))
            body = self.rfile.read(length).decode("utf-8", "replace") if length else ""
            payload = _parse_body(body, self.headers.get("Content-Type", ""))
            try:
                interaction = normalize(payload, settings)
                if interaction is not None:
                    append_to_store(interaction, store_path)
            except Exception as exc:  # never 500 the telco; log and ack
                self.log_message("normalize error: %s | raw=%s", exc, body[:500])
            self._ack()

        def _ack(self):
            self.send_response(200)
            self.send_header("Content-Type", "text/plain")
            self.end_headers()
            self.wfile.write(b"ACK")

        def log_message(self, fmt, *args):  # keep logs terse
            print("[voys-webhook] " + (fmt % args))

    return Handler


def _parse_body(body: str, content_type: str) -> dict:
    body = body.strip()
    if not body:
        return {}
    if "json" in content_type or body.startswith("{"):
        try:
            return json.loads(body)
        except json.JSONDecodeError:
            return {}
    # form-encoded
    return {k: v[0] for k, v in parse_qs(body).items()}


def main(argv: list[str] | None = None) -> int:
    import argparse

    ap = argparse.ArgumentParser(description="Voys Freedom Gespreksnotificaties-ontvanger")
    ap.add_argument("--port", type=int, default=8080)
    ap.add_argument("--config", default=os.environ.get("TEAM_MONITOR_CONFIG"))
    ap.add_argument("--store", default=os.environ.get("TEAM_MONITOR_STORE", "data/calls.jsonl"))
    args = ap.parse_args(argv)

    settings = load_settings(args.config)
    handler = make_handler(settings, args.store)
    server = HTTPServer(("0.0.0.0", args.port), handler)
    print(f"[voys-webhook] luistert op :{args.port}/voys  ->  store: {args.store}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n[voys-webhook] gestopt")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
