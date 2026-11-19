# Private A-Alerts API v1 — isolated prototype
This directory is a **prototype**, NOT the currently deployed gateway. It must not be uploaded over the existing `web/` folder without a separate migration and review. The existing repository's main branch automatically deploys `web/` to Vimexx; this feature branch does not trigger that main-only deployment.

## What exists
- `public/index.php`: private read-only REST endpoints `GET /v1/meldingen?bron=p2000&limit=50` and `GET /v1/status`.
- `src/common.php`: bearer-token validation and PDO connection.
- `bin/poll-p2000.php`: CLI-only polling collector using the provider URL stored server-side.
- `schema.sql`: database schema with deduplication.
- All other sources are **planned**, not implemented. WebSocket fallback is **not connected**.

## Configuration (never commit values)
Set these as server environment variables, or define them with `putenv()` in a PHP config file outside the webroot, referenced by `AALERTS_PRIVATE_CONFIG`:
- `AALERTS_DB_DSN=mysql:host=127.0.0.1;dbname=YOUR_DB;charset=utf8mb4`
- `AALERTS_DB_USER`
- `AALERTS_DB_PASS`
- `AALERTS_READ_TOKEN`: random secret, at least 32 characters; generate a 32-byte token with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`
- `P2000_PAGE_API_URL`: existing private P2000 provider feed URL **not retrievable from GitHub Actions secrets**. Copy manually from authorized configuration and respect provider permissions/rate limits.

Access:
```
curl -H 'Authorization: Bearer YOUR_PRIVATE_TOKEN' 'https://api.a-alerts.nl/v1/meldingen?bron=p2000&limit=20'
```
The token belongs in the Authorization header, **not the URL**. The API returns 401 if token absent/wrong. Only TLS/HTTPS should be used. No public browser CORS or client-secret embedding. If an iOS app/site needs direct browser access, use a separate user-authenticated, scoped API later.

## Deployment prerequisites — not yet performed
1. Verify PHP 8.1+, PDO MySQL, cURL, mbstring, CLI PHP, HTTPS, MySQL storage, and the hosting's outbound rate limits.
2. Create a separate database + least-privilege user, import `schema.sql`.
3. Store secrets outside the docroot. Set the virtual host document root to **only** `prototype/public`, or install a front-controller rewrite in the separate target directory; keep src/bin/schema inaccessible from HTTP.
4. Configure a minutely CLI cronjob invoking `bin/poll-p2000.php`, avoiding overlaps. Use your hosting's actual CLI PHP path.
5. Validate the P2000 provider response shape against real examples (the parser is a generic first pass), timestamps and request quotas. Do not turn on automatic collection before those tests.
6. Add upstream failover WebSocket only after identifying its endpoint, protocol and credentials from a legitimate secret store. **GitHub Actions does not disclose secret values.** Shared hosting is not a reliable permanent WebSocket host; use a supervised collector elsewhere and secure server-to-server ingestion.
7. Run tests, audit retention/logging, monitoring and backups. Cut over apps/website only after approval.

## Security
- Secret not embedded in a URL, not logged, not checked into public GitHub.
- HTTPS, token comparison via hash_equals, DB prepared statements, and no wildcard CORS.
- Web server must not serve sibling source or private config files.
- Do not expose the upstream URL, provider raw data, or administrative status to unauthenticated callers.
- Lock down intake endpoints via separate scoped write credentials / HMAC signature + replay protection.
- Need rate limits at edge, token rotation, error handling, audit logs scrubbed of auth, and database backups for production.
- Anything already public through the live Worker or other channels remains public until those channels are secured separately.
