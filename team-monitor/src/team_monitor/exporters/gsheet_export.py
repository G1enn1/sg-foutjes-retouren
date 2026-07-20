"""Google Sheets export (optional — requires gspread + a service account).

Writes the daily and summary tables to a Google Sheet so the whole team can view
and filter live. Set env vars:

    GOOGLE_SERVICE_ACCOUNT_JSON = /path/to/service-account.json
    GSHEET_ID                   = <spreadsheet id from its URL>

Share the target spreadsheet with the service account's client_email (editor).
"""

from __future__ import annotations

import os

from ..aggregate import DailyStat, per_employee_totals
from ..metrics import difficulty_profiles


def write_gsheet(stats: list[DailyStat], sheet_id: str | None = None) -> str:
    try:
        import gspread
        from google.oauth2.service_account import Credentials
    except ImportError as exc:  # pragma: no cover
        raise RuntimeError(
            "Google Sheets-export vereist: pip install gspread google-auth"
        ) from exc

    sa_path = os.environ.get("GOOGLE_SERVICE_ACCOUNT_JSON")
    sheet_id = sheet_id or os.environ.get("GSHEET_ID")
    if not sa_path or not sheet_id:
        raise RuntimeError("Zet GOOGLE_SERVICE_ACCOUNT_JSON en GSHEET_ID in je omgeving.")

    scopes = ["https://www.googleapis.com/auth/spreadsheets"]
    creds = Credentials.from_service_account_file(sa_path, scopes=scopes)
    gc = gspread.authorize(creds)
    sh = gc.open_by_key(sheet_id)

    _replace_sheet(sh, "Per dag", _daily_rows(stats))
    _replace_sheet(sh, "Samenvatting", _summary_rows(stats))
    _replace_sheet(sh, "Moeilijkheid", _difficulty_rows(stats))
    return sh.url


def _replace_sheet(sh, title: str, rows: list[list]):
    try:
        ws = sh.worksheet(title)
        ws.clear()
    except Exception:  # worksheet missing
        ws = sh.add_worksheet(title=title, rows=max(len(rows) + 5, 20), cols=max(len(rows[0]) + 2, 10))
    ws.update(rows, value_input_option="USER_ENTERED")


def _daily_rows(stats: list[DailyStat]) -> list[list]:
    rows = [["Datum", "Medewerker", "E-mails verzonden", "Telefoontjes", "Belminuten",
             "Gem. min/gesprek", "Escalaties", "FCR-ratio", "Gem. CSAT", "Tickets"]]
    for s in stats:
        rows.append([s.day.isoformat(), s.employee_name, s.emails_sent, s.calls_handled,
                     s.call_minutes, s.avg_call_minutes, s.escalations, s.fcr_rate,
                     s.avg_csat or "", s.ticket_count])
    return rows


def _summary_rows(stats: list[DailyStat]) -> list[list]:
    rows = [["Medewerker", "E-mails", "Telefoontjes", "Belminuten", "Gem. min/gesprek",
             "Escalatie-ratio", "FCR-ratio", "Gem. CSAT"]]
    for t in per_employee_totals(stats):
        rows.append([t.employee_name, t.emails_sent, t.calls_handled, t.call_minutes,
                     t.avg_call_minutes, t.escalation_rate, t.fcr_rate, t.avg_csat or ""])
    return rows


def _difficulty_rows(stats: list[DailyStat]) -> list[list]:
    rows = [["Medewerker", "Gewogen zwaarte", "Touches/ticket", "Escalatie-ratio",
             "FCR-ratio", "Reopen-ratio", "Gem. CSAT"]]
    for p in difficulty_profiles(stats):
        rows.append([p.employee_name, p.weighted_difficulty or "", p.avg_touches_per_ticket or "",
                     p.escalation_rate or "", p.fcr_rate or "", p.reopen_rate or "", p.avg_csat or ""])
    return rows
