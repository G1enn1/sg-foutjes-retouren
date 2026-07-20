"""Derived metrics: difficulty proxies and career-progression signals.

Volume (emails, calls, minutes) tells you *how much* someone did, not *how hard*
it was or *how well* they did it. These helpers turn the optional enrichers on
each interaction into interpretable indicators. They degrade gracefully: if the
enrichers are missing (e.g. no HubSpot tickets/categories yet), the difficulty
figures are simply reported as unavailable rather than as fake precision.
"""

from __future__ import annotations

from collections import defaultdict
from dataclasses import dataclass
from datetime import date

from .aggregate import DailyStat

# Relative weights per ticket category. Tune these to your own reality; they are
# the single knob that encodes "a garantieclaim is heavier than an adreswijziging".
DEFAULT_CATEGORY_WEIGHTS: dict[str, float] = {
    "adreswijziging": 1.0,
    "retour": 1.5,
    "verkeerd geleverd": 2.0,
    "garantie": 3.0,
    "klacht": 3.5,
}


@dataclass
class DifficultyProfile:
    employee_id: str
    employee_name: str
    weighted_difficulty: float | None   # avg category weight of handled tickets
    avg_touches_per_ticket: float | None
    escalation_rate: float | None
    fcr_rate: float | None
    reopen_rate: float | None
    avg_csat: float | None
    category_mix: dict[str, int]


def difficulty_profiles(
    stats: list[DailyStat],
    category_weights: dict[str, float] | None = None,
) -> list[DifficultyProfile]:
    """Summarize, per employee, how hard the handled work was.

    ``weighted_difficulty`` is the average category weight across handled
    tickets. Higher = heavier caseload. ``None`` where the data to compute a
    signal honestly is absent.
    """

    weights = category_weights or DEFAULT_CATEGORY_WEIGHTS
    by_emp: dict[str, list[DailyStat]] = defaultdict(list)
    for s in stats:
        by_emp[s.employee_id].append(s)

    profiles: list[DifficultyProfile] = []
    for emp_id, rows in by_emp.items():
        name = rows[0].employee_name
        tickets = sum(r.ticket_count for r in rows)
        escalations = sum(r.escalations for r in rows)
        fcr = sum(r.first_contact_resolved for r in rows)
        reopened = sum(r.reopened_tickets for r in rows)
        interactions = sum(r.total_interactions for r in rows)

        cat_mix: dict[str, int] = defaultdict(int)
        for r in rows:
            for cat, n in r.categories.items():
                cat_mix[cat] += n

        weighted = None
        if cat_mix:
            total = sum(cat_mix.values())
            weighted = round(
                sum(weights.get(cat, 1.0) * n for cat, n in cat_mix.items()) / total, 2
            )

        csat_scores = [c for r in rows for c in r.csat_scores]

        profiles.append(
            DifficultyProfile(
                employee_id=emp_id,
                employee_name=name,
                weighted_difficulty=weighted,
                avg_touches_per_ticket=round(interactions / tickets, 2) if tickets else None,
                escalation_rate=round(escalations / tickets, 3) if tickets else None,
                fcr_rate=round(fcr / tickets, 3) if tickets else None,
                reopen_rate=round(reopened / tickets, 3) if tickets else None,
                avg_csat=round(sum(csat_scores) / len(csat_scores), 2) if csat_scores else None,
                category_mix=dict(cat_mix),
            )
        )
    return sorted(profiles, key=lambda p: p.employee_name)


@dataclass
class TrendPoint:
    week: str          # ISO year-week, e.g. "2026-W29"
    emails_sent: int
    calls_handled: int
    call_minutes: float
    avg_touches_per_ticket: float | None
    escalation_rate: float | None
    weighted_difficulty: float | None


def weekly_trend(
    stats: list[DailyStat],
    employee_id: str,
    category_weights: dict[str, float] | None = None,
) -> list[TrendPoint]:
    """Weekly trend for one employee — the basis for judging *progression*.

    Progress looks like: volume steady/up while avg touches and escalation rate
    fall, and weighted difficulty rises (taking on heavier work over time).
    """

    weights = category_weights or DEFAULT_CATEGORY_WEIGHTS
    rows = [s for s in stats if s.employee_id == employee_id]
    by_week: dict[str, list[DailyStat]] = defaultdict(list)
    for s in rows:
        iso = s.day.isocalendar()
        by_week[f"{iso.year}-W{iso.week:02d}"].append(s)

    points: list[TrendPoint] = []
    for week in sorted(by_week):
        wk = by_week[week]
        tickets = sum(r.ticket_count for r in wk)
        interactions = sum(r.total_interactions for r in wk)
        escalations = sum(r.escalations for r in wk)
        cat_mix: dict[str, int] = defaultdict(int)
        for r in wk:
            for cat, n in r.categories.items():
                cat_mix[cat] += n
        weighted = None
        if cat_mix:
            total = sum(cat_mix.values())
            weighted = round(sum(weights.get(c, 1.0) * n for c, n in cat_mix.items()) / total, 2)
        points.append(
            TrendPoint(
                week=week,
                emails_sent=sum(r.emails_sent for r in wk),
                calls_handled=sum(r.calls_handled for r in wk),
                call_minutes=round(sum(r.call_minutes for r in wk), 1),
                avg_touches_per_ticket=round(interactions / tickets, 2) if tickets else None,
                escalation_rate=round(escalations / tickets, 3) if tickets else None,
                weighted_difficulty=weighted,
            )
        )
    return points
