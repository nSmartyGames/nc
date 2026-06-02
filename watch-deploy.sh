#!/bin/bash
# Watches nc project files and auto-uploads changes to nicolaecatrina.com via SFTP.
# Usage: bash watch-deploy.sh

DIR="$(cd "$(dirname "$0")" && pwd)"
source "$DIR/.deploy.prod.env"

declare -A checksums

checksum() { md5 -q "$1" 2>/dev/null; }

WATCH_FILES=(student.html yoga.html airtable-proxy.php)

for f in "${WATCH_FILES[@]}"; do
  checksums["$f"]=$(checksum "$DIR/$f")
done

echo "Watching: ${WATCH_FILES[*]}"
echo "Press Ctrl+C to stop."

while true; do
  for f in "${WATCH_FILES[@]}"; do
    current=$(checksum "$DIR/$f")
    if [[ "$current" != "${checksums[$f]}" ]]; then
      checksums["$f"]=$current
      bash "$DIR/update.sh" "$f"
    fi
  done
  sleep 1
done
