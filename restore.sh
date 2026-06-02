#!/bin/bash
# Redeploys all app files. Use to restore a known-good local state.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$DIR/update.sh" student.html
bash "$DIR/update.sh" yoga.html
bash "$DIR/update.sh" airtable-proxy.php
echo "All files restored at $(date '+%H:%M:%S')"
