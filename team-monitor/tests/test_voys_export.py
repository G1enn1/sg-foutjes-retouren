"""Tests for the Voys Freedom call-list export importer."""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src"))

from team_monitor.aggregate import aggregate  # noqa: E402
from team_monitor.config import Employee, Settings  # noqa: E402
from team_monitor.models import Direction  # noqa: E402
from team_monitor.sources.voys_export import parse_export, _parse_duration  # noqa: E402

SAMPLE = Path(__file__).parent / "sample_voys_export.csv"


def _settings() -> Settings:
    return Settings(employees=[
        Employee(id="sanne", name="Sanne de Vries", voipgrid_user="201"),
        Employee(id="tom", name="Tom Bakker", voipgrid_user="202"),
        Employee(id="aisha", name="Aisha El Amrani", voipgrid_user="203"),
    ])


def test_duration_parsing():
    assert _parse_duration("00:04:12") == 252
    assert _parse_duration("07:45") == 465
    assert _parse_duration("90") == 90
    assert _parse_duration("") == 0


def test_attributes_calls_via_extension_and_skips_unmapped():
    interactions = parse_export(SAMPLE, _settings())
    # extension 999 is not mapped -> its row is skipped
    assert len(interactions) == 4
    by_emp = {}
    for it in interactions:
        by_emp.setdefault(it.employee_name, []).append(it)
    assert set(by_emp) == {"Sanne de Vries", "Tom Bakker", "Aisha El Amrani"}


def test_direction_and_minutes():
    interactions = parse_export(SAMPLE, _settings())
    sanne = [i for i in interactions if i.employee_name == "Sanne de Vries"]
    # Sanne (201): one inbound 4:12 and one outbound 2:30
    directions = sorted(i.direction.value for i in sanne)
    assert directions == ["inbound", "outbound"]
    total_minutes = round(sum(i.minutes for i in sanne), 2)
    assert total_minutes == round((252 + 150) / 60, 2)


def test_aggregation_of_export():
    interactions = parse_export(SAMPLE, _settings())
    stats = aggregate(interactions)
    # all calls are on the same day (06-07-2026) -> 3 employee rows
    assert len(stats) == 3
    aisha = next(s for s in stats if s.employee_name == "Aisha El Amrani")
    assert aisha.calls_handled == 1
    assert aisha.call_minutes == round(723 / 60, 2)  # 12:03 = 723s
