#!/usr/bin/env python3
"""Read-only verification of A-Alerts private config files; never read or print their content."""
import json,os,ssl,urllib.parse,urllib.request,sys,re
user=os.environ.get("VIMEXX_CPANEL_USERNAME","").strip()
token=os.environ.get("VIMEXX_CPANEL_API_TOKEN","").strip()
if not user or not token:sys.exit("CPANEL_SECRETS_MISSING")
headers={"Authorization":f"cpanel {user}:{token}","Accept":"application/json"}
context=ssl.create_default_context()
def call(module,fn,**params):
 url="https://cpanel04.zxcs.nl:2083/execute/"+module+"/"+fn
 if params:url+="?"+urllib.parse.urlencode(params)
 req=urllib.request.Request(url,headers=headers)
 with urllib.request.urlopen(req,context=context,timeout=25) as response:d=json.load(response)
 r=d.get("result",d)
 return (r.get("status") not in (0,False) and not r.get("errors")),r.get("data")
def list_names(directory):
 try:ok,items=call("Fileman","list_files",dir=directory,show_hidden="1")
 except Exception as e:
  print("FILEMAN_LIST_UNAVAILABLE",type(e).__name__)
  return None
 if not ok:return None
 if isinstance(items,dict):items=items.get("files",items.get("data",[]))
 if not isinstance(items,list):return None
 return {str(item.get("file",item.get("name",""))) for item in items if isinstance(item,dict)}
ok,d=call("DomainInfo","single_domain_data",domain="api.a-alerts.nl")
if not ok:sys.exit("DOMAIN_QUERY_UNAVAILABLE")
if isinstance(d,list):d=d[0] if d else {}
if not isinstance(d,dict):sys.exit("DOMAIN_RESPONSE_UNEXPECTED")
root=str(d.get("documentroot") or d.get("document_root") or "")
home=str(d.get("homedir") or "")
m=re.match(r"^(/home[^/]*/[^/]+)(?:/|$)",root)
if not home and m:home=m.group(1)
if not home or not home.startswith("/home"):sys.exit("HOME_PATH_UNKNOWN")
print("HOME_RESOLVED",bool(home))
# The home derived from the document root (what the repair and deploy tools use) next to cPanel's own homedir.
afgeleid=m.group(1) if m else ""
print("HOME_SAME_AS_DERIVED",afgeleid==home.rstrip("/"),"DERIVED_KNOWN",bool(afgeleid))
for label,directory in [
 ("home_root",home),
 ("derived_private",(afgeleid or home)+"/aalerts-private"),
 ("home_private",home+"/aalerts-private"),
 ("domain_private",root.rstrip("/")+"/aalerts-private"),
 ("api_core",root.rstrip("/")+"/_core")]:
 names=list_names(directory)
 print("CHECK",label,"listable",names is not None,
       "config_exists",bool(names and "config.php" in names),
       "docs_auth_exists",bool(names and "docs-auth.php" in names),
       "local_docs_auth_exists",bool(names and "docs-auth.local.php" in names))
 # Names only, never content: which other private files are there (sources, one-time schema permit).
 print("CHECK",label,"sources_exists",bool(names and "sources.php" in names),"schema_once_exists",bool(names and "schema-once.php" in names),
       "private_dir_exists",bool(names and "aalerts-private" in names),"count",len(names) if names is not None else -1)
