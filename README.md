# A-Alerts API-gateway (api.a-alerts.nl)

Een klein doorgeefluik in PHP voor op de eigen hosting. Alles wat binnenkomt op `api.a-alerts.nl` gaat ongewijzigd door
naar de echte A-Alerts-API (de Cloudflare Worker op `workers.dev`) en het antwoord gaat ongewijzigd terug: status,
headers en inhoud. De gateway bewaart niets, logt niets en bevat geen geheimen.

**Waarom:** sommige netwerken en adblockers blokkeren adressen op `workers.dev`. Via het eigen adres blijft A-Alerts
dan bereikbaar.

**Status: uitgeschakeld (18-11-2026).** De gateway staat uit via `GATEWAY_ACTIEF = false` in `web/config.php` en stuurt geen API-verzoeken door (HTTP 503). De website en apps blijven voorlopig rechtstreeks `workers.dev` gebruiken; er is geen migratie van clients uitgevoerd. De vergelijkingstest (`test/vergelijk.py`, workflow *Vergelijken*) controleert of de gateway dezelfde API-antwoorden teruggeeft.

## Mappen

- `web/`: wat op de hosting staat: `index.php` (de gateway), `config.php` (adres van de echte API, time-out) en `.htaccess`.
- `test/`: `run.sh` test de gateway tegen een nagemaakte API (`upstream.php`).
- `tools/ftps.sh`: de eerder werkende FTPS-verbinding voor dit API-subdomein (met certificaatcontrole). Gewone FTP wordt op deze verbinding momenteel geweigerd.
- `.github/workflows/deploy.yml`: test, upload via FTPS en controle van `https://api.a-alerts.nl/_gateway`.

## Hosting

1. Maak bij de hoster het subdomein `api.a-alerts.nl` aan, met een eigen map en een SSL-certificaat (Let's Encrypt).
2. Repo-secrets: `FTP_SERVER`, `FTP_USER` (of `FTP_USERNAME`), `FTP_PASSWORD`, optioneel `FTP_PORT` en `FTP_DIR`
   (de map van het subdomein, gezien vanaf de FTP-login). Zonder `FTP_DIR`: `public_html` als de login die heeft,
   anders de hoofdmap van de login.
3. Veiligheid: staat in de doelmap de website a-alerts.nl (mappen als `pager`, `nieuws`, `assets`), dan stopt de
   upload zonder iets te wijzigen.

Vereist op de hosting: PHP 8 met cURL en Apache met `mod_rewrite`. De upload van deze afzonderlijke API-repository gebruikt de voor deze hosting eerder succesvol geteste versleutelde FTPS-verbinding.

## Gedrag

- Alle methodes (GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS), query, body en headers gaan door; ook `Authorization`.
- De echte API krijgt `X-Forwarded-For` (adres van de bezoeker), `X-Forwarded-Host` en `X-Forwarded-Proto`.
- Reageert de echte API niet binnen 25 seconden: `504`; is hij onbereikbaar: `502` (JSON met `error`).
- `GET /_gateway`: status van de gateway zelf (`{"ok":true,"gateway":"a-alerts","versie":"1.0.0"}`), zonder de API te raken.

## Testen

```
bash test/run.sh
```

## Later migreren (nog niet gedaan)

1. Controleren dat `https://api.a-alerts.nl/v1/status` hetzelfde geeft als het `workers.dev`-adres.
2. De Worker het adres van de bezoeker uit `X-Forwarded-For` laten gebruiken (alleen voor verzoeken van de gateway),
   zodat limieten per bezoeker blijven werken.
3. Het API-adres omzetten in de website, de widgets, de apps (iOS, Android, Apple TV, Watch), beheer en de Homey-app,
   met het `workers.dev`-adres als reserve.
