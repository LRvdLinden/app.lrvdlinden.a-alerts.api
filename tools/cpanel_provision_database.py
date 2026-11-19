#!/usr/bin/env python3
"""Create ONLY the A-Alerts API database, restricted user and private PHP config.
Secrets are kept on cPanel, never committed or printed. Does not touch Cloudflare.
"""
import json,os,re,secrets,ssl,sys,urllib.parse,urllib.request,urllib.error
HOST="cpanel04.zxcs.nl"
USER=os.environ.get("VIMEXX_CPANEL_USERNAME","").strip()
TOKEN=os.environ.get("VIMEXX_CPANEL_API_TOKEN","").strip()
if not USER or not TOKEN:print("BLOCKED: required cPanel secrets missing");sys.exit(2)
if not re.fullmatch(r"[a-z][a-z0-9]{0,15}",USER):print("BLOCKED: invalid cPanel account name");sys.exit(2)
CTX=ssl.create_default_context()
HEAD={"Authorization":f"cpanel {USER}:{TOKEN}","Accept":"application/json","User-Agent":"A-Alerts-cPanel-Provision/1.0"}
def cp(module,function,**params):
 url=f"https://{HOST}:2083/execute/{module}/{function}"
 req=urllib.request.Request(url,data=urllib.parse.urlencode(params).encode("utf8") if params else None,headers=HEAD,method="POST" if params else "GET")
 with urllib.request.urlopen(req,context=CTX,timeout=25) as response:
  value=json.load(response)
 data=value.get("result",value)
 if data.get("status") in (0,False) or data.get("errors"):
  raise RuntimeError(module+"/"+function+" rejected request")
 return data.get("data")
def cp2(module,function,**params):
 p={"cpanel_jsonapi_user":USER,"cpanel_jsonapi_apiversion":"2","cpanel_jsonapi_module":module,"cpanel_jsonapi_func":function,**params}
 req=urllib.request.Request(f"https://{HOST}:2083/json-api/cpanel",data=urllib.parse.urlencode(p).encode("utf8"),headers=HEAD,method="POST")
 with urllib.request.urlopen(req,context=CTX,timeout=25) as response:
  value=json.load(response).get("cpanelresult",{})
 if value.get("event",{}).get("result")!=1:raise RuntimeError(module+"/"+function+" rejected request")
 return value.get("data")
def names(entries,keys):
 output=set()
 if isinstance(entries,dict):entries=list(entries.values())
 if not isinstance(entries,list):return output
 for entry in entries:
  if isinstance(entry,str):output.add(entry)
  elif isinstance(entry,dict):
   for key in keys:
    if isinstance(entry.get(key),str):output.add(entry[key]);break
 return output
domain=cp("DomainInfo","single_domain_data",domain="api.a-alerts.nl")
obj=domain[0] if isinstance(domain,list) and domain else domain
if not isinstance(obj,dict):raise RuntimeError("domain info unavailable")
docroot=str(obj.get("documentroot") or obj.get("document_root") or "")
dom=str(obj.get("domain") or obj.get("servername") or "")
m=re.match(r"^(/home[^/]*/[^/]+)(?:/|$)",docroot)
if not m or ("api.a-alerts.nl" not in docroot and dom!="api.a-alerts.nl"):
 raise RuntimeError("Cannot prove isolated api.a-alerts.nl document root")
home=m.group(1)
if home.endswith("/public_html") or home==docroot:raise RuntimeError("Unsafe home path")
db=USER+"_aalapi";mysql_user=USER+"_aalapp"
if len(db)>63 or len(mysql_user)>32:raise RuntimeError("MySQL name limits exceeded")
dbs=names(cp("Mysql","list_databases"),("database","name","db"))
users=names(cp("Mysql","list_users"),("user","name","dbuser"))
if db in dbs or mysql_user in users:
 print("BLOCKED: matching database or DB user exists; refusing to reset credentials")
 sys.exit(3)
pw=secrets.token_urlsafe(36)
read_token=secrets.token_hex(32);admin_token=secrets.token_hex(32)
print("Preflight: isolated API document root confirmed; target names are unused")
cp("Mysql","create_database",name=db)
print("Database created")
cp("Mysql","create_user",name=mysql_user,password=pw)
print("Limited database user created")
cp("Mysql","set_privileges_on_database",user=mysql_user,database=db,privileges="SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX")
print("Privileges assigned only to API database")
# Avoid public_html: home/aalerts-private is outside all domain document roots.
try:cp2("Fileman","mkdir",path=home,name="aalerts-private",permissions="0700")
except Exception:raise RuntimeError("Private config directory could not be created; database was created but has no configured client")
settings={
 "DB_HOST":"localhost","DB_NAME":db,"DB_USER":mysql_user,"DB_PASS":pw,
 "READ_TOKEN":read_token,"ADMIN_TOKEN":admin_token,"INSTALL_ENABLED":"1",
 "REFERENCE_WORKER_URL":"https://p2000-apeldoorn-api.lrvdlinden.workers.dev",
 "P2000_PAGE_API_URL":"","VERKEER_URL":"","VERKEER_TOKEN":"",
 "ADSB_LOL_API_KEY":"",
}
content="<?php\ndeclare(strict_types=1);\nreturn "+json.dumps(settings,ensure_ascii=False).replace("true","true")+";\n"
# JSON object is not valid PHP: generate safely quoted PHP array with json.dumps on every key/value.
entries=",\n".join("  "+json.dumps(k)+" => "+json.dumps(v) for k,v in settings.items())
content="<?php\ndeclare(strict_types=1);\nreturn [\n"+entries+"\n];\n"
cp2("Fileman","savefile",path=home+"/aalerts-private",filename="config.php",content=content)
print("Private PHP configuration saved outside all web document roots")
print("Provisioned database and separate authentication tokens. No token values printed.")
