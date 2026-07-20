"""Command-line entry point.

Examples
--------
Demo on generated sample data (no credentials needed):
    python -m team_monitor --demo

Live pull for a date range, writing Excel + HTML:
    python -m team_monitor --config config.yaml --from 2026-07-01 --to 2026-07-19 \
        --output out --formats excel,html

Push to Google Sheets:
    python -m team_monitor --config config.yaml --from 2026-07-01 --to 2026-07-19 \
        --formats gsheet
"""

from __future__ import annotations

import argparse
import sys
from datetime import date, datetime, timedelta
from pathlib import Path

from .aggregate import aggregate
from .config import load_settings
from .models import Interaction


def _parse_date(text: str) -> date:
    return datetime.strptime(text, "%Y-%m-%d").date()


def collect(args) -> list[Interaction]:
    if args.demo:
        from .sources.sample import generate
        return generate(days=args.days)

    settings = load_settings(args.config)
    start = _parse_date(args.from_) if args.from_ else date.today() - timedelta(days=args.days - 1)
    end = _parse_date(args.to) if args.to else date.today()

    interactions: list[Interaction] = []

    if settings.voipgrid_token:
        from .sources.voipgrid import VoipgridClient
        interactions += VoipgridClient(settings).fetch(start, end)
    else:
        print("[i] Geen VOIPGRID_TOKEN gezet — telefoondata overgeslagen.", file=sys.stderr)

    if settings.hubspot_token:
        from .sources.hubspot import HubspotClient
        interactions += HubspotClient(settings).fetch_emails(start, end)
    else:
        print("[i] Geen HUBSPOT_TOKEN gezet — e-maildata overgeslagen.", file=sys.stderr)

    if not interactions:
        print("[!] Geen data opgehaald. Controleer tokens/config of gebruik --demo.", file=sys.stderr)
    return interactions


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="team-monitor",
                                 description="E-mail- en telefoonstatistieken per medewerker per dag.")
    ap.add_argument("--demo", action="store_true", help="draai op gegenereerde voorbeelddata")
    ap.add_argument("--config", help="pad naar config.yaml met medewerker-mapping")
    ap.add_argument("--from", dest="from_", help="startdatum YYYY-MM-DD")
    ap.add_argument("--to", help="einddatum YYYY-MM-DD")
    ap.add_argument("--days", type=int, default=14, help="aantal dagen terug als geen from/to (default 14)")
    ap.add_argument("--output", default="out", help="uitvoermap (default: ./out)")
    ap.add_argument("--formats", default="html,csv",
                    help="komma-gescheiden: html,csv,excel,gsheet (default html,csv)")
    ap.add_argument("--title", default="Team-monitor — Stralend Groen")
    args = ap.parse_args(argv)

    interactions = collect(args)
    if not interactions:
        return 1

    stats = aggregate(interactions)
    out = Path(args.output)
    formats = {f.strip() for f in args.formats.split(",") if f.strip()}
    written: list[str] = []

    if "csv" in formats:
        from .exporters.csv_export import write_daily_csv
        written.append(str(write_daily_csv(stats, out / "per_dag.csv")))
    if "html" in formats:
        from .exporters.html_dashboard import write_dashboard
        written.append(str(write_dashboard(stats, out / "dashboard.html", title=args.title)))
    if "excel" in formats:
        from .exporters.excel_export import write_workbook
        written.append(str(write_workbook(stats, out / "team_monitor.xlsx")))
    if "gsheet" in formats:
        from .exporters.gsheet_export import write_gsheet
        written.append(write_gsheet(stats))

    print(f"[✓] {len(interactions)} interacties → {len(stats)} dagregels")
    for w in written:
        print(f"    - {w}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
