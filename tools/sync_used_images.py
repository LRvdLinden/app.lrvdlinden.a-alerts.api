#!/usr/bin/env python3
"""Copy (never move) every image the A-Alerts website and apps use into /image of this repository.

Sources are read-only checkouts, given as arguments or found in .sources/:
  p2000-app    <- LRvdLinden/p2000-apeldoorn @ a-alerts (website, iOS/tvOS/watchOS apps, Android app, Homey app)
  p2000-brand  <- LRvdLinden/P2000-brand @ main (only banners/: the banners the server, share pages and Homey link to)

Result:
  image/website/        website/assets/img (photos, news banners, icons, markers, aircraft drawings)
  image/website-root/   images in other folders of the website (favicons, share images)
  image/brand/banners/  the banners of the brand repository
  image/apps/ios/       every asset catalog of the Apple apps (iPhone, iPad, Mac, Watch, TV, widgets)
  image/apps/android/   drawable and mipmap folders of the Android app
  image/apps/homey/     images of the Homey app
  image/index.json      every file with its source, size and SHA-256
  image/README.md       counts per folder

Run:  python3 tools/sync_used_images.py [path/to/p2000-apeldoorn] [path/to/P2000-brand]
A file that disappeared from its source is removed here too, so /image is always what is in use now.
"""
import hashlib, json, shutil, sys
from pathlib import Path

root = Path(__file__).resolve().parents[1]
app = Path(sys.argv[1]) if len(sys.argv) > 1 else root / ".sources/p2000-app"
brand = Path(sys.argv[2]) if len(sys.argv) > 2 else root / ".sources/p2000-brand"
if not (app / "website/assets/img").is_dir() or not (brand / "banners").is_dir():
    raise SystemExit("Source checkouts missing; nothing changed")

BEELD = {".png", ".jpg", ".jpeg", ".webp", ".svg", ".gif", ".ico", ".avif"}
dest = root / "image"
gezien, index = set(), []

def kopieer(origin: Path, naar: str, bron: str, extra=frozenset(), filt=None):
    for f in sorted(origin.rglob("*")):
        if not f.is_file() or f.is_symlink() or f.suffix.lower() not in BEELD | extra:
            continue
        rel = f.relative_to(origin)
        if filt and not filt(rel):
            continue
        doel = dest / naar / rel
        doel.parent.mkdir(parents=True, exist_ok=True)
        data = f.read_bytes()
        if not doel.exists() or doel.read_bytes() != data:
            shutil.copyfile(f, doel)
        gezien.add(doel)
        index.append({"pad": str(doel.relative_to(dest)), "bron": f"{bron}/{rel}", "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()})

kopieer(app / "website/assets/img", "website", "p2000-apeldoorn:website/assets/img")
kopieer(app / "website", "website-root", "p2000-apeldoorn:website", filt=lambda r: r.parts[:2] != ("assets", "img"))
kopieer(brand / "banners", "brand/banners", "P2000-brand:banners")
for cat in sorted(app.glob("*/*.xcassets")):
    if cat.parts[-2] == "beheer":
        continue
    kopieer(cat, f"apps/ios/{cat.parts[-2]}/{cat.stem}", f"p2000-apeldoorn:{cat.relative_to(app)}", extra={".pdf"})
res = app / "android/app/src/main/res"
kopieer(res, "apps/android", "p2000-apeldoorn:android/app/src/main/res", filt=lambda r: r.parts[0].startswith(("drawable", "mipmap")))
kopieer(app / "homey", "apps/homey", "p2000-apeldoorn:homey", filt=lambda r: "node_modules" not in r.parts and ".homeybuild" not in r.parts)

# What is no longer in use goes away here too.
for f in sorted(dest.rglob("*")):
    if f.is_file() and f not in gezien and f.name not in ("index.json", "README.md"):
        f.unlink()
for d in sorted((p for p in dest.rglob("*") if p.is_dir()), reverse=True):
    if not any(d.iterdir()):
        d.rmdir()

index.sort(key=lambda x: x["pad"])
(dest / "index.json").write_text(json.dumps(index, ensure_ascii=False, indent=1) + "\n")
per = {}
for x in index:
    k = "/".join(x["pad"].split("/")[: 3 if x["pad"].startswith("apps/") else 1 if not x["pad"].startswith("brand/") else 2])
    n, b = per.get(k, (0, 0)); per[k] = (n + 1, b + x["bytes"])
regels = ["# Beelden van A-Alerts", "",
  "Alle foto's, banners, iconen en markers die de website en de apps van A-Alerts gebruiken, gekopieerd uit de bronnen.",
  "Deze map wordt gemaakt door `tools/sync_used_images.py`; wijzig hier niets met de hand. De bronnen blijven leidend:",
  "`LRvdLinden/p2000-apeldoorn` (branch `a-alerts`) en `LRvdLinden/P2000-brand` (alleen `banners/`).", "",
  "| Map | Bestanden | Grootte |", "|---|---:|---:|"]
for k in sorted(per):
    regels.append(f"| `{k}/` | {per[k][0]} | {per[k][1] / 1e6:.1f} MB |")
regels += [f"| **Totaal** | **{len(index)}** | **{sum(x['bytes'] for x in index) / 1e6:.1f} MB** |", "",
  "`index.json` noemt per bestand de bron, de grootte en de SHA-256.", "",
  "Let op: de logo's van derden (onder meer Burgernet, NL-Alert, AMBER Alert, KNMI en VNOG) en de nieuwsfoto's blijven van hun eigenaren.",
  "Dat ze hier staan geeft geen recht om ze buiten A-Alerts te gebruiken.", ""]
(dest / "README.md").write_text("\n".join(regels))
print(f"{len(index)} bestanden, {sum(x['bytes'] for x in index) / 1e6:.1f} MB")
