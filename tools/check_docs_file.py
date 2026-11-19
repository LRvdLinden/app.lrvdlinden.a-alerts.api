#!/usr/bin/env python3
"""Read-only check of the private docs-auth file. Never read or print its content."""
import json,os,urllib.parse,urllib.request,urllib.error,ssl
u=os.environ["VIMEXX_CPANEL_USERNAME"];t=os.environ["VIMEXX_CPANEL_API_TOKEN"]
url="https://cpanel04.zxcs.nl:2083/execute/Fileman/list_files?"+urllib.parse.urlencode({"dir":"aalerts-private","show_hidden":1})
req=urllib.request.Request(url,headers={"Authorization":f"cpanel {u}:{t}","Accept":"application/json"})
try:
 with urllib.request.urlopen(req,context=ssl.create_default_context(),timeout=25) as r:
  body=json.load(r)
except urllib.error.HTTPError as e:
 print("PRIVATE_FILE_CHECK_HTTP",e.code);raise SystemExit(1)
data=body.get("result",body)
if data.get("status") in (0,False) or data.get("errors"):
 print("PRIVATE_FILE_CHECK_API_ERROR");raise SystemExit(1)
entries=data.get("data") or []
if isinstance(entries,dict):entries=list(entries.values())
files=set(str(x.get("file") or x.get("name") or "") for x in entries if isinstance(x,dict))
print("PRIVATE_DIRECTORY_LISTED",isinstance(entries,list))
print("DOCS_AUTH_FILE_PRESENT","docs-auth.php" in files)
print("DATABASE_CONFIG_PRESENT","config.php" in files)
print("SOURCES_CONFIG_PRESENT","sources.php" in files)
if "docs-auth.php" not in files:raise SystemExit(2)
