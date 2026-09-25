#!/bin/bash
# Usage: bash llm-switch.sh free   [claude args...]  — OpenRouter free models on branch openrouter-branch
#        bash llm-switch.sh claude [claude args...]  — normal Claude (subscription / Anthropic API)
#        bash llm-switch.sh status                   — show current branch + which backend env would be used
#
# Launches Claude Code with the right backend. A running session cannot change its own model,
# so switching always means (re)launching through this script.
# OpenRouter key + model live in .openrouter.env (gitignored), next to this script:
#   OPENROUTER_API_KEY=sk-or-...
#   OPENROUTER_MODEL=qwen/qwen3-coder:free        # optional; any ":free" model from openrouter.ai/models
#   OPENROUTER_SMALL_MODEL=qwen/qwen3-coder:free  # optional; background/fast model, defaults to OPENROUTER_MODEL

set -euo pipefail

MODE="${1:-status}"; shift || true
DIR="$(cd "$(dirname "$0")" && pwd)"
ENV="$DIR/.openrouter.env"
FREE_BRANCH="openrouter-branch"

cd "$DIR"

clear_backend() {
  unset ANTHROPIC_BASE_URL ANTHROPIC_AUTH_TOKEN ANTHROPIC_MODEL \
        ANTHROPIC_DEFAULT_OPUS_MODEL ANTHROPIC_DEFAULT_SONNET_MODEL ANTHROPIC_DEFAULT_HAIKU_MODEL \
        ANTHROPIC_SMALL_FAST_MODEL CLAUDE_CODE_SUBAGENT_MODEL
}

switch_branch() {
  local target="$1"
  local current; current="$(git rev-parse --abbrev-ref HEAD)"
  [[ "$current" == "$target" ]] && return 0
  if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    echo "Uncommitted changes on $current — commit or stash them before switching to $target."; exit 1
  fi
  git fetch -q origin 2>/dev/null || echo "(offline — using local branches)"
  if git show-ref -q --verify "refs/heads/$target"; then
    git checkout -q "$target"
  elif git show-ref -q --verify "refs/remotes/origin/$target"; then
    git checkout -q -b "$target" --track "origin/$target"
  else
    git checkout -q -b "$target" origin/master 2>/dev/null || git checkout -q -b "$target" master
    echo "Created $target from master (push it with: git push -u origin $target)"
  fi
  echo "Branch: $target"
}

case "$MODE" in
  free)
    [[ ! -f "$ENV" ]] && { echo "Missing $ENV (needs OPENROUTER_API_KEY=sk-or-...)"; exit 1; }
    source "$ENV"
    [[ -z "${OPENROUTER_API_KEY:-}" ]] && { echo "OPENROUTER_API_KEY empty in $ENV"; exit 1; }
    MODEL="${OPENROUTER_MODEL:-qwen/qwen3-coder:free}"
    SMALL="${OPENROUTER_SMALL_MODEL:-$MODEL}"
    switch_branch "$FREE_BRANCH"
    clear_backend
    export ANTHROPIC_BASE_URL="https://openrouter.ai/api"
    export ANTHROPIC_AUTH_TOKEN="$OPENROUTER_API_KEY"
    export ANTHROPIC_API_KEY=""
    export ANTHROPIC_MODEL="$MODEL"
    export ANTHROPIC_DEFAULT_OPUS_MODEL="$MODEL"
    export ANTHROPIC_DEFAULT_SONNET_MODEL="$MODEL"
    export ANTHROPIC_DEFAULT_HAIKU_MODEL="$SMALL"
    export CLAUDE_CODE_SUBAGENT_MODEL="$MODEL"
    export NC_LLM_MODE="free"
    echo "Backend: OpenRouter → $MODEL"
    exec claude "$@"
    ;;
  claude)
    clear_backend
    export NC_LLM_MODE="claude"
    echo "Backend: Claude (branch $(git rev-parse --abbrev-ref HEAD))"
    exec claude "$@"
    ;;
  status)
    echo "Branch:  $(git rev-parse --abbrev-ref HEAD)"
    echo "Backend: ${ANTHROPIC_BASE_URL:-Claude (default)}  model: ${ANTHROPIC_MODEL:-default}"
    [[ -f "$ENV" ]] && echo "OpenRouter key file: present" || echo "OpenRouter key file: missing ($ENV)"
    ;;
  *)
    echo "Usage: bash llm-switch.sh free|claude|status [claude args...]"; exit 1
    ;;
esac
