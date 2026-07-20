"""Aggregate normalized interactions into per-employee-per-day statistics.

This is deliberately dependency-free (pure stdlib) so the whole core path runs
without pandas/numpy. The output is a list of ``DailyStat`` rows plus small
helper views for the exporters.
"""

from __future__ import annotations

from collections import defaultdict
from dataclasses import dataclass, field
from datetime import date
from statistics import mean

from .models import Channel, Direction, Interaction


@dataclass
class DailyStat:
    """Everything we report for one employee on one day."""

    day: date
    employee_id: str
    employee_name: str

    # --- the two core overviews the request explicitly asked for ---
    emails_sent: int = 0
    calls_handled: int = 0
    call_minutes: float = 0.0

    # --- supporting volume figures ---
    emails_received: int = 0
    inbound_calls: int = 0
    outbound_calls: int = 0

    # --- difficulty / quality proxies (only populated if enrichers exist) ---
    escalations: int = 0
    reopened_tickets: int = 0
    first_contact_resolved: int = 0
    ticket_count: int = 0
    csat_scores: list[float] = field(default_factory=list)
    categories: dict[str, int] = field(default_factory=dict)

    @property
    def avg_call_minutes(self) -> float:
        return round(self.call_minutes / self.calls_handled, 2) if self.calls_handled else 0.0

    @property
    def total_interactions(self) -> int:
        return self.emails_sent + self.calls_handled

    @property
    def escalation_rate(self) -> float:
        return round(self.escalations / self.ticket_count, 3) if self.ticket_count else 0.0

    @property
    def fcr_rate(self) -> float:
        return round(self.first_contact_resolved / self.ticket_count, 3) if self.ticket_count else 0.0

    @property
    def avg_csat(self) -> float | None:
        return round(mean(self.csat_scores), 2) if self.csat_scores else None


def aggregate(interactions: list[Interaction]) -> list[DailyStat]:
    """Roll interactions up to one ``DailyStat`` per (employee, day)."""

    buckets: dict[tuple[date, str], DailyStat] = {}
    # track distinct tickets per bucket so we don't double count multi-touch cases
    seen_tickets: dict[tuple[date, str], set[str]] = defaultdict(set)

    for it in interactions:
        key = (it.day, it.employee_id)
        stat = buckets.get(key)
        if stat is None:
            stat = DailyStat(day=it.day, employee_id=it.employee_id, employee_name=it.employee_name)
            buckets[key] = stat

        if it.channel is Channel.EMAIL:
            if it.direction is Direction.OUTBOUND:
                stat.emails_sent += 1
            else:
                stat.emails_received += 1
        elif it.channel is Channel.CALL:
            stat.calls_handled += 1
            stat.call_minutes = round(stat.call_minutes + it.minutes, 2)
            if it.direction is Direction.OUTBOUND:
                stat.outbound_calls += 1
            elif it.direction is Direction.INBOUND:
                stat.inbound_calls += 1

        # difficulty / quality enrichers
        if it.is_escalation:
            stat.escalations += 1
        if it.reopened:
            stat.reopened_tickets += 1
        if it.is_first_contact_resolution:
            stat.first_contact_resolved += 1
        if it.csat is not None:
            stat.csat_scores.append(it.csat)
        if it.category:
            stat.categories[it.category] = stat.categories.get(it.category, 0) + 1
        if it.ticket_id and it.ticket_id not in seen_tickets[key]:
            seen_tickets[key].add(it.ticket_id)
            stat.ticket_count += 1

    return sorted(buckets.values(), key=lambda s: (s.day, s.employee_name))


def per_employee_totals(stats: list[DailyStat]) -> list[DailyStat]:
    """Collapse the daily rows into one total row per employee (period summary)."""

    totals: dict[str, DailyStat] = {}
    csat_by_emp: dict[str, list[float]] = defaultdict(list)
    for s in stats:
        t = totals.get(s.employee_id)
        if t is None:
            t = DailyStat(day=s.day, employee_id=s.employee_id, employee_name=s.employee_name)
            totals[s.employee_id] = t
        t.emails_sent += s.emails_sent
        t.emails_received += s.emails_received
        t.calls_handled += s.calls_handled
        t.call_minutes = round(t.call_minutes + s.call_minutes, 2)
        t.inbound_calls += s.inbound_calls
        t.outbound_calls += s.outbound_calls
        t.escalations += s.escalations
        t.reopened_tickets += s.reopened_tickets
        t.first_contact_resolved += s.first_contact_resolved
        t.ticket_count += s.ticket_count
        for cat, n in s.categories.items():
            t.categories[cat] = t.categories.get(cat, 0) + n
        csat_by_emp[s.employee_id].extend(s.csat_scores)
    for emp_id, scores in csat_by_emp.items():
        totals[emp_id].csat_scores = scores
    return sorted(totals.values(), key=lambda s: s.employee_name)
