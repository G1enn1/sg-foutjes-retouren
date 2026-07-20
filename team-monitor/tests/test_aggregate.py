"""Tests for the aggregation + metrics core (pure stdlib, no network)."""

from __future__ import annotations

import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src"))

from team_monitor.aggregate import aggregate, per_employee_totals  # noqa: E402
from team_monitor.metrics import difficulty_profiles, weekly_trend  # noqa: E402
from team_monitor.models import Channel, Direction, Interaction  # noqa: E402


def _email(emp, ts, direction=Direction.OUTBOUND, **kw):
    return Interaction(emp, emp.title(), Channel.EMAIL, direction, ts, **kw)


def _call(emp, ts, seconds, direction=Direction.INBOUND, **kw):
    return Interaction(emp, emp.title(), Channel.CALL, direction, ts, duration_seconds=seconds, **kw)


def test_counts_emails_and_calls_per_day():
    ts = datetime(2026, 7, 6, 10, 0)
    interactions = [
        _email("sanne", ts),
        _email("sanne", ts, direction=Direction.INBOUND),   # received, not sent
        _call("sanne", ts, 300),                            # 5 min
        _call("sanne", ts, 120),                            # 2 min
    ]
    stats = aggregate(interactions)
    assert len(stats) == 1
    s = stats[0]
    assert s.emails_sent == 1
    assert s.emails_received == 1
    assert s.calls_handled == 2
    assert s.call_minutes == 7.0
    assert s.avg_call_minutes == 3.5
    assert s.total_interactions == 3  # sent emails + calls


def test_splits_by_employee_and_day():
    interactions = [
        _email("sanne", datetime(2026, 7, 6, 9, 0)),
        _email("tom", datetime(2026, 7, 6, 9, 0)),
        _email("sanne", datetime(2026, 7, 7, 9, 0)),
    ]
    stats = aggregate(interactions)
    assert len(stats) == 3
    totals = per_employee_totals(stats)
    by_name = {t.employee_name: t for t in totals}
    assert by_name["Sanne"].emails_sent == 2
    assert by_name["Tom"].emails_sent == 1


def test_difficulty_weighting_and_ticket_dedup():
    ts = datetime(2026, 7, 6, 10, 0)
    interactions = [
        # same ticket touched twice -> counts as 1 ticket, 2 touches
        _call("sanne", ts, 200, ticket_id="T1", category="garantie", is_escalation=True),
        _email("sanne", ts, ticket_id="T1", category="garantie"),
        _email("sanne", ts, ticket_id="T2", category="adreswijziging",
               is_first_contact_resolution=True),
    ]
    stats = aggregate(interactions)
    assert stats[0].ticket_count == 2
    prof = difficulty_profiles(stats)[0]
    # weights: garantie 3.0 (x2 categorised interactions) + adreswijziging 1.0 (x1) = 7/3
    assert prof.weighted_difficulty == round(7 / 3, 2)
    assert prof.escalation_rate == 0.5   # 1 escalation / 2 tickets
    assert prof.avg_touches_per_ticket is not None


def test_weekly_trend_groups_by_iso_week():
    interactions = [
        _email("sanne", datetime(2026, 7, 6, 9, 0)),   # week 28
        _email("sanne", datetime(2026, 7, 13, 9, 0)),  # week 29
    ]
    stats = aggregate(interactions)
    trend = weekly_trend(stats, "sanne")
    assert len(trend) == 2
    assert trend[0].emails_sent == 1
    assert trend[1].emails_sent == 1


def test_missing_enrichers_degrade_to_none():
    ts = datetime(2026, 7, 6, 10, 0)
    stats = aggregate([_email("tom", ts)])  # no ticket/category
    prof = difficulty_profiles(stats)[0]
    assert prof.weighted_difficulty is None
    assert prof.escalation_rate is None
