#!/usr/bin/env python3
"""Compares the gateway (api.a-alerts.nl) with the real API, request by request: status, headers and body must match.
Only reads; requests that would write (visits, devices, views) are sent in a form the API refuses, so nothing is stored.
Usage: python3 test/vergelijk.py [gateway] [api]"""
import sys, json, time, statistics, requests

G = (sys.argv[1] if len(sys.argv) > 1 else "https://api.a-alerts.nl").rstrip("/")
W = (sys.argv[2] if len(sys.argv) > 2 else "https://p2000-apeldoorn-api.lrvdlinden.workers.dev").rstrip("/")
# Headers that belong to the connection or to the server in front, not to the answer of the API.
NEGEER = {"date", "server", "cf-ray", "cf-cache-status", "report-to", "nel", "alt-svc", "server-timing", "content-length",
          "content-encoding", "transfer-encoding", "connection", "keep-alive", "age", "x-powered-by", "upgrade", "priority", "vary"}
s = requests.Session()
s.headers["Accept-Encoding"] = "gzip"
tijden = {"G": [], "W": []}

def vraag(basis, methode, pad, headers=None, body=None):
    t = time.time()
    try:
        r = s.request(methode, basis + pad, headers=headers or {}, data=body, timeout=45, allow_redirects=False)
    except Exception as e:
        return -1, {}, f"{'gateway' if basis == G else 'api'} {methode} {pad}: {type(e).__name__}: {e}".encode()
    tijden["G" if basis == G else "W"].append(time.time() - t)
    h = {k.lower(): v for k, v in r.headers.items() if k.lower() not in NEGEER}
    return r.status_code, h, r.content

def vorm(x):
    if isinstance(x, dict): return {k: vorm(v) for k, v in sorted(x.items())}
    if isinstance(x, list): return [vorm(x[0])] if x else []
    return type(x).__name__ if x is not None else "null"

fouten, regels = 0, []
def test(naam, methode, pad, headers=None, body=None, wisselend=False):
    global fouten
    laatste = None
    for poging in range(5):
        w1 = vraag(W, methode, pad, headers, body); g = vraag(G, methode, pad, headers, body); w2 = vraag(W, methode, pad, headers, body)
        for w in (w1, w2):
            if g[0] == w[0] and g[2] == w[2]:
                hv = {k: (g[1].get(k), w[1].get(k)) for k in set(g[1]) | set(w[1]) if g[1].get(k) != w[1].get(k)}
                # etag/last-modified may differ between two moments; then the body differed too and we would not be here
                if not hv:
                    regels.append(("GELIJK", naam, f"{g[0]}, {len(g[2])} bytes")); return
                laatste = ("HEADERS", hv)
        if laatste is None: laatste = ("INHOUD", (g, w2))
        time.sleep(1.5)
    soort, d = laatste
    if soort == "HEADERS":
        fouten += 1; regels.append(("VERSCHIL", naam, "headers: " + "; ".join(f"{k}: gateway={a!r} api={b!r}" for k, (a, b) in sorted(d.items())))); return
    g, w = d
    if g[0] == w[0] and wisselend:
        # Signed tokens (JWT) carry the time they were made: compare everything in them except those times.
        def jwt(b):
            import base64, re
            uit = []
            for tok in re.findall(rb"[A-Za-z0-9_-]{8,}[.][A-Za-z0-9_-]{8,}[.][A-Za-z0-9_-]{8,}", b):
                d = lambda x: json.loads(base64.urlsafe_b64decode(x + b"=" * (-len(x) % 4)))
                kop, lijf = d(tok.split(b".")[0]), d(tok.split(b".")[1])
                uit.append((kop, {k: v for k, v in lijf.items() if k not in ("iat", "exp", "nbf", "jti")}))
            return uit
        try:
            jg, jw = jwt(g[2]), jwt(w[2])
            if jg and jg == jw and len(g[2]) == len(w[2]):
                hv = {k for k in set(g[1]) | set(w[1]) if g[1].get(k) != w[1].get(k)}
                if not hv: regels.append(("GELIJK*", naam, f"{g[0]}, token met alleen een ander tijdstip; verder gelijk")); return
        except Exception: pass
        try:
            if vorm(json.loads(g[2])) == vorm(json.loads(w[2])):
                hv = {k for k in set(g[1]) | set(w[1]) if g[1].get(k) != w[1].get(k) and k not in ("etag", "last-modified", "x-opgehaald")}
                if not hv: regels.append(("GELIJK*", naam, f"{g[0]}, inhoud verandert per aanvraag; opbouw en headers gelijk")); return
                fouten += 1; regels.append(("VERSCHIL", naam, f"headers: {sorted(hv)}")); return
        except Exception: pass
    fouten += 1
    regels.append(("VERSCHIL", naam, f"status gateway={g[0]} api={w[0]}; lengte gateway={len(g[2])} api={len(w[2])}; begin gateway={g[2][:120]!r} api={w[2][:120]!r}"))

