#!/usr/bin/env python3
"""Apple, devices, statistics, status and beheer: Worker next to api.a-alerts.nl. Read only toward the Worker; only
requests that are refused (no writes) go to both. Prints status codes and field differences, never tokens."""
import json,sys,urllib.request,urllib.error
W="https://p2000-apeldoorn-api.lrvdlinden.workers.dev"; A="https://api.a-alerts.nl"
def call(base,path,method="GET",body=None,headers=None):
 h={"Accept":"application/json","User-Agent":"A-Alerts-parity/1.0",**(headers or {})}
 data=json.dumps(body).encode() if body is not None else None
 if data is not None:h["Content-Type"]="application/json"
 try:
  with urllib.request.urlopen(urllib.request.Request(base+path,data=data,method=method,headers=h),timeout=30) as r:
   raw=r.read();ct=r.headers.get("Content-Type","")
   return r.status,(json.loads(raw) if "json" in ct and raw else raw.decode("utf-8","replace")),r.headers.get("X-AAlerts-Bron")
 except urllib.error.HTTPError as e:
  raw=e.read()
  try:return e.code,json.loads(raw),e.headers.get("X-AAlerts-Bron")
  except ValueError:return e.code,raw.decode("utf-8","replace")[:120],e.headers.get("X-AAlerts-Bron")
def diff(a,b,pad=""):
 out=[]
 if isinstance(a,dict) and isinstance(b,dict):
  for k in sorted(set(a)|set(b)):
   if k not in a:out.append(pad+k+" (alleen eigen)")
   elif k not in b:out.append(pad+k+" (ontbreekt bij eigen)")
   else:out+=diff(a[k],b[k],pad+k+".")
 elif isinstance(a,list) and isinstance(b,list):
  if len(a)!=len(b):out.append(pad+"lengte %d/%d"%(len(a),len(b)))
  for i,(x,y) in enumerate(zip(a,b)):out+=diff(x,y,pad+"[%d]."%i)
 elif isinstance(a,(int,float)) and isinstance(b,(int,float)) and not isinstance(a,bool):
  if abs(a-b)>1e-6:out.append(pad.rstrip("."))
 elif a!=b:out.append(pad.rstrip("."))
 return out
def same(path,**kw):
 sw,x,_=call(W,path,**kw);se,y,bron=call(A,path,**kw);d=diff(x,y)
 print("ROUTE",kw.get("method","GET"),path,"worker",sw,"eigen",se,"bron",bron,"verschillen",len(d),";".join(d[:6]))
 return x,y
def vorm(path,**kw):
 sw,x,_=call(W,path,**kw);se,y,bron=call(A,path,**kw)
 ks=lambda v:sorted(v) if isinstance(v,dict) else type(v).__name__
 print("VORM",path,"worker",sw,"eigen",se,"bron",bron,"zelfde_velden",ks(x)==ks(y))
 return x,y
# Status: values differ per moment and per server; the shape and the components must match.
x,y=vorm("/v1/status")
if isinstance(x,dict) and isinstance(y,dict):
 for g,h in zip(x.get("groepen",[]),y.get("groepen",[])):
  for o,p in zip(g["onderdelen"],h["onderdelen"]):print("  STATUS",o["id"],"worker",o["staat"],"eigen",p["staat"],"|",p.get("detail"))
# MapKit token: both give a JWT for an allowed site and refuse a foreign one.
for org in ("https://a-alerts.nl","https://example.com"):
 sw,x,_=call(W,"/v1/mapkit-token",headers={"Origin":org});se,y,bron=call(A,"/v1/mapkit-token",headers={"Origin":org})
 print("ROUTE GET /v1/mapkit-token",org,"worker",sw,"eigen",se,"bron",bron,"jwt_vorm",isinstance(y,str) and y.count(".")==2)
same("/v1/widget/ping?site=a-alerts.nl")
# Alerts with a street: routes, building and weather of older (cached) alerts must be equal.
_,lijst,_=call(W,"/v1/meldingen?limit=60")
n=0
for m in [m for m in (lijst or []) if m.get("locatie") and m.get("lat")][:8]:
 g=m["guid"]
 for sub in ("routes","pand","weather"):
  sw,x,_=call(W,"/v1/meldingen/%s/%s"%(g,sub));se,y,bron=call(A,"/v1/meldingen/%s/%s"%(g,sub))
  d=diff(x,y)
  if sub=="routes" and isinstance(x,dict) and isinstance(y,dict):
   ids=lambda v:[(k["id"],k["via"]) for k in v.get("kazernes",[])]
   print("ROUTE /v1/meldingen/{guid}/routes worker",sw,"eigen",se,"zelfde_kazernes",ids(x)==ids(y),"verschillen",len(d),";".join(d[:4]))
  else:print("ROUTE /v1/meldingen/{guid}/%s"%sub,"worker",sw,"eigen",se,"verschillen",len(d),";".join(d[:4]))
_,ws,_=call(W,"/v1/waarschuwingen?days=30&kind=1&vnog=1")
import urllib.parse
for w in (ws or [])[:4]:
 same("/v1/waarschuwingen/%s/weather"%urllib.parse.quote(w["id"],safe=":"))
# Refused requests (nothing is written): the same answer from both.
same("/v1/installs",method="POST",body={})
same("/v1/devices",method="POST",body={"token":"nope"})
same("/v1/beheer/me")
same("/v1/beheer/overview",headers={"Authorization":"Bearer x.y"})
same("/v1/meldingen/onbekende-guid-123/feedback",method="POST",body={"reden":"anders"})
same("/v1/devices/"+"0"*64+"/test",method="POST",body={})
x,y=vorm("/v1/beheer/ping")
print("PING worker",x,"eigen",y)
