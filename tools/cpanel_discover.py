#!/usr/bin/env python3
"""Discover cPanel capabilities without printing account paths or credentials."""
import json,os,ssl,urllib.parse,urllib.request,urllib.error,sys
host="cpanel04.zxcs.nl"
token=os.environ.get("VIMEXX_CPANEL_API_TOKEN","")
username=os.environ.get("VIMEXX_CPANEL_USERNAME","")
if not token or not username:print("BLOCKED: missing cPanel secrets");sys.exit(2)
headers={"Authorization":f"cpanel {username}:{token}","Accept":"application/json"}
def get(mod,fn,**args):
 url=f"https://{host}:2083/execute/{mod}/{fn}"
 if args:url+="?"+urllib.parse.urlencode(args)
 try:
  with urllib.request.urlopen(urllib.request.Request(url,headers=headers),context=ssl.create_default_context(),timeout=20) as f:
   j=json.load(f)
  return (not j.get("errors") and j.get("status") != 0),j.get("data")
 except Exception as e:return False,None
db,dat=get("Mysql","list_databases")
users,udat=get("Mysql","list_users")
dom,ddat=get("DomainInfo","single_domain_data",domain="api.a-alerts.nl")
cron,_=get("Cron","listcron")
# deliberately do not print any names, paths, IDs or API source response bodies.
def response_shape(x):
 return type(x).__name__
print("DATABASE_LIST:", "OK" if db else "UNAVAILABLE",response_shape(dat))
print("USERS_LIST:", "OK" if users else "UNAVAILABLE",response_shape(udat))
print("API_DOMAIN:", "OK" if dom and isinstance(ddat,(list,dict)) else "UNAVAILABLE",response_shape(ddat))
if dom:
 value=ddat[0] if isinstance(ddat,list) and ddat else ddat
 if isinstance(value,dict):
  docroot=value.get("documentroot") or value.get("document_root") or ""
  print("API_DOCUMENTROOT:", "DISCOVERED" if "api" in str(docroot).lower() and "a-alerts" in str(docroot).lower() else "NEEDS_REVIEW")
print("CRON:", "OK" if cron else "UNAVAILABLE")
# Runtime options (names of features and versions only, never paths).
ok,apps=get("PassengerApps","list_applications")
print("PASSENGER_APPS:", "OK" if ok else "UNAVAILABLE", len(apps) if isinstance(apps,(list,dict)) else "-")
ok,feat=get("Features","list_features")
names=sorted(k for k,v in (feat or {}).items() if v and any(w in k.lower() for w in ("node","python","ruby","passenger","ssh","terminal","shell","cron","php"))) if isinstance(feat,dict) else []
print("FEATURES:", "OK" if ok else "UNAVAILABLE", ",".join(names))
ok,php=get("LangPHP","php_get_installed_versions")
print("PHP_VERSIONS:", "OK" if ok else "UNAVAILABLE", ",".join(php.get("versions",[])) if isinstance(php,dict) else "")
ok,cur=get("LangPHP","php_get_vhost_versions")
if ok and isinstance(cur,list):
 for v in cur:
  if isinstance(v,dict) and "a-alerts" in str(v.get("vhost","")):print("PHP_VHOST", v.get("vhost"), v.get("version"))
ok,res=get("ResourceUsage","get_usages")
if ok and isinstance(res,list):
 for r in res:
  if isinstance(r,dict) and r.get("id") in ("cachedmysqldiskusage","lvecpu","lvememphy","lvenproc","lveep","disk_usage","mysql_databases"):
   print("RESOURCE",r.get("id"),r.get("usage"),"/",r.get("maximum"))
