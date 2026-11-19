#!/usr/bin/env python3
"""Copy, never move, visual assets from the two A-Alerts source repositories.

Checked-out source roots (read-only):
  .sources/p2000-brand  <- LRvdLinden/P2000-brand
  .sources/p2000-app    <- LRvdLinden/p2000-apeldoorn @ a-alerts

All image assets live in repo root /images; only a small selection is duplicated
under /web/images for first-party API docs thumbnails, independent of external CDN.
"""
from pathlib import Path
import shutil

root=Path(__file__).resolve().parents[1]
source_brand=root/".sources/p2000-brand"
source_app=root/".sources/p2000-app"
if not (source_brand/"README.md").exists() or not (source_app/"website/assets/img").is_dir():
    raise SystemExit("Source checkouts missing; nothing changed")
allowed={".png",".jpg",".jpeg",".webp",".svg",".gif",".avif",".heic"}
copied=[]
def copy_assets(origin,dest,filterfn=None):
    for entry in sorted(origin.rglob("*")):
        if not entry.is_file() or entry.is_symlink() or entry.suffix.lower() not in allowed:continue
        rel=entry.relative_to(origin)
        if filterfn and not filterfn(rel):continue
        target=dest/rel
        target.parent.mkdir(parents=True,exist_ok=True)
        if not target.exists() or entry.read_bytes()!=target.read_bytes():
            shutil.copy2(entry,target)
        copied.append(str(target.relative_to(root)))
image_root=root/"images"
# Original structure retained: /images/brand/nieuws/knmi-red-wind.png etc.
copy_assets(source_brand,image_root/"brand")
# All website visual assets; includes 112news images, banners, maps, timeline visuals.
copy_assets(source_app/"website/assets/img",image_root/"website")
# Vector aircraft silhouettes used on map + shared iOS helis.
copy_assets(source_app/"tools/lucht",image_root/"aircraft/vector")
copy_assets(source_app/"Shared/SharedAssets.xcassets",image_root/"aircraft/shared",
            lambda p: "heli_" in p.name and p.suffix.lower()==".svg")
copy_assets(source_app/"android/app/src/main/res/drawable-nodpi",image_root/"aircraft/android",
            lambda p:p.name.startswith(("heli_","lucht_")))
# Lightweight doc previews: only source-owned files, not externally scraped photos.
preview_site=["aalerts-icon-32.png","aalerts-icon-180.png","aalerts-icon-512.png",
 "icon_amber.png","icon_ambulance.png","icon_brandweer.png","icon_burgernet.png",
 "icon_knmi.png","icon_nlalert.png","icon_politie.png","icon_traumaheli.png",
 "icon_vermistkind.png","icon_vnog.png",
 "marker_amber.png","marker_ambulance.png","marker_brandweer.png",
 "marker_burgernet.png","marker_nlalert.png","marker_politie.png",
 "marker_traumaheli.png","marker_vermistkind.png","marker_vnog.png",
 "banner_brand.webp","banner_ambu.webp","banner_pol.webp","banner_life.webp",
 "banner_nlalert.webp","banner_burgernet.webp","banner_knmi.webp",
 "banner_amber.webp","banner_vermistkind.webp",
 "nieuws/politie-brand.webp","nieuws/112-brand-1.webp"]
for rel in preview_site:
    p=source_app/"website/assets/img"/rel
    if not p.is_file():continue
    dest=root/"web/images"/"website"/rel
    dest.parent.mkdir(parents=True,exist_ok=True)
    shutil.copy2(p,dest)
for name in ["heli_lifeliner_ec135","heli_politie_aw139","heli_politie_ec135"]:
    p=source_app/"Shared/SharedAssets.xcassets"/(name+".imageset")/(name+".svg")
    if p.is_file():
        dest=root/"web/images/aircraft"/(name+".svg")
        dest.parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(p,dest)
for name in ["apache","chinook","cougar","f35","heli","hercules","kmar",
             "kustwacht_aw189","kustwacht_dash8","kustwacht_heli","les",
             "nh90","phgov","vliegtuig"]:
    p=source_app/"tools/lucht"/(name+".svg")
    if p.is_file():
        dest=root/"web/images/aircraft"/(name+".svg")
        dest.parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(p,dest)
print("Copied asset files:",len(copied))
print("Origin repositories remain unmodified.")