# Compression: every variant a browser or app can ask for must arrive complete and identical after unpacking.
import subprocess
def curl(basis, pad, enc):
    r = subprocess.run(["curl", "-s", "--max-time", "40", "-H", f"Accept-Encoding: {enc}", "-H", "Origin: https://a-alerts.nl", "--compressed" if enc != "identity" else "-N",
                        "-w", "\n%{http_code} %{size_download}", "-D", "/tmp/h.txt", basis + pad], capture_output=True)
    kop = open("/tmp/h.txt", errors="replace").read().lower()
    ce = next((l.split(":", 1)[1].strip() for l in kop.splitlines() if l.startswith("content-encoding:")), "-")
    lijf, _, staart = r.stdout.rpartition(b"\n")
    return r.returncode, staart.decode(), ce, lijf
for enc in ("identity", "gzip", "br", "zstd", "gzip, deflate, br, zstd"):
    for pad in ("/v1/changelog", "/v1/meldingen?limit=500", "/v1/content/privacy"):
        g, w = curl(G, pad, enc), curl(W, pad, enc)
        # the gateway offers gzip (the API also brotli and zstd); asked without compression both answer plain
        gelijk = g[0] == 0 and w[0] == 0 and g[3] == w[3] and (g[2] == "gzip" if "gzip" in enc else g[2] == "-")
        if not gelijk: fouten += 1
        regels.append(("GELIJK" if gelijk else "VERSCHIL", f"Compressie {enc} {pad}", f"gateway: curl={g[0]} {g[1]} enc={g[2]} uitgepakt={len(g[3])}; api: curl={w[0]} {w[1]} enc={w[2]} uitgepakt={len(w[3])}"))

O = {"Origin": "https://a-alerts.nl"}
# ids for the detail pages
ml = requests.get(W + "/v1/meldingen?limit=5", timeout=30).json(); mid = ml[0]["id"] if ml else None
nl = requests.get(W + "/v1/nieuws?limit=20", timeout=30).json(); nid = nl[0]["id"] if nl else None
wl = requests.get(W + "/v1/waarschuwingen", timeout=30).json(); wid = wl[0]["id"] if wl else None
hl = requests.get(W + "/v1/helikopters", timeout=30).json(); hs = (hl.get("helikopters") or hl.get("items") or []) if isinstance(hl, dict) else hl
hid = hs[0].get("id") if hs else None

test("Startpagina /", "GET", "/")
test("Health", "GET", "/health", wisselend=True)
test("Meldingen (500)", "GET", "/v1/meldingen?limit=500", O)
test("Meldingen (5)", "GET", "/v1/meldingen?limit=5", O)
test("Meldingen zonder Origin", "GET", "/v1/meldingen?limit=50")
if mid:
    test("Melding detail", "GET", f"/v1/meldingen/{mid}", O)
    test("Melding routes", "GET", f"/v1/meldingen/{mid}/routes", O, wisselend=True)
    test("Melding weer", "GET", f"/v1/meldingen/{mid}/weather", O, wisselend=True)
test("Melding onbekend (404)", "GET", "/v1/meldingen/bestaat-niet-12345", O)
test("Status", "GET", "/v1/status", O, wisselend=True)
test("Helikopters", "GET", "/v1/helikopters", O, wisselend=True)
if hid:
    test("Helikopter spoor", "GET", f"/v1/helikopters/{hid}/spoor", O, wisselend=True)
    test("Helikopter vluchten", "GET", f"/v1/helikopters/{hid}/vluchten", O, wisselend=True)
test("Verkeer", "GET", "/v1/verkeer", O, wisselend=True)
test("Nieuws", "GET", "/v1/nieuws", O)
test("Nieuws weer (30)", "GET", "/v1/nieuws?soort=weer&limit=30", O)
test("Nieuws politie", "GET", "/v1/nieuws?soort=politie&limit=10", O)
if nid: test("Nieuwsartikel", "GET", f"/v1/nieuws/{nid}", O)
test("Nieuwsartikel onbekend (404)", "GET", "/v1/nieuws/p-000000000000", O)
test("Waarschuwingen", "GET", "/v1/waarschuwingen", O)
test("Waarschuwingen (1 dag)", "GET", "/v1/waarschuwingen?days=1", O)
if wid:
    test("Waarschuwing detail", "GET", "/v1/waarschuwingen/" + requests.utils.quote(wid, safe=""), O)
    test("Waarschuwing weer", "GET", "/v1/waarschuwingen/" + requests.utils.quote(wid, safe="") + "/weather", O, wisselend=True)
