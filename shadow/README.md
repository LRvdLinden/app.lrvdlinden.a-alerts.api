# A-Alerts private shadow API — work in progress (19 Nov 2026)

**Safety boundary:** this is isolated development on `feature/private-api-v1`, inside `shadow/`. It does NOT replace the existing disabled `web/` proxy and does NOT deploy to Vimexx, Cloudflare, Koyeb, A-Alerts website, or apps. Never merge this branch to main until reviewed: main deploys the old `web/` folder automatically.

## Existing sources (read-only reference repositories)
- `LRvdLinden/p2000-apeldoorn`, branch `a-alerts`: `worker/src/{index,alerts,kind,nieuws,koyeb-feed,verkeer,heli}.js`
- `LRvdLinden/a-alerts_file_verkeer_info`, branch `main`: Koyeb NDW traffic service, `/verkeer` and `/adsb`.

## Built in this branch
- `shadow/src/bootstrap.php`: private disk snapshots and bearer check (no URL tokens).
- `shadow/bin/collect.php`: CLI-only HTTP collectors, source errors and independent Worker snapshots. Currently intentionally basic; **NOT equivalent yet to the Worker parsers**, filtering or deduplication.
- `shadow/public/index.php`: private snapshot reading and count comparison.
- `shadow/public/.htaccess`: proposed routing and authorization forwarding for an isolated Apache document root.

### API contracts — shadow, all require Authorization Bearer
| Endpoint | Output | State |
|---|---|---|
| GET /v1/meldingen?limit=50 | `{data,count,source,updated_at,error,shadow}` | P2000 snapshot only after secret configured |
| GET /v1/waarschuwingen?bron=... | same | aggregate implementation pending; collector snapshots split per source |
| GET /v1/nieuws?soort=... | same | police and 112 subset, NOT full Cloudflare news (KNMI articles/liveblogs/manual items) |
| GET /v1/verkeer | same | existing Koyeb service if token configured |
| GET /v1/helikopters | same | configured ADS-B fetch; provider structure still needs adaptation |
| GET /v1/lucht | alias for helikopters | planned separate payload |
| GET /v1/status | per-source count/update/error | code exists |
| GET /v1/bronnen | alias for status | code exists |
| GET /v1/vergelijk | ID count differences vs Worker snapshots | **NOT** payload/time/field parity |
| GET /v1/nieuws/{id}, /v1/waarschuwingen/{id} | details | **NOT implemented** |
| GET /v1/nieuws/foto/{id} | own licensed photo | **NOT implemented** |
| GET /v1/radar, /v1/helikopters/status | weather/aircraft status | **NOT implemented** |
| GET /v1/vergelijk/rapport | detailed parity report | **NOT implemented** |

### Collector status
- P2000: `P2000_PAGE_API_URL` must be configured independently; GitHub never discloses Actions secret values. The current HTTP collector is unverified.
- Koyeb P2000 fallback: existing Worker uses a **Durable Object WebSocket** with dedicated `KOYEB_SUBPROTOCOL`. No WebSocket in the PHP prototype: use a separately supervised service if needed.
- NL-Alert: `api.public-warning.app/api/v1/providers/nl-alert/alerts`.
- Burgernet actions: `www.burgernet.nl/active`; action detail pages not yet mirrored.
- KNMI: `www.knmi.nl/api/v1/weather-warnings`; polygon/code translation not yet mirrored.
- AMBER/Missing child: `services.burgernet.nl/landactiehost/api/v1/alerts`; alert level and area filtering not yet mirrored.
- Traffic: `VERKEER_URL/verkeer` with `VERKEER_TOKEN` if configured.
- Aircraft: `AALERTS_ADSB_URL` configurable; no filtering/normalization yet.
- Police news: `api.politie.nl/v4/nieuws`; current prototype lacks strict area filtering and original stable SHA-1 IDs; must align with Worker.
- 112 News: `112nieuws.net/tag/{plaats}/feed/`; only permitted short intro/title/link, no external images; partial place list and no area exclusions yet.
- KNMI weather articles + Burgernet/AMBER/Missing Child liveblogs: **NOT implemented** (existing source logic `worker/src/nieuws.js` is reference).

### Configuration (DO NOT COMMIT VALUES)
Set these via server-only env or a file referenced by `AALERTS_PRIVATE_CONFIG` stored outside HTTP document root:
```text
AALERTS_SHADOW_DIR=/absolute/private/path/shadow_data
AALERTS_READ_TOKEN=<random 64 hex characters>
P2000_PAGE_API_URL=<authorized HTTPS URL>
VERKEER_URL=<authorized Koyeb HTTPS origin>
VERKEER_TOKEN=<private upstream token>
AALERTS_REFERENCE_URL=https://p2000-apeldoorn-api.lrvdlinden.workers.dev
AALERTS_ADSB_URL=<approved ADSB HTTPS endpoint>
AALERTS_ADSB_TOKEN=<optional private token>
```
Generate token locally: `php -r 'echo bin2hex(random_bytes(32)),PHP_EOL;'`.
Avoid passing these values in command arguments, browser URLs, test logs, or GitHub files.

### Proposed setup — NOT done
1. Verify Apache isolated document root points exactly to `shadow/public`, never repo root.
2. Ensure `AALERTS_SHADOW_DIR` exists outside document root and is private to PHP OS user.
3. Enable PHP curl + mbstring + SimpleXML extensions.
4. Configure private environment, access logging that **never records Authorization**, HTTPS and rate limits.
5. Run CLI collector through appropriately limited cron, starting with offline fixtures / low rate.
6. Verify unauthorized requests yield 401 and inaccessible source/config paths 403/404.
7. Validate **every** source's schema, geographic scope, deduplication, updates, expiry, error paths and legal restrictions.
8. Independently compare live data snapshots with identical time windows over multiple days, including payload content and edits.
9. Obtain explicit owner approval before any deployment or app/website migration.

### One-to-one criteria
No differences in stable ID mapping, location, title, full message, category, timestamp (with explicit tolerances), event lifecycle, geo filtering and derived news. Record mismatches by field; do not declare parity based on matching counts. One-to-one is **not met** yet.

### Security limitation
The shadow API can be private, but if an equivalent record is already served publicly by the original Worker, this does not make the record globally private. For mobile/website use, never embed this server master secret in distributed client code: add scoped user auth or an application-specific gateway only after separate approval.
