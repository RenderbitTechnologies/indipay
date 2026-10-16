#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
composer install --no-interaction --prefer-dist