test("Locaties", "GET", "/v1/locaties", O)
test("Wat is nieuw", "GET", "/v1/changelog", O)
for c in ("privacy", "cookies", "terms", "disclaimer", "hulp"): test(f"Tekst {c}", "GET", f"/v1/content/{c}", O)
test("Downloads (GET)", "GET", "/v1/downloads", O)
test("Radar info", "GET", "/v1/radar", O, wisselend=True)
test("Radar afbeelding", "GET", "/v1/radar.png", O, wisselend=True)
test("Radar tegel", "GET", "/v1/radar/8/131/84.png", O, wisselend=True)
test("Kaartafbeelding", "GET", "/v1/kaart.png?lat=52.21&lon=5.97&z=13", O)
test("MapKit-token", "GET", "/v1/mapkit-token", O, wisselend=True)
test("MapKit-token zonder Origin", "GET", "/v1/mapkit-token", wisselend=True)
test("Widget-ping zonder site", "GET", "/v1/widget/ping", O)
test("Tekens in de query", "GET", "/v1/nieuws?soort=weer&limit=3&x=a%20b%2Fc%C3%A9&y=1+2", O)
test("Onbekend pad (404)", "GET", "/v1/bestaat-niet", O)
test("Onbekend pad buiten /v1", "GET", "/iets/anders?x=1", O)
test("HEAD status", "HEAD", "/v1/status", O)
test("HEAD nieuws", "HEAD", "/v1/nieuws", O)
test("CORS-preflight GET", "OPTIONS", "/v1/meldingen", {**O, "Access-Control-Request-Method": "GET", "Access-Control-Request-Headers": "content-type"})
test("CORS-preflight POST", "OPTIONS", "/v1/devices", {**O, "Access-Control-Request-Method": "POST", "Access-Control-Request-Headers": "content-type,authorization"})
test("CORS vreemde Origin", "GET", "/v1/nieuws?limit=2", {"Origin": "https://voorbeeld.example"})
test("Beheer zonder inloggen", "GET", "/v1/beheer/me", O)
test("Beheer met fout token", "GET", "/v1/beheer/overview", {**O, "Authorization": "Bearer fout-token-voor-de-test"})
test("Beheer ping zonder inloggen", "GET", "/v1/beheer/ping", O)
test("Toestel registreren: lege body", "POST", "/v1/devices", {**O, "Content-Type": "application/json"}, "")
test("Toestel registreren: geen JSON", "POST", "/v1/devices", {**O, "Content-Type": "application/json"}, "dit is geen json")
test("Toestel registreren: onjuiste gegevens", "POST", "/v1/devices", {**O, "Content-Type": "application/json"}, json.dumps({"token": "x"}))
test("Toestel verwijderen: onjuist token", "DELETE", "/v1/devices/xyz", O)
test("Feedback: lege body", "POST", "/v1/meldingen/bestaat-niet-12345/feedback", {**O, "Content-Type": "application/json"}, "{}")
test("PUT op een GET-pad", "PUT", "/v1/meldingen", {**O, "Content-Type": "application/json"}, "{}")
test("Grote body (200 kB) naar een onbekend pad", "POST", "/v1/bestaat-niet", {**O, "Content-Type": "application/json"}, json.dumps({"x": "a" * 200_000}))
test("If-None-Match", "GET", "/v1/changelog", {**O, "If-None-Match": '"bestaat-niet"'})
test("Accept-Language en User-Agent", "GET", "/v1/nieuws?limit=3", {**O, "Accept-Language": "en-GB", "User-Agent": "A-Alerts/2.0.1 (iPhone; iOS 26.1)"})

print()
for st, naam, d in regels: print(f"R {st:9} {naam}: {d}")
med = lambda x: round(statistics.median(x) * 1000)
print(f"\nR SAMENVATTING {len(regels)} tests, {sum(1 for r in regels if r[0].startswith('GELIJK'))} gelijk, {fouten} met verschil")
print(f"R SNELHEID mediaan gateway {med(tijden['G'])} ms, api {med(tijden['W'])} ms")
# extra headers that only the gateway sends (from the hosting), on one typical answer
r1, r2 = s.get(G + "/v1/changelog", headers=O), s.get(W + "/v1/changelog", headers=O)
g, w = {k.lower(): v for k, v in r1.headers.items()}, {k.lower(): v for k, v in r2.headers.items()}
print("R ALLEEN-GATEWAY " + "; ".join(f"{k}: {g[k]}" for k in sorted(set(g) - set(w))))
print("R ALLEEN-API " + "; ".join(f"{k}: {w[k]}" for k in sorted(set(w) - set(g))))
sys.exit(1 if fouten else 0)
