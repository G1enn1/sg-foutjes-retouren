"""Self-contained HTML dashboard — no external assets, opens in any browser.

Deliberately restrained: tables plus simple inline-SVG horizontal bars. One
accent hue encodes magnitude; text labels sit on every bar so it is readable
without relying on color. Light/dark aware via prefers-color-scheme.
"""

from __future__ import annotations

import html
from datetime import date
from pathlib import Path

from ..aggregate import DailyStat, per_employee_totals
from ..metrics import DifficultyProfile, difficulty_profiles

_ACCENT = "#2f8f5b"       # Stralend Groen-ish green
_ACCENT_2 = "#3b6ea5"


def _bar(value: float, vmax: float, color: str, label: str) -> str:
    pct = 0 if vmax <= 0 else max(2.0, round(value / vmax * 100, 1))
    return (
        f'<div class="bar-row"><span class="bar-label">{html.escape(label)}</span>'
        f'<span class="bar-track"><span class="bar-fill" style="width:{pct}%;background:{color}">'
        f'</span></span><span class="bar-val">{value:g}</span></div>'
    )


def write_dashboard(stats: list[DailyStat], path: str | Path, title: str = "Team-monitor") -> Path:
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)

    totals = per_employee_totals(stats)
    profiles = {p.employee_id: p for p in difficulty_profiles(stats)}
    days = sorted({s.day for s in stats})
    period = f"{days[0].isoformat()} t/m {days[-1].isoformat()}" if days else "—"

    max_emails = max((t.emails_sent for t in totals), default=1)
    max_minutes = max((t.call_minutes for t in totals), default=1)

    rows_summary = "".join(_summary_row(t, profiles.get(t.employee_id)) for t in totals)
    bars_emails = "".join(_bar(t.emails_sent, max_emails, _ACCENT, t.employee_name) for t in totals)
    bars_minutes = "".join(_bar(t.call_minutes, max_minutes, _ACCENT_2, t.employee_name) for t in totals)
    matrix = _daily_matrix(stats, days)

    doc = _TEMPLATE.format(
        title=html.escape(title),
        period=html.escape(period),
        rows_summary=rows_summary,
        bars_emails=bars_emails,
        bars_minutes=bars_minutes,
        matrix=matrix,
    )
    path.write_text(doc, encoding="utf-8")
    return path


def _fmt(v, suffix: str = "") -> str:
    return "—" if v is None else f"{v}{suffix}"


def _summary_row(t: DailyStat, p: DifficultyProfile | None) -> str:
    diff = _fmt(p.weighted_difficulty) if p else "—"
    touches = _fmt(p.avg_touches_per_ticket) if p else "—"
    esc = _fmt(round(p.escalation_rate * 100, 1), "%") if p and p.escalation_rate is not None else "—"
    fcr = _fmt(round(p.fcr_rate * 100, 1), "%") if p and p.fcr_rate is not None else "—"
    csat = _fmt(t.avg_csat)
    return (
        f"<tr><td class='name'>{html.escape(t.employee_name)}</td>"
        f"<td>{t.emails_sent}</td><td>{t.calls_handled}</td><td>{t.call_minutes:g}</td>"
        f"<td>{t.avg_call_minutes:g}</td><td>{diff}</td><td>{touches}</td>"
        f"<td>{esc}</td><td>{fcr}</td><td>{csat}</td></tr>"
    )


def _daily_matrix(stats: list[DailyStat], days: list[date]) -> str:
    by_emp: dict[str, dict[date, DailyStat]] = {}
    names: dict[str, str] = {}
    for s in stats:
        by_emp.setdefault(s.employee_id, {})[s.day] = s
        names[s.employee_id] = s.employee_name
    head = "".join(f"<th>{d.strftime('%d-%m')}</th>" for d in days)
    body = ""
    for emp_id in sorted(by_emp, key=lambda e: names[e]):
        cells = ""
        for d in days:
            s = by_emp[emp_id].get(d)
            if s:
                cells += f"<td title='{s.call_minutes:g} belmin'>{s.emails_sent}✉ / {s.calls_handled}☎</td>"
            else:
                cells += "<td class='empty'>·</td>"
        body += f"<tr><td class='name'>{html.escape(names[emp_id])}</td>{cells}</tr>"
    return f"<table class='matrix'><thead><tr><th>Medewerker</th>{head}</tr></thead><tbody>{body}</tbody></table>"


_TEMPLATE = """<!doctype html>
<html lang="nl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<style>
  :root {{ --bg:#ffffff; --fg:#1a1a1a; --muted:#6b7280; --card:#f7f8fa; --line:#e5e7eb; }}
  @media (prefers-color-scheme: dark) {{
    :root {{ --bg:#0f1115; --fg:#e6e6e6; --muted:#9aa0aa; --card:#181b21; --line:#2a2f37; }}
  }}
  * {{ box-sizing:border-box; }}
  body {{ margin:0; font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
         background:var(--bg); color:var(--fg); }}
  .wrap {{ max-width:1080px; margin:0 auto; padding:28px 20px 60px; }}
  h1 {{ font-size:24px; margin:0 0 4px; }}
  .period {{ color:var(--muted); margin:0 0 24px; }}
  h2 {{ font-size:17px; margin:32px 0 12px; }}
  .grid {{ display:grid; grid-template-columns:1fr 1fr; gap:24px; }}
  @media (max-width:760px) {{ .grid {{ grid-template-columns:1fr; }} }}
  .card {{ background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px 18px; }}
  table {{ width:100%; border-collapse:collapse; font-size:13.5px; }}
  th, td {{ text-align:right; padding:7px 8px; border-bottom:1px solid var(--line); white-space:nowrap; }}
  th:first-child, td.name {{ text-align:left; }}
  thead th {{ color:var(--muted); font-weight:600; }}
  .scroll {{ overflow-x:auto; }}
  .matrix td.empty {{ color:var(--muted); }}
  .bar-row {{ display:flex; align-items:center; gap:10px; margin:6px 0; }}
  .bar-label {{ width:130px; font-size:13px; color:var(--fg); }}
  .bar-track {{ flex:1; height:14px; background:var(--line); border-radius:7px; overflow:hidden; }}
  .bar-fill {{ display:block; height:100%; border-radius:7px; }}
  .bar-val {{ width:52px; text-align:right; font-variant-numeric:tabular-nums; color:var(--muted); }}
  .note {{ color:var(--muted); font-size:12.5px; margin-top:8px; }}
</style></head>
<body><div class="wrap">
  <h1>{title}</h1>
  <p class="period">Periode: {period}</p>

  <h2>Samenvatting per medewerker</h2>
  <div class="card scroll"><table>
    <thead><tr><th>Medewerker</th><th>E-mails</th><th>Telefoontjes</th><th>Belmin.</th>
      <th>Gem/gesprek</th><th>Zwaarte</th><th>Touches/ticket</th><th>Escalatie</th>
      <th>FCR</th><th>CSAT</th></tr></thead>
    <tbody>{rows_summary}</tbody>
  </table>
  <p class="note">Zwaarte = gewogen gemiddelde complexiteit van categorieën. Touches/ticket, escalatie en FCR zeggen iets over moeilijkheid en kwaliteit — niet het pure volume.</p>
  </div>

  <div class="grid">
    <div><h2>E-mails verzonden (totaal)</h2><div class="card">{bars_emails}</div></div>
    <div><h2>Belminuten (totaal)</h2><div class="card">{bars_minutes}</div></div>
  </div>

  <h2>Per dag — e-mails ✉ / telefoontjes ☎</h2>
  <div class="card scroll">{matrix}</div>
</div></body></html>
"""
