#!/bin/sh
# ish.sh — control this repo (git + deploy) from iSH on iOS (Alpine / busybox sh).
#
# First run on the iPhone/iPad (inside iSH):
#   apk add git curl && git clone https://github.com/nSmartyGames/nc && cd nc
#   sh ish.sh setup
#
# Then:  sh ish.sh help
#
# Secrets live in .ish.env (gitignored) — never commit it:
#   GIT_NAME=...            GIT_EMAIL=...
#   GITHUB_USER=...         GITHUB_TOKEN=...   (fine-grained PAT, Contents: read/write on nSmartyGames/nc)
#   VERCEL_TOKEN=...        (vercel.com/account/tokens)
#   VERCEL_PROJECT=nc       VERCEL_TEAM_ID=    (team_… only for team projects)
#   VERCEL_DEPLOY_HOOK=...  (Project → Settings → Git → Deploy Hooks)
#   VERCEL_FILES="index.html student.html"     (optional; default = tracked static files)
# FTP deploy (update.sh) keeps using .deploy.prod.env as before.

set -eu

DIR="$(cd "$(dirname "$0")" && pwd)"
ENV="$DIR/.ish.env"
API="https://api.vercel.com"
[ -f "$ENV" ] && . "$ENV"

say()  { printf '\033[33m▸\033[0m %s\n' "$*"; }
die()  { printf '\033[31m✗\033[0m %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || die "missing '$1' — run: sh ish.sh setup"; }
g()    { git -C "$DIR" "$@"; }
branch() { g rev-parse --abbrev-ref HEAD; }

# Files that must never be staged or deployed
SECRET_RE='(^|/)(\.deploy\.prod\.env|\.ish\.env|id_rsa(\.pub)?|sftp\.duck)$|\.mobileconfig$'

# ---------------------------------------------------------------- setup
cmd_setup() {
  if command -v apk >/dev/null 2>&1; then
    say "Installing packages (git curl jq bash openssh coreutils)…"
    apk add -q git curl jq bash openssh-client coreutils ca-certificates
  fi
  if [ ! -f "$ENV" ]; then
    cat >"$ENV" <<'EOF'
GIT_NAME=""
GIT_EMAIL=""
GITHUB_USER=""
GITHUB_TOKEN=""
VERCEL_TOKEN=""
VERCEL_PROJECT="nc"
VERCEL_TEAM_ID=""
VERCEL_DEPLOY_HOOK=""
VERCEL_FILES=""
EOF
    chmod 600 "$ENV"
    say "Created .ish.env — fill it in (vi .ish.env), then run: sh ish.sh setup  again"
    return
  fi
  grep -qx '.ish.env' "$DIR/.gitignore" 2>/dev/null || echo '.ish.env' >>"$DIR/.gitignore"
  [ -n "${GIT_NAME:-}" ]  && g config user.name  "$GIT_NAME"
  [ -n "${GIT_EMAIL:-}" ] && g config user.email "$GIT_EMAIL"
  g config pull.rebase true
  g config core.fileMode false   # iOS Files app mangles exec bits
  if [ -n "${GITHUB_TOKEN:-}" ]; then
    # Token is supplied per-command via a credential helper that reads .ish.env,
    # so it is never written into .git/config or ~/.git-credentials.
    g config credential.https://github.com.helper \
      "!f() { . \"$ENV\"; echo username=\${GITHUB_USER:-x-access-token}; echo password=\$GITHUB_TOKEN; }; f"
    say "GitHub HTTPS auth configured from .ish.env"
  fi
  say "Setup done. Try: sh ish.sh status"
}

# ---------------------------------------------------------------- git
cmd_status() { g fetch -q origin 2>/dev/null || true; g status -sb; }
cmd_log()    { g log --oneline --graph -n "${1:-15}"; }
cmd_diff()   { g --no-pager diff --stat; g diff "$@"; }
cmd_pull()   { g pull --rebase --autostash origin "$(branch)"; }

cmd_branch() {
  if [ $# -eq 0 ]; then g branch -a; return; fi
  g fetch -q origin "$1" 2>/dev/null || true
  if g show-ref -q --verify "refs/heads/$1"; then g checkout "$1"
  elif g show-ref -q --verify "refs/remotes/origin/$1"; then g checkout -b "$1" --track "origin/$1"
  else g checkout -b "$1"; fi
}

# save "message" [files…]  — stage, commit, push (with retry)
cmd_save() {
  [ $# -ge 1 ] || die 'usage: sh ish.sh save "commit message" [files…]'
  msg="$1"; shift
  if [ $# -gt 0 ]; then g add -- "$@"; else g add -A; fi
  bad=$(g diff --cached --name-only | grep -E "$SECRET_RE" || true)
  if [ -n "$bad" ]; then
    g reset -q -- $bad
    die "refused to commit secrets: $bad"
  fi
  g diff --cached --quiet && { say "Nothing to commit."; return; }
  g commit -m "$msg"
  cmd_push
}

cmd_push() {
  b=$(branch); n=0; wait=2
  until g push -u origin "$b"; do
    n=$((n+1)); [ $n -ge 4 ] && die "push failed after 4 retries"
    say "push failed, retry in ${wait}s…"; sleep $wait; wait=$((wait*2))
  done
}

cmd_undo() { g reset --soft HEAD~1 && say "Last commit undone (changes kept staged)."; }

# ---------------------------------------------------------------- FTP (existing server)
cmd_ftp() {
  [ $# -ge 1 ] || die "usage: sh ish.sh ftp <file> [file…]"
  need bash
  for f in "$@"; do bash "$DIR/update.sh" "$f"; done
}

# ---------------------------------------------------------------- Vercel
vq() { [ -n "${VERCEL_TEAM_ID:-}" ] && echo "?teamId=$VERCEL_TEAM_ID" || true; }
vauth() { [ -n "${VERCEL_TOKEN:-}" ] || die "VERCEL_TOKEN not set in .ish.env"; need jq; }

vercel_files() {
  if [ -n "${VERCEL_FILES:-}" ]; then echo "$VERCEL_FILES" | tr ' ' '\n'
  else
    # Static assets only: PHP can't run on Vercel; CSV/JSON/xlsx hold student data.
    g ls-files | grep -E '\.(html|css|js|png|jpe?g|gif|svg|webp|ico|woff2?)$'
  fi | grep -vE "$SECRET_RE|\.(csv|json|xlsx|php|py|sh|env)$" | grep -v '^backups/'
}

# vercel [prod]  — upload files via REST API and create a deployment (no Node needed)
cmd_vercel() {
  vauth
  target=preview; [ "${1:-}" = prod ] && target=production
  manifest=$(mktemp)
  echo '[]' >"$manifest"
  for f in $(vercel_files); do
    [ -f "$DIR/$f" ] || { say "skip missing $f"; continue; }
    sha=$(sha1sum "$DIR/$f" | cut -d' ' -f1)
    size=$(wc -c <"$DIR/$f" | tr -d ' ')
    printf '  ↑ %s (%s B)\n' "$f" "$size"
    code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/v2/files$(vq)" \
      -H "Authorization: Bearer $VERCEL_TOKEN" \
      -H "Content-Type: application/octet-stream" \
      -H "x-vercel-digest: $sha" --data-binary "@$DIR/$f")
    case "$code" in 200|201) ;; *) rm -f "$manifest"; die "upload $f failed (HTTP $code)";; esac
    jq --arg f "$f" --arg s "$sha" --argjson n "$size" \
      '. + [{file:$f, sha:$s, size:$n}]' "$manifest" >"$manifest.t" && mv "$manifest.t" "$manifest"
  done
  body=$(jq -n --arg name "${VERCEL_PROJECT:-nc}" --arg t "$target" \
    --arg sha "$(g rev-parse HEAD)" --arg br "$(branch)" --slurpfile files "$manifest" \
    '{name:$name, project:$name, files:$files[0], projectSettings:{framework:null},
      meta:{githubCommitSha:$sha, githubCommitRef:$br}}
     + (if $t=="production" then {target:"production"} else {} end)')
  rm -f "$manifest"
  say "Creating $target deployment…"
  res=$(curl -s -X POST "$API/v13/deployments$(vq)" \
    -H "Authorization: Bearer $VERCEL_TOKEN" -H "Content-Type: application/json" -d "$body")
  url=$(echo "$res" | jq -r '.url // empty')
  [ -n "$url" ] || die "deploy failed: $(echo "$res" | jq -c '.error // .')"
  say "https://$url  (state: $(echo "$res" | jq -r .readyState))"
}

# hook — trigger the Git-integration build via a Deploy Hook URL
cmd_hook() {
  [ -n "${VERCEL_DEPLOY_HOOK:-}" ] || die "VERCEL_DEPLOY_HOOK not set in .ish.env"
  curl -s -X POST "$VERCEL_DEPLOY_HOOK" | { command -v jq >/dev/null && jq . || cat; }
}

# vstatus [n] — latest deployments of the project
cmd_vstatus() {
  vauth
  sep='?'; [ -n "${VERCEL_TEAM_ID:-}" ] && sep='&'
  curl -s "$API/v6/deployments$(vq)${sep}limit=${1:-5}&app=${VERCEL_PROJECT:-nc}" \
    -H "Authorization: Bearer $VERCEL_TOKEN" |
    jq -r '.deployments[]? | "\(.state)\t\(.target // "preview")\t\(.url)\t\(.meta.githubCommitRef // "-")"'
}

# ship "message" [prod] — save + push, then deploy to Vercel
cmd_ship() {
  [ $# -ge 1 ] || die 'usage: sh ish.sh ship "commit message" [prod]'
  cmd_save "$1"
  if [ -n "${VERCEL_TOKEN:-}" ]; then cmd_vercel "${2:-}"
  elif [ -n "${VERCEL_DEPLOY_HOOK:-}" ]; then cmd_hook
  else say "No Vercel credentials — pushed only (Git integration will build if connected)."; fi
}

cmd_help() {
  cat <<'EOF'
sh ish.sh <command>

 setup                    install packages, create/apply .ish.env
 status | log [n] | diff  inspect
 pull                     pull --rebase current branch
 branch [name]            list, or switch/create branch
 save "msg" [files…]      add + commit + push (blocks secret files)
 push | undo              push with retry | undo last local commit

 ftp <file…>              deploy to nicolaecatrina.com/app (update.sh)
 vercel [prod]            deploy static files via Vercel API (token)
 hook                     trigger Vercel Deploy Hook (Git integration)
 vstatus [n]              list recent Vercel deployments
 ship "msg" [prod]        save + push + vercel deploy
EOF
}

c="${1:-help}"; [ $# -gt 0 ] && shift
case "$c" in
  setup|status|log|diff|pull|branch|save|push|undo|ftp|vercel|hook|vstatus|ship|help) "cmd_$c" "$@" ;;
  *) cmd_help; exit 1 ;;
esac
