=== Stralend Team Monitor ===
Contributors: stralendgroen
Tags: reporting, voys, hubspot, callcenter, productivity
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

E-mail- (HubSpot) en telefoonstatistieken (Voys Freedom) per medewerker per dag, met moeilijkheids- en progressie-inzichten, in wp-admin.

== Description ==

Stralend Team Monitor verzamelt e-mails (HubSpot CRM API) en telefoongesprekken
(Voys Freedom — webhook of CSV-import) en toont per medewerker per dag hoeveel
e-mails zijn verstuurd en hoeveel telefoontjes/minuten zijn afgehandeld. Aanvullend
worden moeilijkheids- en kwaliteitsindicatoren berekend (gewogen categorie-zwaarte,
touches per ticket, escalatie- en FCR-ratio, CSAT) zodra ticketdata beschikbaar is.

Secrets (HubSpot-token, webhook-sleutel) worden in WP-opties of in wp-config.php
bewaard. Individuele prestatiemonitoring valt onder de AVG — gebruik het als
ontwikkel-/coachtool, transparant naar het team.

== Installation ==

1. Upload de map naar /wp-content/plugins/ of installeer de zip via wp-admin.
2. Activeer de plugin.
3. Ga naar Team Monitor > Instellingen en vul de HubSpot-token en medewerker-mapping in.
4. Zet de Voys Gespreksnotificaties-webhook aan (URL staat op de instellingenpagina) en/of importeer een gesprekkenlijst-export.

== Changelog ==

= 0.1.0 =
* Eerste versie: Voys-webhook + CSV-import, HubSpot e-mailsync, dashboard, CSV-export.
