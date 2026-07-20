"""CSV export — the lowest-common-denominator output, pure stdlib."""

from __future__ import annotations

import csv
from pathlib import Path

from ..aggregate import DailyStat


def write_daily_csv(stats: list[DailyStat], path: str | Path) -> Path:
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow([
            "datum", "medewerker", "emails_verzonden", "telefoontjes",
            "belminuten", "gem_belminuten", "escalaties", "escalatie_ratio",
            "fcr_ratio", "gem_csat", "tickets",
        ])
        for s in stats:
            w.writerow([
                s.day.isoformat(), s.employee_name, s.emails_sent, s.calls_handled,
                s.call_minutes, s.avg_call_minutes, s.escalations, s.escalation_rate,
                s.fcr_rate, s.avg_csat if s.avg_csat is not None else "", s.ticket_count,
            ])
    return path
