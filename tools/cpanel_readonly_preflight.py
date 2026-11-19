#!/usr/bin/env python3
"""Read-only cPanel UAPI check: no tokens, usernames, paths, or API response bodies in logs."""
import json, os, ssl, sys, urllib.request, urllib.error
host=os.environ.get("CPANEL_HOST","cpanel04.zxcs.nl").strip()
token=os.environ.get("VIMEXX_CPANEL_API_TOKEN","")
username=os.environ.get("VIMEXX_CPANEL_USERNAME","").strip()
if not username:
    ftp=os.environ.get("FTP_USERNAME","").strip()
    username=ftp if ftp and "@" not in ftp else ""
if not token:
    print("FAIL: VIMEXX_CPANEL_API_TOKEN ontbreekt in GitHub Actions.")
    sys.exit(2)
if not username:
    print("FAIL: VIMEXX_CPANEL_USERNAME ontbreekt (de cPanel-login, niet het FTP-account).")
    sys.exit(3)
if not host or any(c not in "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-" for c in host):
    print("FAIL: Ongeldige CPANEL_HOST.")
    sys.exit(4)
def call(module, function):
    url=f"https://{host}:2083/execute/{module}/{function}"
    req=urllib.request.Request(url,headers={
        "Authorization":f"cpanel {username}:{token}",
        "Accept":"application/json",
        "User-Agent":"A-Alerts-ReadOnly-Preflight/1.0"})
    try:
        with urllib.request.urlopen(req,context=ssl.create_default_context(),timeout=20) as response:
            data=json.load(response)
        if data.get("status")==0 or data.get("errors"):
            print(f"{module}/{function}: API returned an error (details redacted).")
            return False
        print(f"{module}/{function}: OK")
        return True
    except urllib.error.HTTPError as error:
        print(f"{module}/{function}: HTTP {error.code}. No account details shown.")
        return False
    except Exception as error:
        print(f"{module}/{function}: Connection error ({type(error).__name__}).")
        return False

# Read-only UAPI calls; avoids accidental changes to cPanel, database, PHP or domains.
passed=[call("Mysql","list_databases"),call("Cron","listcron"),call("DomainInfo","list_domains")]
print("Result:", "PASS" if all(passed) else "CHECK_NEEDED")
sys.exit(0 if all(passed) else 1)
