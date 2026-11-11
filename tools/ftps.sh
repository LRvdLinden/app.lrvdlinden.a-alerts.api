# shellcheck shell=bash
# FTPS-verbinding met de hosting van a-alerts.nl (zelfde aanpak als de dashboards van Tunr).
# Gebruik:  source tools/ftps.sh; ftps_connect; ftps_run "cd \"$FTPS_TARGET\"; cls -1"
# Omgeving: FTP_SERVER, FTP_USERNAME (of FTP_USER), FTP_PASSWORD, optioneel FTP_PORT (standaard 21), FTP_DIR en FTP_USERNAME_ALT.
# Nodig: lftp, openssl, curl, python3 (werkt op Ubuntu- en macOS-runners; macOS: brew install lftp).
#
# Alleen FTPS met een gecontroleerd certificaat (nooit gewone FTP). Gedeelde hosting toont vaak een certificaat op naam
# van de hoster (bij Tunr *.zxcs.nl; de echte servernaam is cpanel04.zxcs.nl). Klopt het certificaat niet voor
# FTP_SERVER, dan wordt een naam gezocht uit de reverse DNS, het certificaat (SAN/CN, bij een wildcard afgeleid) of
# FTPS_HOST_CANDIDATES, die naar dezelfde IP-adressen wijst als FTP_SERVER; die naam wordt daarna volledig gecontroleerd.
# Het log is openbaar: geen gebruikersnamen, mappen, hostnamen, IP-adressen of serverantwoorden, alleen gelukt/mislukt.

FTPS_HOST_CANDIDATES="${FTPS_HOST_CANDIDATES:-cpanel04.zxcs.nl}"
# macOS-runners: /usr/bin/openssl is LibreSSL (geen -verify_hostname/-starttls zoals OpenSSL); gebruik OpenSSL 3 van Homebrew
if [ "$(uname -s)" = "Darwin" ] && ! openssl version 2>/dev/null | grep -q '^OpenSSL 3'; then
  for _d in "$(brew --prefix openssl@3 2>/dev/null)/bin" /opt/homebrew/opt/openssl@3/bin /usr/local/opt/openssl@3/bin; do
    [ -x "$_d/openssl" ] && { PATH="$_d:$PATH"; export PATH; break; }
  done
  if ! openssl version 2>/dev/null | grep -q '^OpenSSL 3' && command -v brew >/dev/null; then
    HOMEBREW_NO_AUTO_UPDATE=1 brew install openssl@3 >/dev/null 2>&1 || true
    _d="$(brew --prefix openssl@3 2>/dev/null)/bin"; [ -x "$_d/openssl" ] && { PATH="$_d:$PATH"; export PATH; }
  fi
fi
FTPS_TMP="${FTPS_TMP:-$(mktemp -d)}"
FTPS_PORT=$(printf '%s' "${FTP_PORT:-21}" | tr -cd '0-9'); FTPS_PORT="${FTPS_PORT:-21}"
_ftps_ca="$FTPS_TMP/ca.pem"
_ftps_common="set cmd:fail-exit true; set net:max-retries 3; set net:timeout 30; set net:reconnect-interval-base 5; set ftp:passive-mode true"
_ftps_common="$_ftps_common; set ftp:ssl-force true; set ftp:ssl-protect-data true; set ftp:ssl-protect-list true"
_ftps_common="$_ftps_common; set ssl:verify-certificate yes; set ssl:check-hostname yes; set ssl:ca-file $_ftps_ca"

