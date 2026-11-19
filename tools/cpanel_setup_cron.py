#!/usr/bin/env python3
"""Install only A-Alerts API collector cron jobs. Does not modify unrelated crontab entries."""
import json,os,re,ssl,urllib.parse,urllib.request,urllib.error,sys
USER=os.getenv("VIMEXX_CPANEL_USERNAME","")
TOKEN=os.getenv("VIMEXX_CPANEL_API_TOKEN","")
PHP=os.getenv("AALERTS_PHP_BIN","/usr/local/bin/php")
if not USER or not TOKEN:raise SystemExit("CPANEL_SECRETS_MISSING")
if PHP not in ("/usr/local/bin/php","/usr/bin/php"):raise SystemExit("UNAPPROVED_PHP_BINARY")
CTX=ssl.create_default_context()
HEAD={"Authorization":f"cpanel {USER}:{TOKEN}","Accept":"application/json"}
def cp2(mod,fn,**params):
 form={"cpanel_jsonapi_user":USER,"cpanel_jsonapi_apiversion":"2","cpanel_jsonapi_module":mod,"cpanel_jsonapi_func":fn,**params}
 req=urllib.request.Request("https://cpanel04.zxcs.nl:2083/json-api/cpanel",data=urllib.parse.urlencode(form).encode(),headers=HEAD,method="POST")
 with urllib.request.urlopen(req,context=CTX,timeout=30) as resp:raw=json.load(resp).get("cpanelresult",{})
 if raw.get("event",{}).get("result")!=1:raise RuntimeError(mod+"/"+fn+" unavailable")
 return raw.get("data",[])
req=urllib.request.Request("https://cpanel04.zxcs.nl:2083/execute/DomainInfo/single_domain_data?domain=api.a-alerts.nl",headers=HEAD)
with urllib.request.urlopen(req,context=CTX,timeout=25) as resp:
 dom=json.load(resp).get("result",{}).get("data",{})
if isinstance(dom,list):dom=dom[0] if dom else {}
root=str(dom.get("documentroot","")) if isinstance(dom,dict) else ""
if not re.fullmatch(r"/home[^/]+/[^/]+/(?:[A-Za-z0-9_.-]+/)*api\.a-alerts\.nl/?",root) and not ("api.a-alerts.nl" in root and re.fullmatch(r"/home[^/]+/[^/]+/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*",root)):
 raise RuntimeError("ISOLATED_DOCROOT_NOT_PROVEN")
script=root.rstrip("/")+"/_core/collect.php"
known=cp2("Cron","listcron")
if not isinstance(known,list):raise RuntimeError("CRON_LIST_UNEXPECTED")
existing=[str(x.get("command","")) for x in known if isinstance(x,dict)]
jobs=[
 ("p2000","*", "*/1"),("alerts","*", "*/5"),("air","*", "*/5"),
 ("traffic","*", "*/5"),("news","*", "*/15"),("reference","*", "*/15")]
for group,hour,minute in jobs:
 command=PHP+" -q "+script+" "+group+" >/dev/null 2>&1 # aalerts-api-"+group
 if command in existing:
  print("CRON_EXISTS",group)
  continue
 # Stop rather than duplicating a cron that was configured with different binary/path.
 if any("aalerts-api-"+group in x for x in existing):raise RuntimeError("CRON_CONFLICT_"+group)
 result=cp2("Cron","add_line",command=command,day="*",hour=hour,minute=minute,month="*",weekday="*")
 if not isinstance(result,list) or not result or not any(isinstance(x,dict) and str(x.get("status"))=="1" for x in result):
  raise RuntimeError("CRON_CREATE_FAILED_"+group)
 print("CRON_CREATED",group)
print("CRON_SETUP_COMPLETED")
