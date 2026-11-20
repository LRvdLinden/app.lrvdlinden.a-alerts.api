#!/usr/bin/env python3
"""Copy the reference data of the Worker (area, places, stations) into the PHP API as JSON. Copies only.

Usage: tools/sync_worker_data.py <path to p2000-apeldoorn checkout>
"""
import json,re,sys
from pathlib import Path
src=Path(sys.argv[1] if len(sys.argv)>1 else "../p2000-apeldoorn")/"worker/src"
out=Path(__file__).resolve().parents[1]/"web/_core/aa/data"
out.mkdir(parents=True,exist_ok=True)
def js_default(p):
 t=(src/p).read_text(encoding="utf-8")
 return json.loads(re.search(r"export default (\[.*\]);?\s*$",t,re.S).group(1))
import glob
def js_obj(p):
 t=(src/p).read_text(encoding="utf-8")
 return json.loads(re.search(r"export default (\{.*\});?\s*$",t,re.S).group(1))
files={"veiligheidsregio.json":js_obj("veiligheidsregio.js"),"locaties.json":js_obj("locaties.js"),
 **{"content-"+Path(f).name:json.loads(Path(f).read_text(encoding="utf-8")) for f in sorted(glob.glob(str(src.parent/"content"/"*.json")))},
 "werkgebied.json":json.loads((src/"werkgebied.json").read_text(encoding="utf-8")),
 "kazernes.json":js_default("kazernes.js"),"woonplaatsen.json":js_default("woonplaatsen.js")}
for name,data in files.items():
 (out/name).write_text(json.dumps(data,ensure_ascii=False,separators=(",",":")),encoding="utf-8")
 print(name,len(data) if isinstance(data,list) else len(data.get("plaatsen",[])))