_ftps_trim(){ printf '%s' "$1" | tr -d '\r\n' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//'; }
# draagbaar (ook macOS-runners: geen getent/timeout, LibreSSL)
_ftps_ips(){
  if command -v getent >/dev/null 2>&1; then (getent ahostsv4 "$1" || true) | awk '{print $1}' | sort -u
  else python3 -c 'import socket,sys
try: print("\n".join(sorted({a[4][0] for a in socket.getaddrinfo(sys.argv[1], None, socket.AF_INET)})))
except Exception: pass' "$1"; fi
}
_ftps_ptr(){
  python3 -c 'import socket,sys
try: print(socket.gethostbyaddr(sys.argv[1])[0].rstrip(".").lower())
except Exception: pass' "$1" 2>/dev/null || true
}
_ftps_to(){ local s="$1"; shift; if command -v timeout >/dev/null 2>&1; then timeout "$s" "$@"; elif command -v gtimeout >/dev/null 2>&1; then gtimeout "$s" "$@"; else "$@"; fi; }
_ftps_ca_init(){
  local f
  for f in /etc/ssl/certs/ca-certificates.crt /etc/pki/tls/certs/ca-bundle.crt /etc/ssl/cert.pem; do
    [ -s "$f" ] && { cp "$f" "$_ftps_ca"; return 0; }
  done
  echo "::error::Geen CA-bundel gevonden voor de certificaatcontrole."; return 1
}
# onvolledige keten (tussencertificaat niet meegestuurd): via het AIA-adres ophalen; controle blijft tegen een vertrouwde root
_ftps_add_chain(){
  local h="$1" aia
  aia=$(_ftps_to 30 openssl s_client -connect "$h:$FTPS_PORT" -starttls ftp -servername "$h" </dev/null 2>/dev/null \
    | openssl x509 -noout -text 2>/dev/null | grep -oE 'CA Issuers - URI:[^ ]+' | sed 's/CA Issuers - URI://' | head -1 || true)
  [ -n "$aia" ] || return 0
  curl -fsSL --max-time 20 "$aia" -o "$FTPS_TMP/inter.bin" 2>/dev/null || return 0
  openssl x509 -inform DER -in "$FTPS_TMP/inter.bin" -out "$FTPS_TMP/inter.pem" 2>/dev/null \
    || openssl x509 -in "$FTPS_TMP/inter.bin" -out "$FTPS_TMP/inter.pem" 2>/dev/null || return 0
  grep -q "$(sed -n 2p "$FTPS_TMP/inter.pem")" "$_ftps_ca" || cat "$FTPS_TMP/inter.pem" >> "$_ftps_ca"
}
# certificaat geldig voor precies deze hostnaam (keten + naam)?
_ftps_cert_ok(){
  _ftps_to 40 openssl s_client -connect "$1:$FTPS_PORT" -starttls ftp -servername "$1" -verify_hostname "$1" -verify_return_error \
    -CAfile "$_ftps_ca" </dev/null 2>/dev/null | grep -q 'Verify return code: 0 (ok)'
}
# naam met een geldig certificaat die naar dezelfde server wijst als $1 (alle IP's van de kandidaat zijn IP's van $1)
_ftps_cert_host(){
  local host="$1" names n want got ptr cands="" first
  want=$(_ftps_ips "$host"); [ -n "$want" ] || return 1
  names=$(_ftps_to 30 openssl s_client -connect "$host:$FTPS_PORT" -starttls ftp -servername "$host" </dev/null 2>/dev/null \
    | openssl x509 -noout -text 2>/dev/null | grep -E 'DNS:|Subject:' \
    | grep -oE '(DNS:|CN ?= ?)(\*\.)?[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+' | sed -E 's/^(DNS:|CN ?= ?)//' | tr 'A-Z' 'a-z' | sort -u) || true
  ptr=$(_ftps_ptr "$(echo "$want" | head -1)")
  first="${host%%.*}"
  cands="$ptr $FTPS_HOST_CANDIDATES"
  for n in $names; do case "$n" in \*.*) cands="$cands $first.${n#\*.} ftp.${n#\*.}" ;; *) cands="$cands $n" ;; esac; done
  for n in $(echo "$cands" | tr ' ' '\n' | awk 'NF && !seen[$0]++'); do
    if [[ "$n" == *\** || "$n" == "$host" ]]; then continue; fi
    got=$(_ftps_ips "$n"); [ -n "$got" ] || continue
    [ -z "$(comm -23 <(echo "$got") <(echo "$want"))" ] || continue
    if _ftps_cert_ok "$n"; then printf '%s' "$n"; return 0; fi
  done
  return 1
}
_ftps_login(){ lftp -e "$_ftps_common; open --env-password -p $FTPS_PORT -u \"$2\" \"$1\"; cls -1 > \"$FTPS_TMP/root.txt\"; bye" >/dev/null 2>"$FTPS_TMP/err.txt"; }

