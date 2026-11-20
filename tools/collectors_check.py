#!/usr/bin/env python3
"""Read-only proof that the collectors store real records, via the authenticated docs viewer.

Prints numbers and source names only: never the login, the CSRF value, a token or record contents.
"""
import html,http.cookiejar,json,os,re,ssl,sys,urllib.parse,urllib.request
username=os.getenv("AALERTS_DOCS_USERNAME","");password=os.getenv("AALERTS_DOCS_PASSWORD","")
if not username or not password:sys.exit("DOCS_TEST_SECRETS_MISSING")
base="https://api.a-alerts.nl"
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),urllib.request.HTTPSHandler(context=ssl.create_default_context()))
def post(path,fields):
 req=urllib.request.Request(base+path,data=urllib.parse.urlencode(fields).encode(),method="POST",headers={"Content-Type":"application/x-www-form-urlencoded","Origin":base})
 with opener.open(req,timeout=40) as r:return r.read(4_000_000).decode("utf-8","replace")
def csrf_of(page):
 m=re.search(r'name="csrf" value="([0-9a-f]{48})"',page)
 if not m:sys.exit("NO_CSRF")
 return m.group(1)
with opener.open(base+"/docs/",timeout=20) as r:page=r.read(200000).decode("utf-8","replace")
page=post("/docs/login",{"username":username,"password":password,"csrf":csrf_of(page)})
if 'action="/docs/logout"' not in page:sys.exit("LOGIN_FAILED")
def live(route,limit="100"):
 global page
 page=post("/docs/preview",{"csrf":csrf_of(page),"route":route,"limit":limit})
 part=page.split('class="docs-live-result"',1)
 if len(part)<2:return None,None
 status=re.search(r"<strong>HTTP (\d+)</strong>",part[1])
 body=re.search(r"<pre><code>(.*?)</code></pre>",part[1],re.S)
 data=None
 if body:
  try:data=json.loads(html.unescape(body.group(1)))
  except ValueError:data=None
 return (int(status.group(1)) if status else None),data
failed=False
code,data=live("GET /v1/bronnen")
print("BRONNEN http",code)
for row in (data or {}).get("collectors",[]) if isinstance(data,dict) else []:
 print("COLLECTOR",row.get("source"),"ok",row.get("successful_runs"),"van",row.get("total_runs"),"laatste",row.get("updated_at"))
if code!=200:failed=True
code,data=live("GET /v1/vergelijk")
print("VERGELIJK http",code)
for name,row in ((data or {}).get("vergelijking",{}) if isinstance(data,dict) else {}).items():
 print("AANTAL",name,"eigen",row.get("eigen"),"worker",row.get("worker"),"ontbreekt",row.get("ontbrekend_op_id"))
for route in ("GET /v1/meldingen","GET /v1/nieuws","GET /v1/waarschuwingen","GET /v1/verkeer","GET /v1/helikopters"):
 code,data=live(route)
 items=data.get("data") if isinstance(data,dict) else None
 newest=""
 if isinstance(items,list) and items and isinstance(items[0],dict):
  newest=str(items[0].get("at") or items[0].get("tijd") or items[0].get("timestamp") or "")[:25]
 print("ROUTE",route,"http",code,"records",len(items) if isinstance(items,list) else "?","nieuwste",newest,"eigen" if isinstance(data,dict) and data.get("shadow") else "gateway")
sys.exit(1 if failed else 0)
