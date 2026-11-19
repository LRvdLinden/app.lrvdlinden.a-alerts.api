#!/usr/bin/env python3
"""E2E private docs login check. Never logs username, password, CSRF or page contents."""
import http.cookiejar,os,re,ssl,sys,urllib.parse,urllib.request,urllib.error
username=os.getenv("AALERTS_DOCS_USERNAME","")
password=os.getenv("AALERTS_DOCS_PASSWORD","")
if not username or not password:sys.exit("DOCS_TEST_SECRETS_MISSING")
base="https://api.a-alerts.nl"
cookies=http.cookiejar.CookieJar()
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies),urllib.request.HTTPSHandler(context=ssl.create_default_context()))
try:
 with opener.open(urllib.request.Request(base+"/docs/",headers={"Cache-Control":"no-cache"}),timeout=20) as r:
  html=r.read(120000).decode("utf-8","replace")
 csrf=re.search(r'name="csrf" value="([0-9a-f]{48})"',html)
 if not csrf:raise RuntimeError("NO_CSRF_LOGIN_FORM")
 data=urllib.parse.urlencode({"username":username,"password":password,"csrf":csrf.group(1)}).encode()
 req=urllib.request.Request(base+"/docs/login",data=data,method="POST",headers={"Content-Type":"application/x-www-form-urlencoded","Origin":base})
 with opener.open(req,timeout=25) as r:
  body=r.read(200000).decode("utf-8","replace")
 if "Developer Documentation" not in body or 'action="/docs/logout"' not in body:
  raise RuntimeError("LOGIN_FAILED_OR_NO_SESSION")
 print("DOCS_E2E_LOGIN_PASS: authenticated documentation and logout form visible")
except urllib.error.HTTPError as e:sys.exit("DOCS_E2E_HTTP_"+str(e.code))
except Exception as e:sys.exit("DOCS_E2E_FAILED: "+type(e).__name__)
