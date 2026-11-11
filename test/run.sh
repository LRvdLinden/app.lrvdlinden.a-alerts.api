#!/usr/bin/env bash
# Tests the gateway against a fake API: php built-in servers on ports 8911 (fake API) and 8912 (gateway).
set -euo pipefail
cd "$(dirname "$0")/.."
php -l web/index.php >/dev/null && php -l web/config.php >/dev/null
php -S 127.0.0.1:8911 test/upstream.php >/dev/null 2>&1 & U=$!
AALERTS_UPSTREAM=http://127.0.0.1:8911 php -S 127.0.0.1:8912 web/index.php >/dev/null 2>&1 & G=$!
trap 'kill $U $G 2>/dev/null' EXIT
sleep 1
G=http://127.0.0.1:8912; fail=0
ok(){ if eval "$2"; then echo "ok   $1"; else echo "FOUT $1"; fail=1; fi; }
r=$(curl -s "$G/v1/meldingen?limit=5&x=a%20b")
ok "GET met query"            '[[ "$r" == *"\"uri\":\"\\/v1\\/meldingen?limit=5&x=a%20b\""* && "$r" == *"\"method\":\"GET\""* ]]'
ok "X-Forwarded-For en -Host" '[[ "$r" == *"\"xff\":\"127.0.0.1\""* && "$r" == *"\"xfh\":\"127.0.0.1:8912\""* ]]'
r=$(curl -s -X POST -H "content-type: application/json" -H "authorization: Bearer geheim" -d '{"a":1}' "$G/v1/devices")
ok "POST met body en Authorization" '[[ "$r" == *"\"method\":\"POST\""* && "$r" == *"Bearer geheim"* && "$r" == *"{\\\"a\\\":1}"* && "$r" == *"application\\/json"* ]]'
h=$(curl -s -D - -o /dev/null "$G/v1/etag")
ok "antwoordheaders komen door" '[[ "${h,,}" == *"etag: \"abc\""* && "${h,,}" == *"access-control-allow-origin: *"* && "${h,,}" == *"x-opgehaald:"* ]]'
ok "304 bij If-None-Match"   '[[ "$(curl -s -o /dev/null -w "%{http_code}" -H "if-none-match: \"abc\"" "$G/v1/etag")" == 304 ]]'
ok "404 blijft 404"          '[[ "$(curl -s -o /dev/null -w "%{http_code}" "$G/v1/fout")" == 404 ]]'
ok "OPTIONS (CORS-preflight)" '[[ "$(curl -s -X OPTIONS "$G/v1/x")" == *"\"method\":\"OPTIONS\""* ]]'
ok "/_gateway"               '[[ "$(curl -s "$G/_gateway")" == *"\"gateway\":\"a-alerts\""* ]]'
ok "504 als de API niet op tijd antwoordt" '[[ "$(curl -s -o /dev/null -w "%{http_code}" -m 40 "$G/v1/traag")" == 504 ]]'
exit $fail
