#!/usr/bin/env bash
set -euo pipefail

if [[ ! -f composer.json ]]; then
  echo "Run from repo root" >&2
  exit 1
fi

composer install --no-dev --optimize-autoloader
php bin/console install "$@"
