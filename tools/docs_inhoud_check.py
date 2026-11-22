#!/usr/bin/env python3
"""Logs in on /docs and checks that the new routes and sources are shown. Prints only yes/no per item, never content or secrets."""
import http.cookiejar,os,re,ssl,sys,urllib.parse,urllib.request
u=os.getenv("AALERTS_DOCS_USERNAME","");p=os.getenv("AALERTS_DOCS_PASSWORD","")
if not u or not p:sys.exit("DOCS_TEST_SECRETS_MISSING")
base="https://api.a-alerts.nl"
op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),urllib.request.HTTPSHandler(context=ssl.create_default_context()))
html=op.open(base+"/docs/",timeout=20).read().decode("utf-8","replace")
csrf=re.search(r'name="csrf" value="([0-9a-f]{48})"',html)
if not csrf:sys.exit("NO_CSRF")
body=op.open(urllib.request.Request(base+"/docs/login",data=urllib.parse.urlencode({"username":u,"password":p,"csrf":csrf.group(1)}).encode(),method="POST",headers={"Content-Type":"application/x-www-form-urlencoded","Origin":base}),timeout=25).read().decode("utf-8","replace")
if 'action="/docs/logout"' not in body:sys.exit("LOGIN_FAILED")
pages={"index":body}
for r in ["GET /v1/statistiek","GET /v1/afkortingen","GET /v1/meldingen/{guid}/afbeelding.png","GET /plaats/{naam}","GET /v1/meldingen"]:
  try:pages[r]=op.open(base+"/docs/?route="+urllib.parse.quote(r),timeout=25).read().decode("utf-8","replace")
  except Exception as e:pages[r]="";print("PAGINA_FOUT",r,type(e).__name__)
alles="\n".join(pages.values())
for k in ["/v1/afkortingen","/v1/statistiek","afbeelding.png","/plaats/{naam}","/eenheid/{capcode}","/sitemap-p2000.xml","BRT-achtergrondkaart","zonder afstandsgrens","Hectometerpalen, statistieken en deelafbeeldingen","Sinds 22 november 2026","https://a-alerts.nl/plaats/{naam}","plaats","eenheid"]:
  print("DOCS_BEVAT",repr(k),k in alles)
for r,h in pages.items():print("ROUTEPAGINA",r,len(h)>1000)
