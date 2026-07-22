# Stralend Team Monitor (WordPress-plugin)

E-mail- en telefoonstatistieken **per medewerker per dag**, direct in `wp-admin`:

- **E-mails verzonden** — via de **HubSpot** CRM API (Private App-token).
- **Telefoontjes + belminuten** — via **Voys Freedom**: live met de *Gespreksnotificaties*-webhook, en/of door een gesprekkenlijst-**export** te importeren.
- **Moeilijkheid & kwaliteit** — gewogen categorie-zwaarte, touches per ticket, escalatie- en FCR-ratio, CSAT (zodra er ticket-/categoriedata is).

Alles draait binnen jullie eigen WordPress/WooCommerce-omgeving; secrets blijven op de site (of in `wp-config.php`), niet in een repo.

---

## Installatie

1. Zip de map `stralend-team-monitor/` en upload via **Plugins → Nieuwe plugin → Plugin uploaden**, of plaats de map in `wp-content/plugins/`.
2. **Activeer** de plugin. Bij activatie worden aangemaakt: de databasetabel, de 8 medewerkers (voorgevuld uit de Voys-gebruikerslijst), standaard categorie-gewichten en een webhook-sleutel.
3. Ga naar **Team Monitor → Instellingen** en controleer de medewerker-mapping.

## Configuratie

### Medewerkers (Instellingen)
Per medewerker: naam, e-mail, **toestel (2xx)** en HubSpot owner-id. Het toestelnummer is het 2xx-nummer zoals het in de Voys-export onder **Bestemming** staat (bijv. `210` voor "210/Sandra"). De owner-id's hoef je niet handmatig op te zoeken — zie de knop hieronder.

### HubSpot (schone route: Private App-token)
De HubSpot-*connector* (marketing) kan geen e-mails per medewerker leveren; daarvoor is een **Private App-token** nodig.

1. HubSpot → **Instellingen → Integraties → Private Apps** (staat nu onder *Legacy Apps* → "Ga naar oude apps").
2. Nieuwe app, scopes: `sales-email-read`, `crm.objects.owners.read` (+ `tickets` voor moeilijkheidsgraad).
3. Kopieer het token en zet het bij **Instellingen**, of — veiliger — in `wp-config.php`:
   ```php
   define( 'STM_HUBSPOT_TOKEN', 'pat-eu1-xxxxxxxx' );
   ```
4. Klik op het dashboard **"HubSpot owner-ids koppelen"** — dit matcht medewerkers op e-mail aan hun HubSpot-owner en vult de owner-id's automatisch in.

### Voys Freedom (telefoon)
Twee routes, prima naast elkaar te gebruiken:

**A. Import (historisch / snel starten)**
Exporteer in Voys Freedom de gesprekkenlijst als **CSV** en upload via **Team Monitor → Voys-import**. Kolommen die worden gelezen: `Datum`, `Inkomend / Uitgaand`, `Tijdsduur` (seconden), `Beller`, `Bestemming`. Na afloop zie je hoeveel gesprekken zijn geïmporteerd en hoeveel niet-toegewezen (zie kanttekening).

**B. Webhook (live, automatisch)**
Zet in Voys Freedom **Gespreksnotificaties** aan en wijs die naar de URL op de instellingenpagina:
```
https://<jouw-site>/wp-json/team-monitor/v1/voys?key=<webhook-secret>
```
Elk gesprek wordt dan realtime opgeslagen. Zorg dat de site via HTTPS bereikbaar is.

> **Kanttekening (belangrijk):** in de huidige Voys-export is de kolom `Beller` gemaskeerd ("x"). Daardoor zijn **uitgaande** gesprekken niet aan een persoon te koppelen, en korte belletjes naar de hoofdlijn tellen niet mee. **Inkomende, beantwoorde** gesprekken worden wél betrouwbaar toegewezen via `Bestemming`. De **webhook** bevat het interne toestel per gesprek en lost ook uitgaand op — daarom is die de nette eindsituatie.

---

## Toegang — wie ziet het?

Toegang is dubbel vergrendeld:

1. **Harde ondergrens: alleen beheerders.** Klanten (WooCommerce-accounts) en
   alle andere rollen worden altijd geblokkeerd — ook als ze op wat voor manier
   dan ook de capability zouden hebben. Ze staan bovendien niet in de
   toegangslijst, dus per ongeluk aanvinken kan niet.
2. **Allow-list binnen de beheerders.** Alleen aangevinkte beheerders (standaard
   Cunera en Glenn) zien het **Team Monitor**-menu en de cijfers; overige
   beheerders niet.

Bij activatie krijgen toegang: de beheerder die de plugin installeert, plus
bestaande beheerdersaccounts met een e-mail uit `DEFAULT_ACCESS_EMAILS`.
Beheer de lijst onder **Team Monitor → Instellingen → Toegang**. Raakt de lijst
per ongeluk leeg, dan mogen beheerders er weer in om het opnieuw in te stellen
(geen lock-out).

## Het dashboard

**Team Monitor** (hoofdmenu) toont voor een gekozen periode:
- samenvatting per medewerker: e-mails, telefoontjes, belminuten, gem. minuten/gesprek, **zwaarte**, touches/ticket, escalatie-%, FCR-%, CSAT;
- staafgrafieken (e-mails en belminuten per medewerker);
- een dag-voor-dag matrix (✉ e-mails / ☎ telefoontjes);
- knoppen: HubSpot nu synchroniseren, owner-ids koppelen, **CSV-export**.

HubSpot wordt daarnaast **dagelijks automatisch** gesynchroniseerd via WP-cron (±06:30).

---

## Hoe het werkt (kort)

Alles wordt genormaliseerd naar één tabel `…_stm_interactions` (één rij per e-mail of gesprek). Het dashboard aggregeert daaruit per medewerker per dag. Bronnen: `voys_webhook`, `voys_import`, `hubspot`. Dubbele webhook-leveringen / her-imports worden ontdubbeld via een `dedup_key`.

```
stralend-team-monitor/
├── stralend-team-monitor.php     # plugin header + bootstrap
├── includes/
│   ├── class-stm-db.php          # tabel + insert/fetch (dedup)
│   ├── class-stm-activator.php   # tabel + defaults + cron
│   ├── class-stm-settings.php    # mapping/tokens + instellingenscherm
│   ├── class-stm-metrics.php     # toewijzing + aggregatie + moeilijkheid
│   ├── class-stm-voys-webhook.php# /wp-json/team-monitor/v1/voys
│   ├── class-stm-voys-import.php # CSV-import van de gesprekkenlijst
│   ├── class-stm-hubspot.php     # e-mails per owner + owner-id koppeling
│   ├── class-stm-cron.php        # dagelijkse HubSpot-sync
│   ├── class-stm-admin-dashboard.php
│   └── class-stm-plugin.php      # hooks/menu's
├── admin/css/admin.css
├── uninstall.php                 # verwijdert tabel + opties bij delete
└── tests/test-metrics.php        # standalone logica-test (php tests/test-metrics.php)
```

## Privacy (AVG)
Dit meet individuele prestaties van medewerkers en valt onder de AVG. Gebruik het als **ontwikkel-/coachtool**, wees er transparant over naar het team, houd het proportioneel en betrek waar nodig een OR/personeelsvertegenwoordiging. Combineer volume altijd met kwaliteit/uitkomst (zie ook "Goodhart" — sturen op één teller nodigt uit tot gamen).

## Tests
```bash
php tests/test-metrics.php
```
Test de toewijzing (2xx/naam), ontdubbeling en gewogen zwaarte tegen het echte Voys-formaat, zonder WordPress.
