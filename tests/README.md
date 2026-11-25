# Tests

Chromium only. Firefox, Safari and iOS WebKit are **not** run by this suite
(they are not available in the dev environment); anything about them is by code
reading only.

```bash
./tests/run.sh
```

It runs, in order: `php -l` on every PHP file, the PHP harness, the Flux Family Kit test, `node --check`
on every JS file, then the browser suite. It exits non-zero on any failure.

Needs `php` (CLI), `node`, and Playwright for Node that already has a Chromium
installed (it resolves `playwright` from `NODE_PATH` or a few common locations).
There is no `npm install` and the suite never runs `playwright install`.

* `tests/run.js` serves the real, unmodified plugin CSS/JS from a tiny local
  HTTP server (default port 9190, override with `BC_TEST_PORT`) inside a
  fixture page with hostile Salient-like host CSS. It is not a real WordPress
  render. Covered: desktop 1440x800, iPhone 13 / Pixel 7 / iPad gen 7 /
  iPad Pro 11 emulation, desktop with `hasTouch`, reduced motion, RTL, scroll,
  resize, className rewrites, stripped inline settings, double load,
  synthetic `pagehide`/`pageshow`, WebGL context loss/restore, idle loop.
* `tests/php-harness.php` includes `bubble-cursor.php` twice with stubbed
  WordPress functions and exercises `sanitize()`, `should_load()` (editor query
  args, Elementor/WPBakery/Customizer), the script tag filter, cache purge and
  `uninstall.php` (single site and multisite).

* `tests/test-family-kit.php` is the shared Flux Family Kit test (9 scenarios,
  identical in every Flux repo, do not edit). It expects the kit at
  `../includes/flux-family`, so `run.sh` runs an unmodified copy of it from a
  scratch directory whose `includes/` links to `bubble-cursor/includes/`.
  Run it by hand the same way, or `php tests/test-family-kit.php` after pointing
  `includes/` at the plugin folder's.

Software GL (swiftshader) makes `requestAnimationFrame` slow, so the tests poll
for the ring to converge instead of using fixed sleeps. Real bfcache is not
exercised (automation disables it); only synthetic events.
