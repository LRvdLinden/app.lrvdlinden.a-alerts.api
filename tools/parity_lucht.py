#!/usr/bin/env python3
"""Aircraft, traffic, radar and map image: the answer of the production Worker next to api.a-alerts.nl. Read only.

Live aircraft change from second to second, so for those the fields and the set of aircraft are compared; the stored
history (trail, flights, status) is compared exactly. Never prints secrets.
"""
import json,sys,urllib.request,urllib.error
W="https://p2000-apeldoorn-api.lrvdlinden.workers.dev"; A="https://api.a-alerts.nl"
def get(base,path,raw=False):
 try:
  with urllib.request.urlopen(urllib.request.Request(base+path,headers={"Accept":"application/json","User-Agent":"A-Alerts-parity/1.0"}),timeout=30) as r:
   b=r.read(); return r.status,(b if raw else json.loads(b or b"null")),dict(r.headers)
 except urllib.error.HTTPError as e:
  b=e.read()
  try:return e.code,(b if raw else json.loads(b or b"null")),dict(e.headers)
  except ValueError:return e.code,None,dict(e.headers)
def norm(x):
 if isinstance(x,str):return x.replace(A,W)
 if isinstance(x,list):return [norm(i) for i in x]
 if isinstance(x,dict):return {k:norm(v) for k,v in x.items()}
 return x
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
 elif isinstance(a,float) or isinstance(b,float):
  if a is None or b is None or abs(float(a)-float(b))>1e-6:out.append(pad.rstrip("."))
 elif a!=b:out.append(pad.rstrip("."))
 return out
def velden(lijst):
 s=set()
 for x in lijst or []:s|=set(x.keys())
 return s
def toestellen(path,key):
 sw,x,_=get(W,path);se,y,h=get(A,path)
 x,y=norm(x),norm(y)
 print("ROUTE",path,"worker",sw,"eigen",se,"bron",h.get("X-AAlerts-Bron") or h.get("x-aalerts-bron"))
 if not(isinstance(x,dict) and isinstance(y,dict)):print("  VORM",type(x).__name__,type(y).__name__);return x,y
 print("  sleutels_gelijk",sorted(x)==sorted(y),sorted(set(x)^set(y)))
 lx,ly=x.get(key) or [],y.get(key) or []
 print("  toestel_velden_gelijk",velden(lx)==velden(ly),sorted(velden(lx)^velden(ly)))
 ix={t["id"]:t for t in lx};iy={t["id"]:t for t in ly}
 print("  toestellen worker",len(ix),"eigen",len(iy),"gemeenschappelijk",len(ix.keys()&iy.keys()),"alleen_worker",sorted(ix.keys()-iy.keys())[:8],"alleen_eigen",sorted(iy.keys()-ix.keys())[:8])
 vaste=["naam","soort","basis","herkenning","registratie","type","hex","icoon","medic","plek","plek_soort"]
 af=0
 for k in ix.keys()&iy.keys():
  d=[f for f in vaste if (f in ix[k] or f in iy[k]) and ix[k].get(f)!=iy[k].get(f)]
  if d:af+=1;print("  VAST_VERSCHIL",k,d)
 print("  vaste_velden_afwijkend",af)
 return x,y
x,y=toestellen("/v1/helikopters","helikopters")
if isinstance(x,dict):print("  inzet worker",len(x.get("inzet") or []),"eigen",len((y or {}).get("inzet") or []),"gelijk",norm(x.get("inzet"))==(y or {}).get("inzet"))
toestellen("/v1/lucht","toestellen")
sw,x,_=get(W,"/v1/helikopters/status");se,y,_=get(A,"/v1/helikopters/status")
if isinstance(x,dict) and isinstance(y,dict):
 ix={t["id"]:t for t in x.get("toestellen",[])};iy={t["id"]:t for t in y.get("toestellen",[])}
 print("ROUTE /v1/helikopters/status worker",sw,"eigen",se,"toestellen",len(ix),len(iy),"gemeenschappelijk",len(ix.keys()&iy.keys()),"weer_gelijk",x.get("weer")==y.get("weer"))
 st=sum(1 for k in ix.keys()&iy.keys() if ix[k]["status"]!=iy[k]["status"]);print("  status_afwijkend",st)
else:print("ROUTE /v1/helikopters/status worker",sw,"eigen",se)
ids=[t["id"] for t in (x or {}).get("toestellen",[])][:6] if isinstance(x,dict) else []
for i in ids:
 for p in ("/v1/helikopters/%s/spoor"%i,"/v1/helikopters/%s/spoor?scope=kort"%i,"/v1/helikopters/%s/vluchten"%i):
  sw,a,_=get(W,p);se,b,_=get(A,p);d=diff(norm(a),norm(b))
  print("ROUTE",p.replace(i,"{"+i+"}"),"worker",sw,"eigen",se,"verschillen",len(d),";".join(d[:4]))
sw,x,_=get(W,"/v1/verkeer");se,y,h=get(A,"/v1/verkeer")
d=diff(x,y);print("ROUTE /v1/verkeer worker",sw,"eigen",se,"verschillen",len(d),";".join(d[:6]))
if isinstance(x,dict) and isinstance(y,dict):print("  items",len(x.get("items") or []),len(y.get("items") or []),"flitsers",len(x.get("flitsers") or []),len(y.get("flitsers") or []),"bijgewerkt",x.get("bijgewerkt"),y.get("bijgewerkt"))
sw,x,_=get(W,"/v1/radar");se,y,_=get(A,"/v1/radar");d=diff(norm(x),norm(y))
print("ROUTE /v1/radar worker",sw,"eigen",se,"verschillen",len(d),";".join(d[:4]))
for p in ["/v1/radar.png","/v1/radar/7/65/42.png","/v1/radar/3/1/1.png","/v1/kaart.png?c=52.2112,5.9699&z=14&m=ambulance","/v1/kaart.png?c=49,6&z=10"]:
 sw,a,ha=get(W,p,True);se,b,hb=get(A,p,True)
 ct=lambda h:(h.get("Content-Type") or h.get("content-type") or "")
 print("ROUTE",p,"worker",sw,ct(ha),len(a or b""),"eigen",se,ct(hb),len(b or b""),"png" if (b or b"")[:4]==b"\x89PNG" else "")
