#!/usr/bin/env bash
# Runs the whole suite: php -l, PHP harness, Chromium browser tests.
# No npm install: it only needs `node`, `php`, and an existing Playwright
# (resolved via NODE_PATH if it is not already on node's path). Never runs
# `playwright install`.
set -u
cd "$(dirname "$0")/.."
status=0

echo "== php -l =="
for f in bubble-cursor/*.php tests/*.php; do php -l "$f" || status=1; done

echo "== PHP harness =="
php tests/php-harness.php || status=1

echo "== Flux Family Kit test =="
# The kit test looks for the kit in ../includes/flux-family, but this plugin keeps it in
# bubble-cursor/includes/flux-family. Run an unmodified copy of the test from a scratch dir
# whose includes/ points at the plugin folder's includes/.
KIT_TMP="$(mktemp -d)"
mkdir -p "$KIT_TMP/tests"
cp tests/test-family-kit.php "$KIT_TMP/tests/"
ln -s "$PWD/bubble-cursor/includes" "$KIT_TMP/includes"
php "$KIT_TMP/tests/test-family-kit.php" || status=1
rm -rf "$KIT_TMP"

echo "== JS syntax =="
for f in bubble-cursor/assets/js/*.js tests/run.js; do node --check "$f" || status=1; done

echo "== Browser tests (Chromium) =="
if ! node -e "require('playwright')" 2>/dev/null; then
  for d in "${NODE_PATH:-}" /opt/node-tools/node_modules /usr/local/lib/node_modules /usr/lib/node_modules; do
    [ -n "$d" ] && [ -d "$d/playwright" ] && export NODE_PATH="$d${NODE_PATH:+:$NODE_PATH}" && break
  done
fi
node tests/run.js || status=1

[ $status -eq 0 ] && echo "ALL GREEN" || echo "FAILURES (see above)"
exit $status
