#!/bin/bash
# Puts app into maintenance mode: replaces all app files on server.
# Run 'bash restore.sh' to bring the site back.
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
ENV="$DIR/.deploy.prod.env"
[[ ! -f "$ENV" ]] && { echo "Missing $ENV"; exit 1; }
source "$ENV"

FTP_PASS_ENC="${FTP_PASS//@/%40}"
BASE="ftp://${FTP_USER}:${FTP_PASS_ENC}@${FTP_HOST}/${APP_PATH}"

upload() {
  echo "  → $2"
  curl -s --ftp-pasv -T "$1" "$BASE/$2"
}

echo "Enabling maintenance mode..."
upload "$DIR/maintenance.html"    "yoga.html"
upload "$DIR/maintenance.html"    "student.html"
upload "$DIR/_disabled-proxy.php" "airtable-proxy.php"
echo "Done: maintenance mode ON at $(date '+%H:%M:%S')"
echo "Run 'bash restore.sh' to bring the site back."
