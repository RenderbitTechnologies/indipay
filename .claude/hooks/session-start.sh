#!/bin/bash
# Installs composer dependencies in Claude Code cloud sessions without blocking session startup.
#
# The install runs detached in the background: SessionStart hooks are synchronous, and a full
# `composer install` in the cloud can take minutes (dist zipballs from api.github.com may be
# refused by the session's GitHub proxy, making composer fall back to cloning each package),
# which left new sessions stuck before Claude's first turn.

# Only run in Claude Code cloud sessions
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "$CLAUDE_PROJECT_DIR" || exit 0

log=/tmp/composer-install.log
marker=vendor/.session-start.sha256
inputs=(composer.json composer.lock .claude/hooks/session-start.sh)

# Skip when dependencies were already installed from the same inputs
if [ -f vendor/autoload.php ] && sha256sum --check --status "$marker" 2>/dev/null; then
  echo "Composer dependencies are already installed (vendor/ is up to date)."
  exit 0
fi

# Delete the old marker first, so a failed install runs again next time
rm -f "$marker"

# install (not update) so versions pinned in composer.lock are used.
# All file descriptors are redirected so the hook returns immediately instead of waiting on the child.
setsid nohup bash -c '
  export COMPOSER_NO_INTERACTION=1 GIT_TERMINAL_PROMPT=0
  if timeout 900 composer install --no-interaction --prefer-dist --no-progress; then
    sha256sum "$@" > '"$marker"' && echo "composer install finished"
  else
    echo "composer install FAILED (exit $?)"
  fi
' _ "${inputs[@]}" </dev/null >"$log" 2>&1 &

echo "composer install is running in the background (log: $log). vendor/ is ready once $marker exists; wait for it before running phpunit."
exit 0
