#!/usr/bin/env bash
#
# Das Skript läuft von Hand oder aus dem Cron:
# */5 * * * * CHIPTUNE_BASE=$HOME/www/chiptune <WORKSPACE>/pull.sh >> <WORKSPACE>/pull.log 2>&1

set -euo pipefail

BASE="${CHIPTUNE_BASE:?CHIPTUNE_BASE ist nicht gesetzt}" # Umgebung mit z.B. ~/www/chiptune (enthält releases/ und current)
REPO="${CHIPTUNE_REPO:-kchaot/chiptune}"
URL="${CHIPTUNE_URL:-https://github.com/${REPO}/releases/download/build}"
KEEP="${CHIPTUNE_KEEP:-3}"

[[ "$BASE" == /* && "$BASE" != "/" && "$BASE" != *..* ]] || { echo "CHIPTUNE_BASE ist ungültig." >&2; exit 1; }
[[ "$KEEP" =~ ^[0-9]+$ ]] || { echo "CHIPTUNE_KEEP ist ungültig." >&2; exit 1; }

mkdir -p "$BASE/releases" "$BASE/tmp"

# Nur ein Lauf gleichzeitig.
exec 9> "$BASE/.pull.lock"
flock -n 9 || exit 0

work="$(mktemp -d "$BASE/tmp/pull.XXXXXX")"
trap 'rm -rf "$work"' EXIT

fetch() { curl -fsSL --retry 2 --max-time 120 -o "$2" "$URL/$1"; }

fetch public.tar.gz.sha256 "$work/sum"
hash="$(awk 'NR==1 {print $1}' "$work/sum")"
[[ "$hash" =~ ^[0-9a-f]{64}$ ]] || { echo "Ungültige Prüfsumme in public.tar.gz.sha256." >&2; exit 1; }

release="${hash:0:16}"

if [ -d "$BASE/releases/$release" ] && [ "$(readlink "$BASE/current" 2>/dev/null || true)" = "releases/$release" ]; then
  exit 0
fi

if [ ! -d "$BASE/releases/$release" ]; then
  fetch public.tar.gz "$work/public.tar.gz"
  echo "$hash  $work/public.tar.gz" | sha256sum --check --status \
    || { echo "Prüfsumme stimmt nicht (der Upload läuft vielleicht noch). Nächster Versuch beim nächsten Lauf." >&2; exit 1; }

  # Keine absoluten Pfade und kein "..", nur normale Dateien und Ordner.
  if tar -tzf "$work/public.tar.gz" | grep -E '(^/|(^|/)\.\.(/|$))' >/dev/null; then
    echo "Das Paket enthält unzulässige Pfade." >&2
    exit 1
  fi

  mkdir "$work/extract"
  tar -xzf "$work/public.tar.gz" -C "$work/extract" --no-same-owner --no-same-permissions
  [ -f "$work/extract/index.html" ] && [ -f "$work/extract/404.html" ] && [ -f "$work/extract/.chiptune-site" ] \
    || { echo "Das Paket sieht nicht nach einem Build aus (index.html, 404.html oder .chiptune-site fehlt)." >&2; exit 1; }

  find "$work/extract" -type d -exec chmod 755 {} +
  find "$work/extract" -type f -exec chmod 644 {} +
  mv "$work/extract" "$BASE/releases/$release"
fi

ln -sfn "releases/$release" "$BASE/current.new"
mv -T "$BASE/current.new" "$BASE/current"
echo "Online: $release"

current="$(basename "$(readlink "$BASE/current")")"
ls -1t "$BASE/releases" | grep -vx "$current" | tail -n +"$((KEEP + 1))" | while read -r old; do
  rm -rf "${BASE:?}/releases/$old"
done
