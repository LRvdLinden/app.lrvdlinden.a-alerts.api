#!/usr/bin/env python3
"""Which runtimes the cron environment of the hosting has (node, php, mysql versions). Prints versions only.

Adds one temporary cron line, waits for it to write a small report into the private folder, reads it and removes both.
"""
import json,os,re,ssl,time,urllib.parse,urllib.request
USER=os.environ["VIMEXX_CPANEL_USERNAME"];TOKEN=os.environ["VIMEXX_CPANEL_API_TOKEN"]
H={"Authorization":f"cpanel {USER}:{TOKEN}","Accept":"application/json"};C=ssl.create_default_context();B="https://cpanel04.zxcs.nl:2083"
def uapi(mod,fn,**a):
 with urllib.request.urlopen(urllib.request.Request(f"{B}/execute/{mod}/{fn}?"+urllib.parse.urlencode(a),headers=H),context=C,timeout=30) as r:
  j=json.load(r);return j.get("result",j)
def cp2(mod,fn,**a):
 f={"cpanel_jsonapi_user":USER,"cpanel_jsonapi_apiversion":"2","cpanel_jsonapi_module":mod,"cpanel_jsonapi_func":fn,**a}
 with urllib.request.urlopen(urllib.request.Request(B+"/json-api/cpanel",data=urllib.parse.urlencode(f).encode(),headers=H,method="POST"),context=C,timeout=30) as r:
  return json.load(r).get("cpanelresult",{})
d=uapi("DomainInfo","single_domain_data",domain="api.a-alerts.nl").get("data",{})
root=str(d.get("documentroot",""));home=str(d.get("homedir",""))
if not home:
 m=re.match(r"(/home[^/]*/[^/]+)",root);home=m.group(1) if m else ""
priv=home+"/aalerts-private";out=priv+"/runtime-probe.txt"
cmd=("( echo NODE_PATH=$(command -v node); node -v; for d in /opt/alt/alt-nodejs*/root/usr/bin/node /usr/bin/node /usr/local/bin/node; do [ -x $d ] && echo NODE_BIN $(basename $(dirname $(dirname $(dirname $(dirname $d))))) $($d -v); done; "
     "echo PHP $(/usr/local/bin/php -r 'echo PHP_VERSION;'); /usr/local/bin/php -m | tr '\\n' ' ' | sed 's/^/PHPMOD /'; echo; "
     "echo MYSQLCLI $(mysql --version 2>/dev/null | head -c 120); echo NPROC $(nproc); echo DONE ) > "+out+" 2>&1 # aalerts-runtime-probe")
cp2("Cron","add_line",command=cmd,minute="*",hour="*",day="*",month="*",weekday="*")
text=""
for _ in range(16):
 time.sleep(10)
 r=uapi("Fileman","get_file_content",dir=priv,file="runtime-probe.txt")
 text=str((r.get("data") or {}).get("content","")) if isinstance(r,dict) else ""
 if "DONE" in text:break
for row in cp2("Cron","listcron").get("data",[]) or []:
 if isinstance(row,dict) and "aalerts-runtime-probe" in str(row.get("command","")):
  cp2("Cron","remove_line",line=str(row.get("count")),linekey=str(row.get("linekey","")))
  print("PROBE_CRON_REMOVED")
cp2("Fileman","fileop",op="unlink",sourcefiles=out)
for line in text.splitlines():
 if line.startswith(("NODE","PHP","MYSQL","NPROC","v")):print(line[:600].replace(home,"<home>"))
print("PROBE_DONE" if "DONE" in text else "PROBE_NO_OUTPUT")
