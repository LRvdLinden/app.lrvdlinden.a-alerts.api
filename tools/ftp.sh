# shellcheck shell=bash
# A-Alerts website hosting: ordinary passive FTP, port 21 (NOT FTPS).
# Source this file from GitHub Actions, then call ftp_connect and ftp_run.
# Warning: ordinary FTP has no transport encryption, including credentials.
# Secrets: FTP_SERVER, FTP_USERNAME / FTP_USER, FTP_PASSWORD,
#          optional FTP_DIR, FTP_PORT, FTP_USERNAME_ALT.

FTP_PORT="${FTP_PORT:-21}"
FTP_TMP="${FTP_TMP:-$(mktemp -d)}"
_ftp_common="set cmd:fail-exit true; set net:max-retries 2; set net:timeout 25; set net:reconnect-interval-base 3"
_ftp_common="$_ftp_common; set ftp:passive-mode true; set ftp:ssl-allow no; set ftp:ssl-force no; set ftp:ssl-protect-data no"

ftp_connect() {
  local key value user
  FTP_USERNAME="${FTP_USERNAME:-${FTP_USER:-}}"
  for key in FTP_SERVER FTP_USERNAME FTP_PASSWORD; do
    value="${!key:-}"
    [ -n "$value" ] || { echo "::error::FTP secret $key ontbreekt."; return 1; }
    printf -v "$key" '%s' "$value"
  done
  [[ "$FTP_PORT" =~ ^[0-9]+$ ]] && [ "$FTP_PORT" -ge 1 ] && [ "$FTP_PORT" -le 65535 ] || {
    echo "::error::Ongeldige FTP-poort."; return 1;
  }
  # Accept hostname or ftp://hostname; never connect with an FTPS scheme.
  FTP_SERVER="${FTP_SERVER#ftp://}"
  FTP_SERVER="${FTP_SERVER#ftps://}"
  FTP_SERVER="${FTP_SERVER%%/*}"
  FTP_SERVER="${FTP_SERVER%%:*}"
  [ -n "$FTP_SERVER" ] || { echo "::error::FTP-servernaam is leeg."; return 1; }

  export LFTP_PASSWORD="$FTP_PASSWORD"
  FTP_LOGIN_USER=""
  local attempt
  for attempt in 1 2 3; do
    for user in "$FTP_USERNAME" "${FTP_USERNAME_ALT:-}"; do
      [ -n "$user" ] || continue
      if lftp -e "$_ftp_common; open --env-password -p $FTP_PORT -u \"$user\" \"$FTP_SERVER\"; cls -1 > \"$FTP_TMP/root.txt\"; bye" \
          >/dev/null 2>"$FTP_TMP/login-error.txt"; then
        FTP_LOGIN_USER="$user"
        break 2
      fi
    done
    if [ "$attempt" -lt 3 ]; then
      echo "::warning::FTP-login is tijdelijk niet gelukt (poging $attempt/3); opnieuw proberen."
      sleep 10
    fi
  done
  rm -f "$FTP_TMP/login-error.txt"
  [ -n "$FTP_LOGIN_USER" ] || { echo "::error::FTP-verbinding of inloggen na 3 pogingen mislukt."; return 1; }

  FTP_TARGET="${FTP_DIR:-}"
  FTP_TARGET="${FTP_TARGET%/}"
  if [ -z "$FTP_TARGET" ] && grep -Eq '^public_html/?$' "$FTP_TMP/root.txt"; then
    FTP_TARGET="public_html"
  fi
  [ -n "$FTP_TARGET" ] || FTP_TARGET="."
  rm -f "$FTP_TMP/root.txt"
  echo "::notice::FTP (poort $FTP_PORT, passieve modus) verbonden."
}

ftp_run() {
  lftp -e "$_ftp_common; open --env-password -p $FTP_PORT -u \"$FTP_LOGIN_USER\" \"$FTP_SERVER\"; $1; bye"
}

# Read-only directory probe: missing candidate folders may be ignored by the caller.
ftp_try() {
  lftp -e "$_ftp_common; set cmd:fail-exit false; open --env-password -p $FTP_PORT -u \"$FTP_LOGIN_USER\" \"$FTP_SERVER\"; $1; bye"
}
