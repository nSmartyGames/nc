#!/bin/bash
# SessionStart hook for Claude Code on the web.
# The repo has no package manifests (shell + PHP + Python + static HTML), so this
# only ensures the lint/check tooling exists: shellcheck, php-cli, jq, curl.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

missing=()
command -v shellcheck >/dev/null 2>&1 || missing+=(shellcheck)
command -v php        >/dev/null 2>&1 || missing+=(php-cli)
command -v jq         >/dev/null 2>&1 || missing+=(jq)
command -v curl       >/dev/null 2>&1 || missing+=(curl)

if [ ${#missing[@]} -gt 0 ]; then
  SUDO=""; [ "$(id -u)" -ne 0 ] && SUDO="sudo"
  export DEBIAN_FRONTEND=noninteractive
  $SUDO apt-get update -qq
  $SUDO apt-get install -y -qq "${missing[@]}"
fi
