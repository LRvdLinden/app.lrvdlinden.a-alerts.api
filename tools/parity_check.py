#!/usr/bin/env python3
"""Per ported route: the answer of the production Worker next to that of api.a-alerts.nl. Read only, public routes.

Prints per route whether status and fields match and how many records differ; never prints record contents in full.
"""
import json,sys,urllib.request,urllib.error
W="https://p2000-apeldoorn-api.lrvdlinden.workers.dev"; A="https://api.a-alerts.nl"
def get(base,path):
 try:
  with urllib.request.urlopen(urllib.request.Request(base+path,headers={"Accept":"application/json","User-Agent":"A-Alerts-parity/1.0"}),timeout=25) as r:
   return r.status,json.loads(r.read() or b"null"),r.headers.get("X-AAlerts-Bron")
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
print("  IDS gelijk",sorted(x["id"] for x in wa or [])==sorted(x["id"] for x in wb or []))
_,ws,_=get(W,"/v1/waarschuwingen?days=30&kind=1&vnog=1")
for w in (ws or [])[:8]:
 import urllib.parse as _u
 p="/v1/waarschuwingen/"+_u.quote(w["id"],safe=":")
 sw,x,_=get(W,p);se,y,bron=get(A,p);d=diff(x,y)
 print("ROUTE /v1/waarschuwingen/{"+w["bron"]+"}","worker",sw,"eigen",se,"verschillen",len(d),";".join(d[:5]))
 if se!=sw:print("  ID",w["id"][:60],"EIGEN_ANTWOORD",str(y)[:200])
import json as _j,pathlib
for k in _j.loads(pathlib.Path("web/_core/aa/data/kazernes.json").read_text())[:58]:
 sw,x,_=get(W,"/v1/posten/"+k["id"]);se,y,bron=get(A,"/v1/posten/"+k["id"])
 d=diff(x,y)
 if d or sw!=se:print("ROUTE /v1/posten/"+k["id"],"worker",sw,"eigen",se,"verschillen",len(d),";".join(d[:5]))
print("POSTEN_KLAAR")
