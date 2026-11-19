#!/usr/bin/env python3
"""Install source tokens into private cPanel file, never into GitHub files/logs.
Does not mutate MySQL credentials, Cloudflare or any non-API domain.
"""
import json,os,re,ssl,sys,urllib.parse,urllib.request
u=os.getenv("VIMEXX_CPANEL_USERNAME","")
t=os.getenv("VIMEXX_CPANEL_API_TOKEN","")
if not u or not t:raise SystemExit("CPANEL_AUTH_MISSING")
hdr={"Authorization":f"cpanel {u}:{t}","Accept":"application/json"}
rooturl="https://cpanel04.zxcs.nl:2083"
req=urllib.request.Request(rooturl+"/execute/DomainInfo/single_domain_data?domain=api.a-alerts.nl",headers=hdr)
with urllib.request.urlopen(req,context=ssl.create_default_context(),timeout=30) as resp:
 response=json.load(resp)
res=response.get("result",{})
print("CPANEL_DOMAIN_RESPONSE",{"has_result":bool(res),"status":res.get("status"),"error_count":len(res.get("errors") or []),"data_type":type(res.get("data")).__name__})
d=res.get("data",{})
if isinstance(d,list):d=d[0] if d else {}
root=str(d.get("documentroot","")) if isinstance(d,dict) else ""
m=re.match(r"^(/home[^/]*/[^/]+)(?:/|$)",root)
print("DOCROOT_CHECK",{"has_api_domain":"api.a-alerts.nl" in root,"has_home":root.startswith("/home"),"length":len(root),"keys":sorted(d.keys()) if isinstance(d,dict) else []})
if not m or "api.a-alerts.nl" not in root:raise RuntimeError("ISOLATED_DOCROOT_NOT_PROVEN")
names=["P2000_PAGE_API_URL","VERKEER_URL","VERKEER_TOKEN","ADSB_LOL_API_KEY"]
data={key:os.getenv(key,"").strip() for key in names}
# Never include unconfigured secrets as an empty override of the main config.
data={key:value for key,value in data.items() if value}
if not data:
 print("SOURCE_SECRETS_MISSING: configure at least one named source secret");sys.exit(0)
for key in ("P2000_PAGE_API_URL","VERKEER_URL"):
 if key in data:
  uri=urllib.parse.urlparse(data[key])
  if uri.scheme!="https" or not uri.hostname or uri.username or uri.password:
   raise RuntimeError("INVALID_SOURCE_URL_"+key)
def php_str(v):return "'"+v.replace("\\","\\\\").replace("'","\\'").replace("\r","").replace("\n","")+"'";
content="<?php\ndeclare(strict_types=1);\nreturn [\n"+",\n".join("  "+php_str(k)+" => "+php_str(v) for k,v in data.items())+"\n];\n"
p={"cpanel_jsonapi_user":u,"cpanel_jsonapi_apiversion":"2","cpanel_jsonapi_module":"Fileman",
   "cpanel_jsonapi_func":"savefile","path":m.group(1)+"/aalerts-private","filename":"sources.php","content":content}
req=urllib.request.Request(rooturl+"/json-api/cpanel",data=urllib.parse.urlencode(p).encode(),headers=hdr,method="POST")
with urllib.request.urlopen(req,context=ssl.create_default_context(),timeout=35) as resp:
 result=json.load(resp).get("cpanelresult",{})
if result.get("event",{}).get("result")!=1:raise RuntimeError("PRIVATE_SOURCES_WRITE_FAILED")
print("PRIVATE_SOURCES_WRITTEN",",".join(sorted(data)))
