#!/usr/bin/env python3
"""Install idempotent A-Alerts tables through temporary cPanel-home authorization.
The temporary file is private, expires in 5 minutes and is moved to trash afterwards.
"""
import hashlib,json,os,re,secrets,ssl,time,urllib.parse,urllib.request,urllib.error,sys
USER=os.getenv("VIMEXX_CPANEL_USERNAME","")
TOKEN=os.getenv("VIMEXX_CPANEL_API_TOKEN","")
if not USER or not TOKEN:raise SystemExit("CPANEL_SECRETS_MISSING")
HEAD={"Authorization":f"cpanel {USER}:{TOKEN}","Accept":"application/json"}
CTX=ssl.create_default_context()
def call(url,data=None,headers=None):
 req=urllib.request.Request(url,headers=headers or HEAD,data=urllib.parse.urlencode(data).encode() if data is not None else None,method="POST" if data is not None else "GET")
 with urllib.request.urlopen(req,context=CTX,timeout=30) as response:return json.load(response)
def api2(fn,**params):
 form={"cpanel_jsonapi_user":USER,"cpanel_jsonapi_apiversion":"2","cpanel_jsonapi_module":"Fileman","cpanel_jsonapi_func":fn,**params}
 result=call("https://cpanel04.zxcs.nl:2083/json-api/cpanel",form).get("cpanelresult",{})
 if result.get("event",{}).get("result")!=1:raise RuntimeError("Fileman "+fn+" failed")
 return result.get("data")
# UAPI answers with data at the top level ("result" only exists behind some proxies).
_antwoord=call("https://cpanel04.zxcs.nl:2083/execute/DomainInfo/single_domain_data?domain=api.a-alerts.nl")
dom=_antwoord.get("result",_antwoord).get("data",{})
if isinstance(dom,list):dom=dom[0] if dom else {}
root=dom.get("documentroot","") if isinstance(dom,dict) else ""
home=re.match(r"^(/home[^/]*/[^/]+)(?:/|$)",root)
if not home or "api.a-alerts.nl" not in root:raise RuntimeError("Isolated api.a-alerts.nl documentroot not proven")
directory=home.group(1)+"/aalerts-private"
one_time=secrets.token_hex(32)
content="<?php\ndeclare(strict_types=1);\nreturn ['hash'=>'"+hashlib.sha256(one_time.encode()).hexdigest()+"','expires'=>"+str(int(time.time()+300))+"];\n"
# API 2 Fileman::savefile takes "dir" (with "path" the file landed in the account home, which is how the private
# config got misplaced before).
api2("savefile",dir=directory,filename="schema-once.php",content=content)
print("SETUP_GRANT: created short-lived authorization outside web root.")
try:
 req=urllib.request.Request("https://api.a-alerts.nl/v1/beheer/install",data=b"{}",method="POST",headers={"Authorization":"Bearer "+one_time,"Accept":"application/json"})
 try:
  with urllib.request.urlopen(req,context=CTX,timeout=45) as response:
   result=json.load(response)
 except urllib.error.HTTPError as e:
  print("SCHEMA_INSTALL: HTTP",e.code,"(no response body logged)")
  sys.exit(4)
 if result.get("ok") is not True:raise RuntimeError("schema install did not return OK")
 print("SCHEMA_INSTALL: OK, tables processed:",result.get("created_or_existing_tables"))
finally:
 try:
  api2("fileop",op="unlink",sourcefiles=directory+"/schema-once.php",doubledecode="0")
  print("SETUP_GRANT: removed from active private configuration.")
 except Exception:
  print("SETUP_GRANT: cleanup requires review; token expires automatically in 5 minutes.")
