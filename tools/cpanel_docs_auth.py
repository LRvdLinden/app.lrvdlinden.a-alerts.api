#!/usr/bin/env python3
"""Configure docs username and password hash exclusively in cPanel private home."""
import json,os,ssl,urllib.parse,urllib.request,subprocess,re,sys
user=os.environ.get("VIMEXX_CPANEL_USERNAME","")
api=os.environ.get("VIMEXX_CPANEL_API_TOKEN","")
docsuser=os.environ.get("AALERTS_DOCS_USERNAME","")
docspass=os.environ.get("AALERTS_DOCS_PASSWORD","")
if not user or not api:raise SystemExit("MISSING_CPANEL_CREDENTIALS")
if not docsuser or not docspass:
 print("DOCS_SECRETS_MISSING: Add AALERTS_DOCS_USERNAME and AALERTS_DOCS_PASSWORD to GitHub Actions secrets, then rerun this workflow.")
 sys.exit(0)
if len(docspass)<8 or len(docspass)>512 or not re.fullmatch("[A-Za-z0-9._@-]{3,100}",docsuser):
 raise SystemExit("DOCS_CREDENTIAL_POLICY_FAILED")
headers={"Authorization":f"cpanel {user}:{api}","Accept":"application/json"}
def call(url,post=None):
 req=urllib.request.Request(url,headers=headers,data=urllib.parse.urlencode(post).encode() if post else None,method="POST" if post else "GET")
 with urllib.request.urlopen(req,context=ssl.create_default_context(),timeout=30) as response:
  return json.load(response)
o=call("https://cpanel04.zxcs.nl:2083/execute/DomainInfo/single_domain_data?domain=api.a-alerts.nl").get("result",{})
d=o.get("data",{})
if isinstance(d,list):d=d[0] if d else {}
root=d.get("documentroot","") if isinstance(d,dict) else ""
m=re.match(r"^(/home[^/]*/[^/]+)(?:/|$)",root)
if not m or "api.a-alerts.nl" not in root:raise SystemExit("API_DOCUMENTROOT_NOT_PROVEN")
password_hash=subprocess.run(["php","-r","echo password_hash(getenv('AALERTS_DOCS_PASSWORD'),PASSWORD_DEFAULT);"],env=os.environ,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=True,check=True).stdout
if not password_hash.startswith("$2y$"):raise SystemExit("PASSWORD_HASH_FAILED")
def php_quote(s):return "'"+s.replace("\\","\\\\").replace("'","\\'")+"'"
content="<?php\ndeclare(strict_types=1);\nreturn ['username'=>"+php_quote(docsuser)+",'password_hash'=>"+php_quote(password_hash)+"];\n"
body={"cpanel_jsonapi_user":user,"cpanel_jsonapi_apiversion":"2","cpanel_jsonapi_module":"Fileman","cpanel_jsonapi_func":"savefile","path":m.group(1)+"/aalerts-private","filename":"docs-auth.php","content":content}
z=call("https://cpanel04.zxcs.nl:2083/json-api/cpanel",body).get("cpanelresult",{})
if z.get("event",{}).get("result")!=1:raise SystemExit("DOCS_CONFIG_WRITE_FAILED")
print("DOCS_AUTH: password hash stored in private cPanel home (no plaintext/password hash in repo).")
