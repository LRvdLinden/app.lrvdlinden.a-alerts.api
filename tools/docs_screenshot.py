#!/usr/bin/env python3
"""Screenshots of /docs after logging in (desktop and phone, light and dark). Never prints the login."""
import os,sys
from playwright.sync_api import sync_playwright
u=os.environ.get("AALERTS_DOCS_USERNAME","");p=os.environ.get("AALERTS_DOCS_PASSWORD","")
if not u or not p:sys.exit("DOCS_TEST_SECRETS_MISSING")
os.makedirs("shots",exist_ok=True)
route=os.environ.get("DOCS_ROUTE","")
with sync_playwright() as pw:
 b=pw.chromium.launch()
 for name,vp,scheme in [("desktop",{"width":1366,"height":900},"light"),("telefoon",{"width":390,"height":844},"light"),("desktop-donker",{"width":1366,"height":900},"dark")]:
  ctx=b.new_context(viewport=vp,color_scheme=scheme,device_scale_factor=1)
  pg=ctx.new_page()
  pg.goto("https://api.a-alerts.nl/docs/",wait_until="networkidle")
  pg.fill("#u",u);pg.fill("#p",p);pg.click("button.primary")
  pg.wait_for_load_state("networkidle")
  if "logout" not in pg.content():sys.exit("LOGIN_FAILED")
  pg.screenshot(path=f"shots/docs-{name}.png",full_page=True)
  if route:
   pg.goto("https://api.a-alerts.nl/docs?route="+route,wait_until="networkidle")
   pg.screenshot(path=f"shots/route-{name}.png",full_page=True)
  errors=[]
  ctx.close()
 b.close()
print("SHOTS_OK")
