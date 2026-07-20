"""Deterministic sample-data generator.

Produces a realistic-looking set of interactions for a small office team so the
whole pipeline (aggregate -> metrics -> export) can be demonstrated end to end
without any API credentials. Deterministic (seeded) so demo output is stable.
"""

from __future__ import annotations

import random
from datetime import date, datetime, time, timedelta

from ..models import Channel, Direction, Interaction

_TEAM = [
    ("sanne", "Sanne de Vries"),
    ("tom", "Tom Bakker"),
    ("aisha", "Aisha El Amrani"),
    ("daan", "Daan Jansen"),
    ("lotte", "Lotte Visser"),
]

# category -> (relative frequency, typical difficulty). Newer/junior staff draw
# lighter categories more often; seniors take the heavy ones. This lets the demo
# show meaningful difficulty differences between people.
_CATEGORIES = ["adreswijziging", "retour", "verkeerd geleverd", "garantie", "klacht"]

# per-employee skill profile: (calls/day, emails/day, seniority 0..1)
_PROFILE = {
    "sanne": (14, 22, 0.9),   # senior, high volume, hard cases
    "tom": (10, 16, 0.6),
    "aisha": (12, 20, 0.75),
    "daan": (7, 12, 0.3),     # junior, lighter cases, more escalations
    "lotte": (9, 14, 0.5),
}


def generate(days: int = 14, end: date | None = None, seed: int = 42) -> list[Interaction]:
    rng = random.Random(seed)
    end = end or date(2026, 7, 19)
    start = end - timedelta(days=days - 1)

    interactions: list[Interaction] = []
    ticket_seq = 1000

    day = start
    while day <= end:
        if day.weekday() < 5:  # weekdays only
            for emp_id, name in _TEAM:
                calls_per_day, emails_per_day, seniority = _PROFILE[emp_id]
                # daily variation
                n_calls = max(0, int(rng.gauss(calls_per_day, 2)))
                n_emails = max(0, int(rng.gauss(emails_per_day, 3)))

                for _ in range(n_calls):
                    ticket_seq += 1
                    cat = _weighted_category(rng, seniority)
                    # heavier cases take longer; seniors are a bit faster
                    base = 3 + _CATEGORIES.index(cat) * 2.5
                    talk = max(30, int(rng.gauss(base * 60, 90) * (1.1 - 0.2 * seniority)))
                    interactions.append(
                        Interaction(
                            employee_id=emp_id,
                            employee_name=name,
                            channel=Channel.CALL,
                            direction=Direction.INBOUND if rng.random() < 0.7 else Direction.OUTBOUND,
                            timestamp=_stamp(rng, day),
                            duration_seconds=talk,
                            ticket_id=f"T{ticket_seq}",
                            category=cat,
                            is_escalation=rng.random() < (0.18 * (1 - seniority)),
                            is_first_contact_resolution=rng.random() < (0.55 + 0.35 * seniority),
                            reopened=rng.random() < (0.12 * (1 - seniority)),
                            csat=_csat(rng, seniority),
                        )
                    )

                for _ in range(n_emails):
                    ticket_seq += 1
                    cat = _weighted_category(rng, seniority)
                    interactions.append(
                        Interaction(
                            employee_id=emp_id,
                            employee_name=name,
                            channel=Channel.EMAIL,
                            direction=Direction.OUTBOUND if rng.random() < 0.65 else Direction.INBOUND,
                            timestamp=_stamp(rng, day),
                            ticket_id=f"T{ticket_seq}",
                            category=cat,
                            is_escalation=rng.random() < (0.12 * (1 - seniority)),
                            is_first_contact_resolution=rng.random() < (0.5 + 0.35 * seniority),
                            reopened=rng.random() < (0.10 * (1 - seniority)),
                            csat=_csat(rng, seniority) if rng.random() < 0.4 else None,
                        )
                    )
        day += timedelta(days=1)

    return interactions


def _weighted_category(rng: random.Random, seniority: float) -> str:
    # seniority shifts the distribution toward heavier categories
    weights = [max(0.05, 1 - seniority + i * seniority * 0.6) for i in range(len(_CATEGORIES))]
    return rng.choices(_CATEGORIES, weights=weights, k=1)[0]


def _csat(rng: random.Random, seniority: float) -> float:
    return round(min(5.0, max(1.0, rng.gauss(3.6 + seniority, 0.7))), 1)


def _stamp(rng: random.Random, day: date) -> datetime:
    hour = rng.randint(8, 17)
    minute = rng.randint(0, 59)
    return datetime.combine(day, time(hour, minute))