# Zet FTPS_USER, FTPS_SERVER en FTPS_TARGET (de webmap van a-alerts.nl); geeft 1 bij een fout.
ftps_connect(){
  local k v base host="" attempt u alt
  [ -n "${FTP_USERNAME:-}" ] || FTP_USERNAME="${FTP_USER:-}"
  for k in FTP_SERVER FTP_USERNAME FTP_PASSWORD; do
    v="${!k:-}"; [ -n "$v" ] || { echo "::error::Secret $k ontbreekt."; return 1; }
    printf -v "$k" '%s' "$(_ftps_trim "$v")"
  done
  FTP_SERVER=$(printf '%s' "$FTP_SERVER" | sed -E 's#^[a-zA-Z]+://##; s#/.*$##; s#:[0-9]+$##')
  export LFTP_PASSWORD="$FTP_PASSWORD"
  _ftps_ca_init || return 1
  # 1. host met een geldig certificaat
  for attempt in 1 2 3; do
    if [ "$attempt" -gt 1 ]; then sleep 30; fi
    for base in "$FTP_SERVER" ftp.a-alerts.nl; do
      [ -n "$(_ftps_ips "$base")" ] || continue
      if [ "$attempt" = 1 ]; then _ftps_add_chain "$base"; fi
      if _ftps_cert_ok "$base"; then host="$base"; break 2; fi
      if alt=$(_ftps_cert_host "$base"); then host="$alt"; echo "::notice::Certificaat staat op een andere naam van dezelfde server; die wordt gebruikt (met controle)."; break 2; fi
    done
  done
  [ -n "$host" ] || { echo "::error::Geen FTP-server met een geldig FTPS-certificaat gevonden (zet FTP_SERVER op de naam uit het certificaat van de hoster)."; return 1; }
  # 2. inloggen
  FTPS_USER=""
  for attempt in 1 2 3; do
    if [ "$attempt" -gt 1 ]; then sleep 30; fi
    for u in "$FTP_USERNAME" "${FTP_USERNAME_ALT:-}"; do
      [ -n "$u" ] || continue
      if _ftps_login "$host" "$u"; then FTPS_USER="$u"; break 2; fi
      if grep -qiE "login incorrect|530|authentication" "$FTPS_TMP/err.txt"; then echo "::warning::Inloggen geweigerd met een van de gebruikersnamen."; fi
    done
  done
  rm -f "$FTPS_TMP/err.txt"
  [ -n "$FTPS_USER" ] || { echo "::error::Inloggen op de FTP-server mislukt (controleer gebruikersnaam en wachtwoord)."; return 1; }
  FTPS_SERVER="$host"
  # 3. doelmap: FTP_DIR, anders public_html als de login die heeft, anders de hoofdmap van de FTP-login
  FTPS_TARGET="${FTP_DIR:-}"; FTPS_TARGET="${FTPS_TARGET%/}"
  if [ -z "$FTPS_TARGET" ] && grep -qx 'public_html/\?' "$FTPS_TMP/root.txt" 2>/dev/null; then FTPS_TARGET="public_html"; echo "::notice::Doelmap: public_html."; fi
  [ -n "$FTPS_TARGET" ] || FTPS_TARGET="."
  rm -f "$FTPS_TMP/root.txt"
  echo "::notice::Verbonden met de server (FTPS, certificaat gecontroleerd)."
}

# lftp-commando's op de server; uitvoer naar stdout (niet naar het log sturen als er servergegevens in staan)
ftps_run(){ lftp -e "$_ftps_common; open --env-password -p $FTPS_PORT -u \"$FTPS_USER\" \"$FTPS_SERVER\"; $1; bye"; }
# zoals ftps_run, maar fouten negeren (bv. een bestand dat nog niet bestaat)
ftps_try(){ lftp -e "$_ftps_common; set cmd:fail-exit false; open --env-password -p $FTPS_PORT -u \"$FTPS_USER\" \"$FTPS_SERVER\"; $1; bye"; }
