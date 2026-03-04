#!/usr/bin/env bash
set -euo pipefail

BRANCH="${BRANCH:-main}"
REMOTE_HOST="${REMOTE_HOST:-ubuntu@158.69.210.127}"
REMOTE_PATH="${REMOTE_PATH:-/var/www/gigtune}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

if ! command -v git >/dev/null 2>&1; then
  echo "git is required."
  exit 1
fi

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "Run this script from inside the project git repository."
  exit 1
fi

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "${CURRENT_BRANCH}" != "${BRANCH}" ]]; then
  echo "You are on branch '${CURRENT_BRANCH}', expected '${BRANCH}'."
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Working tree is not clean. Commit or stash changes first."
  exit 1
fi

echo "Pushing ${BRANCH} to origin..."
git push origin "${BRANCH}"

echo "Deploying to ${REMOTE_HOST}:${REMOTE_PATH}..."
ssh "${REMOTE_HOST}" "set -euo pipefail; \
  cd '${REMOTE_PATH}'; \
  git fetch origin '${BRANCH}'; \
  git checkout '${BRANCH}'; \
  git pull --ff-only origin '${BRANCH}'; \
  ${COMPOSER_BIN} install --no-dev --optimize-autoloader; \
  ${PHP_BIN} artisan migrate --force; \
  ${PHP_BIN} artisan optimize:clear; \
  ${PHP_BIN} artisan optimize; \
  ${PHP_BIN} artisan queue:restart; \
  ${PHP_BIN} artisan gigtune:db-integrity-check || true"

echo "Deploy complete."
