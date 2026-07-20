"""Core data model.

Everything the tool works with is reduced to a single normalized shape: an
``Interaction``. Whether a row comes from Voys (a phone call) or HubSpot (an
email), it is mapped onto this structure so the aggregation layer never has to
know which system it came from.

Human-facing docs are in Dutch (README); code and docstrings are in English.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, datetime
from enum import Enum


class Channel(str, Enum):
    EMAIL = "email"
    CALL = "call"


class Direction(str, Enum):
    INBOUND = "inbound"
    OUTBOUND = "outbound"
    INTERNAL = "internal"


@dataclass(frozen=True)
class Interaction:
    """One handled interaction: an email sent/received or a phone call.

    The fields after ``duration_seconds`` are optional *enrichers*. They are the
    only way to say anything meaningful about the difficulty of the work (see
    ``metrics.py``). Volume alone (counts, minutes) does not capture difficulty.
    """

    employee_id: str          # canonical employee id (see config mapping)
    employee_name: str
    channel: Channel
    direction: Direction
    timestamp: datetime
    duration_seconds: int = 0  # talk time for calls; 0 for email

    # --- optional difficulty / quality enrichers (from HubSpot tickets etc.) ---
    ticket_id: str | None = None
    category: str | None = None        # e.g. "retour", "garantie", "klacht"
    is_escalation: bool = False        # handed over to a colleague/senior
    is_first_contact_resolution: bool | None = None
    reopened: bool = False
    csat: float | None = None          # customer satisfaction 1-5, if measured

    @property
    def day(self) -> date:
        return self.timestamp.date()

    @property
    def minutes(self) -> float:
        return round(self.duration_seconds / 60.0, 2)
