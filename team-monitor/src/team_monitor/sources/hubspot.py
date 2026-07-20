"""Email data via the HubSpot CRM API.

Two possible sources depending on how the customer-service team works:

* Logged 1-to-1 emails  -> ``/crm/v3/objects/emails`` with ``hubspot_owner_id``
  and ``hs_timestamp``. Direction is in ``hs_email_direction``.
* Shared inbox / Conversations (Service Hub) -> the Conversations API; the
  "sender" is the responding agent. Hook that in ``fetch_conversations`` when you
  confirm that's the channel.

We also expose tickets so difficulty enrichers (category, escalation, reopen)
can be attached. Owner id -> employee is resolved via the config mapping; the
Owners API is used as a fallback to discover names/emails.

Auth: Private App token, header ``Authorization: Bearer <token>``. Scopes:
``crm.objects.contacts.read``, ``sales-email-read`` (emails), ``tickets`` and
``crm.objects.owners.read`` as needed.
"""

from __future__ import annotations

from datetime import date, datetime, timezone
from typing import Any

from ..config import Settings
from ..models import Channel, Direction, Interaction

_BASE = "https://api.hubapi.com"


class HubspotClient:
    def __init__(self, settings: Settings):
        self.settings = settings
        self.token = settings.hubspot_token

    def _headers(self) -> dict[str, str]:
        return {"Authorization": f"Bearer {self.token}", "Content-Type": "application/json"}

    # --- owner discovery -----------------------------------------------------
    def owners(self) -> dict[str, dict]:
        """Return {owner_id: {firstName,lastName,email}} for name resolution."""
        import requests

        out: dict[str, dict] = {}
        after: str | None = None
        while True:
            params = {"limit": 100}
            if after:
                params["after"] = after
            r = requests.get(f"{_BASE}/crm/v3/owners", headers=self._headers(), params=params, timeout=30)
            r.raise_for_status()
            data = r.json()
            for o in data.get("results", []):
                out[str(o["id"])] = o
            after = data.get("paging", {}).get("next", {}).get("after")
            if not after:
                break
        return out

    # --- emails --------------------------------------------------------------
    def fetch_emails(self, start: date, end: date) -> list[Interaction]:
        """Fetch email engagements in the window via the CRM Search API."""
        import requests

        emp_by_key = self.settings.hubspot_index()
        start_ms = _to_ms(start)
        end_ms = _to_ms(end, end_of_day=True)

        results: list[Interaction] = []
        after: str | None = None
        body: dict[str, Any] = {
            "filterGroups": [
                {
                    "filters": [
                        {"propertyName": "hs_timestamp", "operator": "BETWEEN",
                         "value": start_ms, "highValue": end_ms},
                    ]
                }
            ],
            "properties": ["hs_timestamp", "hubspot_owner_id", "hs_email_direction",
                           "hs_email_status", "hs_email_subject"],
            "limit": 100,
            "sorts": [{"propertyName": "hs_timestamp", "direction": "ASCENDING"}],
        }
        while True:
            if after:
                body["after"] = after
            r = requests.post(f"{_BASE}/crm/v3/objects/emails/search",
                              headers=self._headers(), json=body, timeout=30)
            r.raise_for_status()
            data = r.json()
            for obj in data.get("results", []):
                interaction = self._normalize_email(obj, emp_by_key)
                if interaction is not None:
                    results.append(interaction)
            after = data.get("paging", {}).get("next", {}).get("after")
            if not after:
                break
        return results

    def _normalize_email(self, obj: dict, emp_by_key: dict) -> Interaction | None:
        props = obj.get("properties", {})
        owner_id = str(props.get("hubspot_owner_id") or "")
        emp = emp_by_key.get(owner_id)
        if emp is None:
            return None
        ts = _parse_ms(props.get("hs_timestamp"))
        raw_dir = str(props.get("hs_email_direction") or "").upper()
        # HubSpot: EMAIL / INCOMING_EMAIL / FORWARDED_EMAIL. Treat outgoing as sent.
        direction = Direction.INBOUND if "INCOMING" in raw_dir else Direction.OUTBOUND
        return Interaction(
            employee_id=emp.id,
            employee_name=emp.name,
            channel=Channel.EMAIL,
            direction=direction,
            timestamp=ts,
        )

    # --- conversations (shared inbox) placeholder ----------------------------
    def fetch_conversations(self, start: date, end: date) -> list[Interaction]:
        """Shared-inbox path. Wire up once we confirm the team uses Conversations.

        The Conversations API returns threads and messages; count outbound
        messages whose ``senders`` map to an agent owner. Left unimplemented on
        purpose so we don't guess the account's setup.
        """
        raise NotImplementedError(
            "Enable this once we confirm the team works from a shared HubSpot inbox."
        )


def _to_ms(d: date, end_of_day: bool = False) -> int:
    t = datetime(d.year, d.month, d.day, 23, 59, 59 if end_of_day else 0, tzinfo=timezone.utc)
    if not end_of_day:
        t = datetime(d.year, d.month, d.day, tzinfo=timezone.utc)
    return int(t.timestamp() * 1000)


def _parse_ms(value: Any) -> datetime:
    if value is None:
        return datetime.now(timezone.utc)
    try:
        return datetime.fromtimestamp(int(value) / 1000, tz=timezone.utc)
    except (ValueError, TypeError):
        # HubSpot sometimes returns ISO strings
        try:
            return datetime.fromisoformat(str(value).replace("Z", "+00:00"))
        except ValueError:
            return datetime.now(timezone.utc)
