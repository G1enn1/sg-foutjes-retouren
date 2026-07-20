# Team-monitor

Een op zichzelf staande tool die per **medewerker per dag** in kaart brengt:

- **hoeveel e-mails** er verstuurd zijn (via **HubSpot**), en
- **hoeveel telefoontjes** met **hoeveel minuten** er zijn afgehandeld (via **Voys**, dat technisch op het **VoIPGRID-platform** draait).

Doel: beter inzicht in welke medewerkers goed presteren, hoeveel progressie ze maken, en — voor zover de data dat toelaat — hoe zwaar het afgehandelde werk was.

> Dit is een aparte plugin/tool binnen de repo (`team-monitor/`). Hij staat los van de rest en heeft z'n eigen afhankelijkheden.

---

## Snelstart (zonder credentials)

De demo draait op gegenereerde voorbeelddata en heeft **geen** externe pakketten of tokens nodig:

```bash
cd team-monitor
PYTHONPATH=src python3 -m team_monitor --demo --formats html,csv
open out/dashboard.html      # of dubbelklik het bestand
```

Je krijgt een `out/dashboard.html` (overzicht met tabellen + grafieken) en `out/per_dag.csv`.

Excel-export erbij? `pip install openpyxl` en voeg `excel` toe aan `--formats`.

---

## Live koppelen aan Voys en HubSpot

### 1. Tokens (in `.env`)

Kopieer `.env.example` naar `.env` en vul in:

| Bron | Waar vandaan |
| --- | --- |
| `VOIPGRID_TOKEN` (+ `VOIPGRID_USER`) | Voys draait op VoIPGRID. Maak in je VoIPGRID-account een **API-token** aan; gebruik je login-e-mail als user. Auth: `Authorization: Token <user>:<token>`. |
| `HUBSPOT_TOKEN` | HubSpot → Instellingen → Integraties → **Private Apps** → nieuwe app met scopes `sales-email-read`, `crm.objects.owners.read` (en `tickets` als je categorieën/moeilijkheid wilt). |

> **Voys/VoIPGRID let op:** het exacte endpoint en de veldnamen van de Call Detail Records verschillen per API-versie/reseller. Er is één plek om dat bij te stellen: `_normalize()` in `src/team_monitor/sources/voipgrid.py`. Draai `python -m team_monitor.sources.voipgrid --probe` om één ruwe record te zien en de veldnamen te controleren. Lukt de API niet, dan kan hetzelfde model gevoed worden vanuit de CSV-export van de Voys-belstatistieken.

### 2. Medewerker-mapping (in `config.yaml`)

De enige echt lastige stap is **identiteit**: Voys kent iemand via een intern nummer, HubSpot via een owner-id. `config.yaml` koppelt beide aan één persoon. Kopieer `config.example.yaml` → `config.yaml` en vul je team in.

Owner-id's niet paraat? De HubSpot-client heeft `HubspotClient.owners()` om ze op te halen.

### 3. Draaien

```bash
set -a; source .env; set +a
PYTHONPATH=src python3 -m team_monitor --config config.yaml \
    --from 2026-07-01 --to 2026-07-19 --formats html,csv,excel
```

### 4. (Optioneel) naar Google Sheets

```bash
pip install gspread google-auth
export GOOGLE_SERVICE_ACCOUNT_JSON=/pad/service-account.json
export GSHEET_ID=<id-uit-de-sheet-url>   # deel de sheet met het service-account
PYTHONPATH=src python3 -m team_monitor --config config.yaml --from ... --to ... --formats gsheet
```

Dagelijks automatisch bijwerken? Zet dit commando in een cron-job (bijv. elke ochtend 07:00).

---

## Wat wordt er gemeten?

### De twee kern-overzichten (waar je om vroeg)
- **E-mails verzonden** per medewerker per dag.
- **Telefoontjes** en **belminuten** (en gemiddelde minuten per gesprek) per medewerker per dag.

