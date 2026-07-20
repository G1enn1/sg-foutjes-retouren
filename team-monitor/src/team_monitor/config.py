"""Configuration loading.

The one genuinely hard integration problem is *identity*: Voys knows a user by
their internal number / VoIPGRID user, HubSpot knows them by owner id / email,
and you want to report on a single person. The employee mapping below is the
bridge. Everything else (tokens) comes from environment variables so no secret
is ever committed.
"""

from __future__ import annotations

import os
from dataclasses import dataclass, field


@dataclass
class Employee:
    id: str                       # canonical id you choose, e.g. "sanne"
    name: str
    voipgrid_user: str | None = None      # VoIPGRID user id or internal number
    hubspot_owner_id: str | None = None
    email: str | None = None

    @property
    def active(self) -> bool:
        return bool(self.voipgrid_user or self.hubspot_owner_id or self.email)


@dataclass
class Settings:
    employees: list[Employee] = field(default_factory=list)
    category_weights: dict[str, float] = field(default_factory=dict)

    # secrets / endpoints — read from env, never from the yaml file
    voipgrid_api_url: str = os.environ.get("VOIPGRID_API_URL", "https://partner.voipgrid.nl")
    voipgrid_user: str = os.environ.get("VOIPGRID_USER", "")
    voipgrid_token: str = os.environ.get("VOIPGRID_TOKEN", "")
    hubspot_token: str = os.environ.get("HUBSPOT_TOKEN", "")

    def voipgrid_index(self) -> dict[str, Employee]:
        return {e.voipgrid_user: e for e in self.employees if e.voipgrid_user}

    def hubspot_index(self) -> dict[str, Employee]:
        idx: dict[str, Employee] = {}
        for e in self.employees:
            if e.hubspot_owner_id:
                idx[str(e.hubspot_owner_id)] = e
            if e.email:
                idx[e.email.lower()] = e
        return idx


def load_settings(path: str | None = None) -> Settings:
    """Load employee mapping + category weights from a YAML file.

    PyYAML is imported lazily so the demo path has zero third-party deps.
    """

    if not path:
        return Settings()
    import yaml  # lazy

    with open(path, "r", encoding="utf-8") as fh:
        raw = yaml.safe_load(fh) or {}

    employees = [
        Employee(
            id=e["id"],
            name=e["name"],
            voipgrid_user=str(e["voipgrid_user"]) if e.get("voipgrid_user") is not None else None,
            hubspot_owner_id=str(e["hubspot_owner_id"]) if e.get("hubspot_owner_id") is not None else None,
            email=e.get("email"),
        )
        for e in raw.get("employees", [])
    ]
    return Settings(
        employees=employees,
        category_weights=raw.get("category_weights", {}) or {},
    )
