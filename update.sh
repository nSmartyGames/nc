#!/bin/bash
# Usage: bash update.sh <filename>
# Uploads a local file to public_html/app/ on nicolaecatrina.com via FTP port 21.

set -euo pipefail

FILE="${1:-}"
DIR="$(cd "$(dirname "$0")" && pwd)"
ENV="$DIR/.deploy.prod.env"

[[ -z "$FILE" ]] && { echo "Usage: bash update.sh <filename>"; exit 1; }
[[ ! -f "$DIR/$FILE" ]] && { echo "File not found: $DIR/$FILE"; exit 1; }
[[ ! -f "$ENV" ]] && { echo "Missing $ENV"; exit 1; }

source "$ENV"

REMOTE="$APP_PATH/$FILE"

echo "Uploading $FILE → ftp://$FTP_HOST/$REMOTE ..."
curl --ftp-pasv \
  -u "$FTP_USER:$FTP_PASS" \
  "ftp://$FTP_HOST/$REMOTE" \
  -T "$DIR/$FILE" \
  --silent --show-error
echo "Done: $FILE uploaded at $(date '+%H:%M:%S')"

# Refresh matching browser tabs
case "$FILE" in
  student.html)
    osascript 2>/dev/null <<'AS'
      tell application "Google Chrome"
        repeat with w in windows
          repeat with t in tabs of w
            if URL of t contains "nicolaecatrina.com/student" then reload t
          end repeat
        end repeat
      end tell
AS
    ;;
  yoga.html)
    osascript 2>/dev/null <<'AS'
      tell application "Google Chrome"
        repeat with w in windows
          repeat with t in tabs of w
            if URL of t contains "nicolaecatrina.com/app/yoga" then reload t
          end repeat
        end repeat
      end tell
AS
    ;;
esac
