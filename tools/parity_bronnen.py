#!/usr/bin/env python3
"""Status of the own intake of the warning and news sources (GET /v1/inname, public), compact."""
import json,urllib.request
with urllib.request.urlopen(urllib.request.Request("https://api.a-alerts.nl/v1/inname",headers={"User-Agent":"A-Alerts-parity/1.0"}),timeout=25) as r:
 raw=r.read()
 try:d=json.loads(raw)
 except ValueError:print("GEEN_JSON status",r.status,raw[:300]);raise SystemExit(1)
for naam in ("proef","actief"):
 p=d.get(naam)
 if not p:print(naam.upper(),"geen");continue
 print(naam.upper(),p.get("at"),"duur",p.get("duur_s"),"status",json.dumps(p.get("_status"),ensure_ascii=False))
 for k,v in sorted(p.items()):
  if isinstance(v,dict) and ("gelijk" in v or "verschillen" in v):
   print("  BRON",k,"gelijk",v.get("gelijk",0),"verschillen",v.get("verschillen",0))
   for x in v.get("voorbeelden",[]):print("    VB",json.dumps(x,ensure_ascii=False)[:400])
 for f in p.get("_fout",[]) or []:print("  FOUT",f)
 print("  PAGINAS",json.dumps(p.get("_burgernet_paginas"),ensure_ascii=False)[:300])
print("BRONNEN",json.dumps(d.get("bronnen"),ensure_ascii=False))
