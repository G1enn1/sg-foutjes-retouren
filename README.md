# sg-foutjes-retouren

Inzicht in de prestaties van het kantoor-/klantenserviceteam: **hoeveel e-mails
en telefoontjes (met minuten) per medewerker per dag**, plus indicatoren voor
moeilijkheidsgraad en loopbaanprogressie. Bronnen: **HubSpot** (e-mail) en
**Voys Freedom** (telefoon).

Deze repo bevat twee onderdelen:

| Map | Wat | Status |
| --- | --- | --- |
| **`stralend-team-monitor/`** | **De WordPress/WooCommerce-plugin — dit is het product.** API-code, HubSpot-token en de Voys-webhook draaien veilig binnen de eigen WP-omgeving; dashboard in `wp-admin`. | Actief |
| `team-monitor/` | Python-referentie/prototype met hetzelfde rekenmodel (aggregatie, moeilijkheid, progressie) en een demo-dashboard. Handig als naslag; niet nodig om de plugin te draaien. | Referentie |

Zie **`stralend-team-monitor/README.md`** voor installatie en configuratie.

## Belangrijk (AVG)
Dit meet individuele prestaties van medewerkers. Gebruik het als ontwikkel-/
coachtool, wees transparant naar het team en houd het proportioneel; combineer
volume altijd met kwaliteit en uitkomst.
