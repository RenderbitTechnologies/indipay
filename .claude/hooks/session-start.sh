#!/bin/bash
set -euo pipefail

# Only run in Claude Code cloud sessions
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "$CLAUDE_PROJECT_DIR"

# install (not update) so versions pinned in composer.lock are used and the cached container state is reused across sessions
composer install --no-interaction --prefer-dist --no-progress
