#!/usr/bin/env python3
"""Read private config from cPanel and initialize schema through owner-only route.
No credentials, secret values, file contents or account names are logged.
"""
import json,os,re,ssl,sys,urllib.parse,urllib.request,urllib.error
USER=os.getenv("VIMEXX_CPANEL_USERNAME","")
TOKEN=os.getenv("VIMEXX_CPANEL_API_TOKEN","")
if not USER or not TOKEN:raise SystemExit("Missing required cPanel secrets")
API="https://cpanel04.zxcs.nl:2083/execute"
HEAD={"Authorization":f"cpanel {USER}:{TOKEN}","Accept":"application/json"}
def get(endpoint,params):
 req=urllib.request.Request(API+"/"+endpoint+"?"+urllib.parse.urlencode(params),headers=HEAD)
 with urllib.request.urlopen(req,context=ssl.create_default_context(),timeout=25) as response:
  body=json.load(response)
 data=body.get("result",body)
 if data.get("status") in (0,False) or data.get("errors"):
  print("CONFIG_PROBE:",endpoint,"rejected")
  return None
 result=data.get("data")
 print("CONFIG_PROBE:",endpoint,"success; result kind:",type(result).__name__,
       "keys:", sorted(result.keys())[:12] if isinstance(result,dict) else "non-object")
 return result
domain=get("DomainInfo/single_domain_data",{"domain":"api.a-alerts.nl"})
if isinstance(domain,list):domain=domain[0] if domain else None
if not isinstance(domain,dict):raise RuntimeError("Cannot determine API docroot")
docroot=str(domain.get("documentroot") or domain.get("document_root") or "")
home_match=re.match(r"^(/home[^/]*/[^/]+)(?:/|$)",docroot)
if not home_match or "api.a-alerts.nl" not in docroot:raise RuntimeError("Isolated API docroot not confirmed")
root=home_match.group(1)+"/aalerts-private"
results=[
 get("Fileman/get_file_content",{"dir":root,"file":"config.php"}),
 get("Fileman/get_file_content",{"path":root+"/config.php"}),
 get("Fileman/get_file_content",{"file":root+"/config.php"})
]
def extract(item):
 if isinstance(item,str):return item
 if isinstance(item,list):
  return next((x for row in item for x in [extract(row)] if x),None)
 if isinstance(item,dict):
  for key in ("content","file_content","contents","data"):
   if key in item:
    x=extract(item[key])
    if x:return x
 return None
content=next((v for x in results for v in [extract(x)] if v and "ADMIN_TOKEN" in v),None)
if not content:
 print("CONFIG_READ: unavailable; contents never printed")
 sys.exit(3)
print("CONFIG_READ: OK")
key=re.search(r'"ADMIN_TOKEN"\s*=>\s*"([a-f0-9]{64})"',content)
enabled=re.search(r'"INSTALL_ENABLED"\s*=>\s*"([01])"',content)
if not key or not enabled:raise RuntimeError("Private config invalid")
if enabled.group(1)!="1":print("INSTALL: disabled, nothing to do");sys.exit(0)
req=urllib.request.Request("https://api.a-alerts.nl/v1/beheer/install",data=b"{}",method="POST",
 headers={"Authorization":"Bearer "+key.group(1),"Content-Type":"application/json"})
try:
 with urllib.request.urlopen(req,context=ssl.create_default_context(),timeout=30) as r:
  result=json.load(r)
except urllib.error.HTTPError as e:
 print("INSTALL: HTTP",e.code,"(no secret or response body shown)")
 sys.exit(4)
if result.get("ok") is not True:raise RuntimeError("Installer did not report success")
print("INSTALL: tables initialized or already existed =",result.get("created_or_existing_tables"))
# One-time flag is replaced only after the database is verified.
revised=content[:enabled.start(1)]+"0"+content[enabled.end(1):]
p={"cpanel_jsonapi_user":USER,"cpanel_jsonapi_apiversion":"2","cpanel_jsonapi_module":"Fileman",
 "cpanel_jsonapi_func":"savefile","path":root,"filename":"config.php","content":revised}
request=urllib.request.Request("https://cpanel04.zxcs.nl:2083/json-api/cpanel",data=urllib.parse.urlencode(p).encode(),headers=HEAD,method="POST")
with urllib.request.urlopen(request,context=ssl.create_default_context(),timeout=30) as response:
 d=json.load(response).get("cpanelresult",{})
if d.get("event",{}).get("result")!=1:raise RuntimeError("Cannot disable installer after success")
print("INSTALL: permanently disabled in private config")
