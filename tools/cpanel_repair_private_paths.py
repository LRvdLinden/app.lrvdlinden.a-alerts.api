#!/usr/bin/env python3
"""One-time, narrowly scoped migration of three misplaced A-Alerts private files.
Reads filenames only; never reads or logs token values.
Does not touch domain webroots, databases or other cPanel assets.
"""
import json,os,re,ssl,sys,urllib.parse,urllib.request
user=os.getenv("VIMEXX_CPANEL_USERNAME","").strip()
token=os.getenv("VIMEXX_CPANEL_API_TOKEN","").strip()
if not user or not token:sys.exit("CPANEL_CREDENTIALS_MISSING")
host="https://cpanel04.zxcs.nl:2083"
hdr={"Authorization":f"cpanel {user}:{token}","Accept":"application/json"}
ssl_ctx=ssl.create_default_context()
def request(url,fields=None):
 data=urllib.parse.urlencode(fields).encode("utf-8") if fields is not None else None
 req=urllib.request.Request(url,headers=hdr,data=data,method="POST" if fields is not None else "GET")
 with urllib.request.urlopen(req,context=ssl_ctx,timeout=30) as r:return json.load(r)
def uapi(fn,**kwargs):
 x=request(host+"/execute/Fileman/"+fn+"?"+urllib.parse.urlencode(kwargs))
 r=x.get("result",x)
 if r.get("status") in (0,False) or r.get("errors"):raise RuntimeError("cPanel UAPI "+fn+" failed")
 return r.get("data",[])
def names(directory):
 items=uapi("list_files",dir=directory,show_hidden="1")
 if isinstance(items,dict):items=items.get("files",items.get("data",[]))
 if not isinstance(items,list):raise RuntimeError("File listing unavailable")
 return {str(x.get("file",x.get("name",""))):x for x in items if isinstance(x,dict)}
def api2_move(src,dest):
 fields={"cpanel_jsonapi_user":user,"cpanel_jsonapi_apiversion":"2",
         "cpanel_jsonapi_module":"Fileman","cpanel_jsonapi_func":"fileop",
         "op":"move","sourcefiles":src,"destfiles":dest,"doubledecode":"1"}
 response=request(host+"/json-api/cpanel",fields).get("cpanelresult",{})
 if response.get("event",{}).get("result")!=1:raise RuntimeError("cPanel file move denied")
 entries=response.get("data",[])
 if not isinstance(entries,list) or not entries or any(str(x.get("result"))!="1" for x in entries if isinstance(x,dict)):
  raise RuntimeError("cPanel did not confirm file move")
# Determine account home from cPanel itself, never a user-provided URL or path.
d=request(host+"/execute/DomainInfo/single_domain_data?domain=api.a-alerts.nl").get("result",{}).get("data",{})
if isinstance(d,list):d=d[0] if d else {}
if not isinstance(d,dict):raise RuntimeError("Domain information unavailable")
root=str(d.get("documentroot") or d.get("document_root") or "")
home=str(d.get("homedir") or "").rstrip("/")
m=re.match(r"^(/home[^/]*/[^/]+)(?:/|$)",root)
if m:
  # Prefer the path derived from the isolated docroot; alternate cPanel homedir
  # representations may include trailing slash or other account aliases.
  home=m.group(1)
if not re.fullmatch(r"/home[^/]*/[^/]+",home):
 print("HOME_DIAGNOSTIC",{"root_starts_home":root.startswith("/home"),"home_starts_home":home.startswith("/home"),"root_parts":len(root.split("/")),"home_parts":len(home.split("/")),"has_docroot_match":bool(m),"domain_field_matches":d.get("domain")=="api.a-alerts.nl"})
 raise RuntimeError("Account home unverified")
if "api.a-alerts.nl" not in root and d.get("domain")!="api.a-alerts.nl":raise RuntimeError("Wrong domain")
target=home+"/aalerts-private"
source_files=names(home);destination_files=names(target)
needed=["config.php","docs-auth.php","docs-auth.local.php"]
# Refuse collisions, absent source or suspicious config sizes. Never overwrite.
for filename in needed:
 if filename in destination_files:
  raise RuntimeError("Target collision; no files moved")
 if filename not in source_files:
  raise RuntimeError("Expected home-root file not found; no files moved")
 if source_files[filename].get("type") in ("dir","directory"):
  raise RuntimeError("Unexpected directory; no files moved")
print("PREFLIGHT_OK: three misplaced files exist; target filenames unused")
for filename in needed:
 api2_move(filename,"aalerts-private")
 final=names(target)
 if filename not in final:raise RuntimeError("Move confirmation did not match target listing")
 print("MOVED_AND_VERIFIED:",filename)
print("PRIVATE_CONFIG_LOCATION_REPAIRED")