### Moeilijkheidsgraad — voor zover eerlijk meetbaar
Puur volume zegt weinig over hoe zwaar het werk was. Deze tool benadert moeilijkheid met *ticket-metadata* — en laat velden bewust op "—" staan als die data (nog) ontbreekt, in plaats van schijnprecisie te tonen:

- **Gewogen zwaarte** — gemiddelde complexiteit van de categorieën die iemand afhandelde (gewichten in `config.yaml`; bijv. garantieclaim zwaarder dan adreswijziging).
- **Touches per ticket** — hoeveel losse handelingen per zaak; meer = complexer.
- **Escalatie-ratio** — hoe vaak een zaak naar een collega/senior ging.
- **FCR (First Contact Resolution)** en **reopen-ratio** — in één keer goed vs. heropend.

Dit vereist dat jullie in HubSpot met **tickets + categorieën** werken. Doen jullie dat nog niet, dan werken de kern-overzichten gewoon, en komen deze kolommen beschikbaar zodra de tickets er zijn.

### Loopbaanprogressie
Bekijk `weekly_trend()` (in `metrics.py`) per medewerker over meerdere weken/maanden. Progressie ziet er zo uit: **volume gelijk/omhoog** terwijl **touches per ticket en escalatie-ratio dalen** en de **gewogen zwaarte stijgt** (iemand pakt zwaardere zaken op) — bij gelijkblijvende of stijgende klanttevredenheid.

---

## Aanbevolen aanvullende metrics (kantoor-/serviceteam)

Combineer volume altijd met **kwaliteit en uitkomst**, anders stuur je onbedoeld op "snel en slordig":

- **Kwaliteit:** CSAT per medewerker (zit al in het model), steekproef-quality-scoring.
- **Snelheid/SLA:** eerste-reactietijd, oplostijd, % binnen afspraak.
- **Bereikbaarheid (uit Voys):** gemiste oproepen, wachttijd klant, service level (% binnen X sec opgenomen).
- **Werkdruk/verdeling:** open backlog per persoon — óók om overbelasting vroeg te zien.
- **Ontwikkeling:** trainingen, nieuwe categorieën die iemand mag oppakken.

---

## Twee belangrijke kanttekeningen

1. **Goodhart's law** — zodra een getal een target wordt, wordt het geoptimaliseerd i.p.v. het werk. Stuur op de combinatie volume + kwaliteit + uitkomst, niet op één teller.
2. **AVG / personeel** — individuele prestatiemonitoring valt onder de AVG. Zorg voor een duidelijk doel (coaching/ontwikkeling), wees er transparant over naar het team, houd het proportioneel, en check of instemming van een OR/personeelsvertegenwoordiging speelt. Gebruik dit als **ontwikkeltool**, niet als surveillance.

---

## Projectstructuur

```
team-monitor/
├── src/team_monitor/
│   ├── models.py          # Interaction: het genormaliseerde datamodel
│   ├── aggregate.py       # -> per medewerker per dag (DailyStat)
│   ├── metrics.py         # moeilijkheid + progressie (weekly_trend)
│   ├── config.py          # medewerker-mapping + tokens uit env
│   ├── sources/
│   │   ├── voipgrid.py    # Voys/VoIPGRID CDR-client (pas _normalize aan)
│   │   ├── hubspot.py     # HubSpot e-mail/owner-client
│   │   └── sample.py      # voorbeelddata voor de demo
│   ├── exporters/         # csv / html / excel / gsheet
│   └── cli.py             # `python -m team_monitor`
└── tests/                 # pure-stdlib tests van de kern
```

## Tests

```bash
pip install pytest && python3 -m pytest team-monitor/tests -q
```

## Status / vervolg

- ✅ Kern, aggregatie, moeilijkheids- en progressie-metrics, 4 exportvormen, demo, tests.
- 🔜 Zodra tokens er zijn: veldnamen van de VoIPGRID-CDR verifiëren (`--probe`) en bevestigen of jullie e-mail via **gelogde 1-op-1 mails** of een **gedeelde inbox/Conversations** loopt (dan de `fetch_conversations`-tak in `hubspot.py` activeren).
