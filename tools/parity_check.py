#!/usr/bin/env python3
"""Per ported route: the answer of the production Worker next to that of api.a-alerts.nl. Read only, public routes.

Prints per route whether status and fields match and how many records differ; never prints record contents in full.
"""
import json,sys,urllib.request,urllib.error
W="https://p2000-apeldoorn-api.lrvdlinden.workers.dev"; A="https://api.a-alerts.nl"
def norm(x):
 # Each API points to itself for its own photos: the host is not a difference.
 if isinstance(x,str):return x.replace("https://api.a-alerts.nl",W)
 if isinstance(x,list):return [norm(i) for i in x]
 if isinstance(x,dict):return {k:norm(v) for k,v in x.items()}
 return x
def get(base,path):
 try:
  with urllib.request.urlopen(urllib.request.Request(base+path,headers={"Accept":"application/json","User-Agent":"A-Alerts-parity/1.0"}),timeout=25) as r:
   return r.status,norm(json.loads(r.read() or b"null")),r.headers.get("X-AAlerts-Bron")
 except urllib.error.HTTPError as e:
  try:body=json.loads(e.read() or b"null")
  except ValueError:body=None
  return e.code,body,e.headers.get("X-AAlerts-Bron")
IGNORE={"views"}
def diff(a,b,pad=""):
 out=[]
 if isinstance(a,dict) and isinstance(b,dict):
  for k in sorted(set(a)|set(b)):
   if k in IGNORE:continue
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
def lijst(path,key="guid"):
 sw,a,_=get(W,path);se,b,bron=get(A,path)
 print("ROUTE",path,"worker",sw,"eigen",se,"bron",bron)
 if not(isinstance(a,list) and isinstance(b,list)):print("  VORM worker",type(a).__name__,"eigen",type(b).__name__);return None
 ia={x.get(key):x for x in a if isinstance(x,dict)};ib={x.get(key):x for x in b if isinstance(x,dict)}
 # Only the time window both cover: the own intake started later than the Worker.
 alleen_w=[k for k in ia if k not in ib];alleen_e=[k for k in ib if k not in ia]
 velden={}
 for k in ia.keys()&ib.keys():
  for d in diff(ia[k],ib[k]):velden[d.split("[")[0]]=velden.get(d.split("[")[0],0)+1
 print("  gelijk_op_id",len(ia.keys()&ib.keys()),"alleen_worker",len(alleen_w),"alleen_eigen",len(alleen_e))
 print("  zelfde_volgorde",[x.get(key) for x in a]==[x.get(key) for x in b])
 for f,n in sorted(velden.items(),key=lambda x:-x[1])[:12]:print("  VELD_VERSCHIL",f,n)
 return a
a=lijst("/v1/meldingen?limit=100")
if a:
 g=a[0]["guid"]
 for p in ("/v1/meldingen/"+g,"/v1/meldingen/"+g+"/verloop"):
  sw,x,_=get(W,p);se,y,bron=get(A,p)
  d=diff(x,y);print("ROUTE",p.replace(g,"{guid}"),"worker",sw,"eigen",se,"bron",bron,"verschillen",len(d),";".join(d[:6]))
def los(path):
 sw,x,_=get(W,path);se,y,bron=get(A,path)
 d=diff(x,y);print("ROUTE",path,"worker",sw,"eigen",se,"bron",bron,"verschillen",len(d),";".join(d[:6]))
for p in ["/v1/locaties","/v1/veiligheidsregio","/v1/changelog","/v1/app"]+["/v1/content/"+n for n in ("privacy","cookies","terms","disclaimer","hulp")]:los(p)
for p in ["/v1/waarschuwingen","/v1/waarschuwingen?kind=1&vnog=1","/v1/waarschuwingen?days=30&kind=1&vnog=1"]:los(p)
_,wa,_=get(W,"/v1/waarschuwingen?days=30&kind=1&vnog=1");_,wb,_=get(A,"/v1/waarschuwingen?days=30&kind=1&vnog=1")
print("  VOLGORDE worker",[ (x["id"][:28],x["start"]) for x in (wa or [])[3:9]])
print("  VOLGORDE eigen ",[ (x["id"][:28],x["start"]) for x in (wb or [])[3:9]])
for x,y in zip(wa or [],wb or []):
 if x.get("plaats")!=y.get("plaats"):print("  PLAATS",x["id"][:40],"worker",repr(x.get("plaats"))[:80],"eigen",repr(y.get("plaats"))[:80],"bijgewerkt",x.get("bijgewerkt"),y.get("bijgewerkt"))
print("  IDS gelijk",sorted(x["id"] for x in wa or [])==sorted(x["id"] for x in wb or []))
_,ws,_=get(W,"/v1/waarschuwingen?days=30&kind=1&vnog=1")
for w in (ws or [])[:8]:
 import urllib.parse as _u
 p="/v1/waarschuwingen/"+_u.quote(w["id"],safe=":")
 sw,x,_=get(W,p);se,y,bron=get(A,p);d=diff(x,y)
 print("ROUTE /v1/waarschuwingen/{"+w["bron"]+"}","worker",sw,"eigen",se,"verschillen",len(d),";".join(d[:5]))
 if se!=sw:print("  ID",w["id"][:60],"EIGEN_ANTWOORD",str(y)[:200])
for p in ["/v1/nieuws","/v1/nieuws?limit=100","/v1/nieuws?soort=politie","/v1/nieuws?soort=liveblog","/v1/nieuws?soort=weer","/v1/nieuws?soort=regionaal"]:los(p)
_,ns,_=get(W,"/v1/nieuws?limit=100")
nd=0
for n in (ns or []):
 sw,x,_=get(W,"/v1/nieuws/"+n["id"]);se,y,_=get(A,"/v1/nieuws/"+n["id"]);d=diff(x,y)
 if d or sw!=se:
  nd+=1
  if nd<=6:print("ROUTE /v1/nieuws/{"+n["soort"]+"}",n["id"],"worker",sw,"eigen",se,"verschillen",len(d),";".join(d[:6]))
print("NIEUWS_DETAILS",len(ns or []),"afwijkend",nd)
import json as _j,pathlib
for k in _j.loads(pathlib.Path("web/_core/aa/data/kazernes.json").read_text())[:58]:
 sw,x,_=get(W,"/v1/posten/"+k["id"]);se,y,bron=get(A,"/v1/posten/"+k["id"])
 d=diff(x,y)
 if d or sw!=se:print("ROUTE /v1/posten/"+k["id"],"worker",sw,"eigen",se,"verschillen",len(d),";".join(d[:5]))
 if d:print("  POST",k["id"],"worker",x.get("inzetten"),x.get("laatste"),(x.get("recent") or [])[:2],"| eigen",y.get("inzetten"),y.get("laatste"),(y.get("recent") or [])[:2])
print("POSTEN_KLAAR")
# Root and health: same fields as the Worker; times and counts differ per server.
for p in ("/","/health"):
 sw,x,_=get(W,p);se,y,bron=get(A,p)
 kx=sorted(x) if isinstance(x,dict) else None; ky=sorted(y) if isinstance(y,dict) else None
 sub=all(sorted(x.get(k) or {})==sorted(y.get(k) or {}) for k in ("feed","koyeb") if isinstance(x,dict) and isinstance(y,dict))
 print("ROUTE",p,"worker",sw,"eigen",se,"zelfde_velden",kx==ky,"zelfde_subvelden",sub,"koyeb_login",(x or {}).get("koyeb",{}).get("login"),(y or {}).get("koyeb",{}).get("login"))
