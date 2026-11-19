#!/usr/bin/env python3
"""Deploy ONLY ./web to cPanel-verified api.a-alerts.nl via HTTPS UAPI Fileman."""
import json,os,re,ssl,sys,uuid,urllib.request,urllib.parse,pathlib
USER=os.getenv("VIMEXX_CPANEL_USERNAME","")
TOKEN=os.getenv("VIMEXX_CPANEL_API_TOKEN","")
if not USER or not TOKEN:raise SystemExit("missing cPanel secrets")
ROOT=pathlib.Path("web")
if not ROOT.is_dir():raise SystemExit("missing web directory")
BASE="https://cpanel04.zxcs.nl:2083/execute"
AUTH=f"cpanel {USER}:{TOKEN}"
def api(url,body=None,ctype=None):
 hdr={"Authorization":AUTH,"Accept":"application/json"}
 if ctype:hdr["Content-Type"]=ctype
 req=urllib.request.Request(url,headers=hdr,data=body,method="POST" if body else "GET")
 with urllib.request.urlopen(req,context=ssl.create_default_context(),timeout=35) as resp: obj=json.load(resp)
 d=obj.get("result",obj)
 if d.get("status")==0 or d.get("errors"):raise RuntimeError("cPanel operation returned failure")
 return d.get("data")
domain=api(BASE+"/DomainInfo/single_domain_data?"+urllib.parse.urlencode({"domain":"api.a-alerts.nl"}))
if isinstance(domain,list):domain=domain[0] if domain else {}
if not isinstance(domain,dict):raise RuntimeError("domain info missing")
docroot=str(domain.get("documentroot") or domain.get("document_root") or "")
if not re.match(r"^/home[^/]*/[^/]+/",docroot) or "api.a-alerts.nl" not in docroot or docroot.endswith("public_html"):
 raise RuntimeError("Deployment blocked: isolated API docroot not proven")
files=sorted(x for x in ROOT.rglob("*") if x.is_file() and not x.is_symlink())
if not files or len(files)>30:raise RuntimeError("Unexpected file count")
for path in files:
 rel=path.relative_to(ROOT)
 if any(x in (".git",".github","..") for x in rel.parts):raise RuntimeError("Unsafe path")
 if path.stat().st_size>512000:raise RuntimeError("Unexpectedly large file")
 folder=docroot+("/"+str(rel.parent) if str(rel.parent)!="." else "")
 boundary="----AAlerts"+uuid.uuid4().hex
 payload=(
  f"--{boundary}\r\nContent-Disposition: form-data; name=\"dir\"\r\n\r\n{folder}\r\n"
  f"--{boundary}\r\nContent-Disposition: form-data; name=\"overwrite\"\r\n\r\n1\r\n"
  f"--{boundary}\r\nContent-Disposition: form-data; name=\"file-1\"; filename=\"{path.name}\"\r\n"
  f"Content-Type: application/octet-stream\r\n\r\n").encode()+path.read_bytes()+f"\r\n--{boundary}--\r\n".encode()
 api(BASE+"/Fileman/upload_files",payload,"multipart/form-data; boundary="+boundary)
 print("Uploaded:",str(rel))
print("Deployment: all API files submitted via HTTPS cPanel UAPI")
