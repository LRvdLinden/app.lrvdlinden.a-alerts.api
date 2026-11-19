#!/usr/bin/env python3
"""Install docs username + bcrypt hash in the cPanel HOME private directory.
Never print, persist in repo, or upload the plaintext password.
"""
import json,os,re,ssl,subprocess,sys,urllib.error,urllib.parse,urllib.request
HOST="https://cpanel04.zxcs.nl:2083"
cp_user=os.getenv("VIMEXX_CPANEL_USERNAME","").strip()
cp_token=os.getenv("VIMEXX_CPANEL_API_TOKEN","").strip()
username=os.getenv("AALERTS_DOCS_USERNAME","").strip()
password=os.getenv("AALERTS_DOCS_PASSWORD","")
if not cp_user or not cp_token:sys.exit("CPANEL_SECRETS_MISSING")
if not username or not password:sys.exit("DOCS_SECRETS_MISSING: AALERTS_DOCS_USERNAME and AALERTS_DOCS_PASSWORD are required")
if not re.fullmatch(r"[A-Za-z0-9._@-]{3,100}",username) or not 8<=len(password)<=512:
 sys.exit("DOCS_CREDENTIAL_POLICY_FAILED: username 3-100 allowed characters, password at least 8")
HEAD={"Authorization":f"cpanel {cp_user}:{cp_token}","Accept":"application/json","User-Agent":"A-Alerts-docs-setup"}
CTX=ssl.create_default_context()
def call(url,form=None):
 data=urllib.parse.urlencode(form).encode() if form is not None else None
 req=urllib.request.Request(url,headers=HEAD,data=data,method="POST" if form is not None else "GET")
 try:
  with urllib.request.urlopen(req,context=CTX,timeout=30) as resp:return json.load(resp)
 except urllib.error.HTTPError as err:
  sys.exit(f"CPANEL_HTTP_{err.code}: cPanel API denied the request; credentials are not logged")
 except Exception as err:
  sys.exit("CPANEL_CONNECT_FAILED: "+type(err).__name__)
result=call(HOST+"/execute/DomainInfo/single_domain_data?domain=api.a-alerts.nl")
obj=result.get("result",result)
if obj.get("status") in (0,False) or obj.get("errors"):sys.exit("CPANEL_DOMAIN_QUERY_FAILED")
domain=obj.get("data")
if isinstance(domain,list):domain=domain[0] if domain else None
if not isinstance(domain,dict):sys.exit("CPANEL_DOMAIN_DATA_MISSING")
root=str(domain.get("documentroot") or domain.get("document_root") or "")
domain_name=str(domain.get("domain") or "")
home=str(domain.get("homedir") or "")
if not home:
 match=re.match(r"^(/home[^/]*/[^/]+)(?:/|$)",root)
 home=match.group(1) if match else ""
if not re.fullmatch(r"/home[^/]*/[^/]+",home) or not root.startswith(home+"/"):
 sys.exit("CPANEL_HOME_NOT_VERIFIED")
if "api.a-alerts.nl" not in root and domain_name!="api.a-alerts.nl":
 sys.exit("API_DOMAIN_NOT_VERIFIED")
hash_result=subprocess.run(["php","-r","echo password_hash(getenv('AALERTS_DOCS_PASSWORD'), PASSWORD_DEFAULT);"],
 env=os.environ,capture_output=True,text=True,check=True)
password_hash=hash_result.stdout
if not password_hash.startswith(("$2y$","$argon2id$")):sys.exit("PHP_PASSWORD_HASH_FAILED")
def phpquote(value):
 return "'"+value.replace("\\","\\\\").replace("'","\\'").replace("\r","").replace("\n","")+"'"
content="<?php\ndeclare(strict_types=1);\nreturn ['username'=>"+phpquote(username)+",'password_hash'=>"+phpquote(password_hash)+"];\n"
form={"cpanel_jsonapi_user":cp_user,"cpanel_jsonapi_apiversion":"2",
 "cpanel_jsonapi_module":"Fileman","cpanel_jsonapi_func":"savefile",
 "path":home+"/aalerts-private","filename":"docs-auth.php","content":content}
saved=call(HOST+"/json-api/cpanel",form).get("cpanelresult",{})
if saved.get("event",{}).get("result")!=1:sys.exit("DOCS_PRIVATE_FILE_SAVE_FAILED")
print("DOCS_CREDENTIALS_INSTALLED: password hash written to verified cPanel private directory; plaintext not logged")
# Verify publicly visible login form, NEVER attempt login or expose the credentials.
probe=urllib.request.Request("https://api.a-alerts.nl/docs/",headers={"User-Agent":"AAlerts-Docs-Check"})
try:
 with urllib.request.urlopen(probe,context=CTX,timeout=25) as response:
  html=response.read(100000).decode("utf-8","replace")
  if response.status!=200 or 'name="username"' not in html or 'name="password"' not in html:
   sys.exit("DOCS_LOGIN_PAGE_NOT_READY")
 print("DOCS_LOGIN_FORM_READY: unauthenticated users see the login form")
except urllib.error.HTTPError as e:sys.exit(f"DOCS_LOGIN_PROBE_HTTP_{e.code}")
