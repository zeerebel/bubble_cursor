# Bubble Cursor — tests

These verify the plugin without a full WordPress install. There is **no build
step**. Run them before every release (see `../HANDOFF.md` §8–9).

```
tests/
  php/bridge-test.php      PHP: defaults, sanitization/clamping, typed-JSON bridge
  php/render-test.php      PHP: renders the WHOLE settings page under stubbed WP,
                           fails on ANY notice/warning (catches a missing default, etc.)
  fixtures/harness.html    Front-end fixture (loads the real plugin assets; reads window.__BC)
  playwright/run.mjs       Headless-Chromium front-end suite
```

## 1. PHP tests (no browser, no dependencies)

```bash
php tests/php/bridge-test.php     # -> RESULT: ALL PASSED
php tests/php/render-test.php     # -> RESULT: ALL PASSED
```

They stub the handful of WordPress functions the plugin uses, load
`bubble-cursor/bubble-cursor.php`, and assert behaviour. `render-test.php` runs
with `error_reporting(E_ALL)` and throws on any notice — so a forgotten default
or a broken tag fails the test immediately.

## 2. Front-end tests (headless Chromium via Playwright)

Needs Node, `playwright-core`, and a Chromium/Chrome binary (browsers are **not**
downloaded — point `CHROME` at one).

```bash
# 1) serve the repo root so /bubble-cursor/... and /demo/... resolve
php -S 127.0.0.1:8765

# 2) in another shell, from a dir where playwright-core is installed:
npm i -D playwright-core
CHROME=/path/to/chromium  BASE=http://127.0.0.1:8765  node /abs/path/to/tests/playwright/run.mjs
```

Env vars (both optional):
- `CHROME` — Chromium/Chrome binary path (default `/opt/pw-browsers/chromium-1194/chrome-linux/chrome`).
- `BASE` — server base URL (default `http://127.0.0.1:8765`).

> Note on module resolution: `run.mjs` does `import { chromium } from 'playwright-core'`.
> ESM resolves that from `node_modules` up the tree **of the script's location**, so
> either install `playwright-core` at the repo root, or run a copy of the script from a
> folder that has it. The fixture/demo URLs are absolute against `BASE`, so the script
> can live anywhere.

It launches with SwiftShader so WebGL works headless, then checks:
smoke renders (no shader errors) · the "View" hover bubble · ring converges to the
pointer · palette colour paints green · auto-contrast difference-blend · image
preview shows/clears · magnetic morph + revert. Exits non-zero on any failure.

## What "good" looks like

All three commands end with `RESULT: ALL PASSED` and exit code 0. That was the
bar for every change in this project — keep it.
