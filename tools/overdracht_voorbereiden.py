#!/usr/bin/env python3
"""Prepare the one-time key transfer: a fresh RSA key pair is made on the runner, the private key and a permit that is
valid for 30 minutes go into the private folder on the server, and only the public key is printed."""
import json,os,re,ssl,subprocess,tempfile,time,urllib.parse,urllib.request
U=os.environ["VIMEXX_CPANEL_USERNAME"];T=os.environ["VIMEXX_CPANEL_API_TOKEN"]
H={"Authorization":f"cpanel {U}:{T}","Accept":"application/json"};C=ssl.create_default_context();B="https://cpanel04.zxcs.nl:2083"
with urllib.request.urlopen(urllib.request.Request(B+"/execute/DomainInfo/single_domain_data?domain=api.a-alerts.nl",headers=H),context=C,timeout=30) as r:
 j=json.load(r);d=j.get("result",j).get("data",{})
m=re.match(r"^(/home[^/]*/[^/]+)(?:/|$)",str(d.get("documentroot","")))
if not m:raise SystemExit("HOME_UNKNOWN")
priv=m.group(1)+"/aalerts-private"
with tempfile.TemporaryDirectory() as t:
 subprocess.run(["openssl","genpkey","-algorithm","RSA","-pkeyopt","rsa_keygen_bits:4096","-out",t+"/k.pem"],check=True,capture_output=True)
 pub=subprocess.run(["openssl","pkey","-in",t+"/k.pem","-pubout"],check=True,capture_output=True,text=True).stdout
 key=open(t+"/k.pem").read()
def save(name,content):
 f={"cpanel_jsonapi_user":U,"cpanel_jsonapi_apiversion":"2","cpanel_jsonapi_module":"Fileman","cpanel_jsonapi_func":"savefile","dir":priv,"filename":name,"content":content}
 with urllib.request.urlopen(urllib.request.Request(B+"/json-api/cpanel",data=urllib.parse.urlencode(f).encode(),headers=H,method="POST"),context=C,timeout=30) as r:
  if json.load(r).get("cpanelresult",{}).get("event",{}).get("result")!=1:raise SystemExit("SAVE_FAILED "+name)
save("overdracht-key.pem",key)
save("overdracht-once.php","<?php\nreturn ['tot'=>%d];\n"%(int(time.time())+1800))
print("PERMIT_GELDIG_30_MIN")
print("PUBLIC_KEY_BEGIN");print(pub.strip());print("PUBLIC_KEY_END")
