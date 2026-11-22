# Bubble Cursor: working notes for Claude

This file is read automatically by Claude Code at the start of a session. Read it fully, then `HANDOFF.md` / `CROSS-BROWSER-AUDIT.md` if they exist in this repo.

- **Repo:** `zeerebel/bubble_cursor`  **Version:** 1.2.0  **Open draft PR:** zeerebel/bubble_cursor#7 (branch `claude/vigilant-cannon-o5rz3s`; nothing merged to `main` yet)

## What this is

A custom cursor: a dot and ring follower plus an optional WebGL fluid/smoke trail (`fluid-cursor.js`). Plugin folder is `bubble-cursor/`. The Reframe theme has a built-in bubble cursor; only one should be active.

## Where things are

- `bubble-cursor/bubble-cursor.php`, `assets/js/bubble-cursor.js`, `assets/js/fluid-cursor.js`, `assets/css/bubble-cursor.css`; filter `bubble_cursor_should_load`; constant `BUBBLE_CURSOR_VERSION`.
- **Do not modify** `Exposure.html`, the `demo/` images or the screenshot jpeg (owner reference material).

## How to test

- `./tests/run.sh` (php -l, PHP harness, node --check, Chromium suite; 63 PHP + 111 browser checks). See `tests/README.md`.

## Current state (as of Nov 2026)

Audit (12 findings) fixed in 1.2.0: visible state no longer lives only in `<html>` classes, settings via `data-bc-settings`, optimizer opt-outs, native cursor hidden only after the follower exists, bfcache restore, builder guards, once-only guard, touch-laptop support (dormant until a real mouse moves), idle smoke loop sleeps, WebGL context recovery, cache purge on save, multisite uninstall. `CROSS-BROWSER-AUDIT.md` has the findings and a 'Resolved in 1.2.0' section.

## Open items and unverified claims

- The WebGL2 half-float linear-filtering change is unverified on Safari/iOS hardware.
- Firefox, Safari, real WordPress, optimizer plugins, real bfcache not run.

## The owner and the setup

- The owner builds portfolio/agency sites on WordPress with the **Salient theme (WPBakery)**, sometimes other themes, behind cache/optimizer plugins (WP Rocket, LiteSpeed, Cloudflare Rocket Loader, Autoptimize). The plugin must work on any theme and must never break the page.
- The owner's `salient_builder` repo (separate) holds Salient's plugin sources only. The real Salient *theme* is **not available** in any repo, so Salient behaviour has only ever been emulated with hostile CSS.
- The owner works from a phone through Claude Code on the web. Keep replies short and plain: what changed, what was tested, what was not.
- **Reply convention:** the owner's own `CLAUDE.md` asks that every reply include the word "sensei". Do that if you see that file's rule in your instructions.

## The Flux plugin suite (sibling repos, all by the same owner)

- `zeerebel/kurato` (flux-reel/): Flux Reel: scroll timecode, menu overlay with preview monitor, footer reel
- `zeerebel/flux-backgrounds` (repo root): Animated WebGL backgrounds
- `zeerebel/flux-preloader` (repo root): Intro preloader
- `zeerebel/flux-reveal` (repo root): Blur-in scroll reveals
- `zeerebel/fluxjellycursor` (repo root): Jelly cursor
- `zeerebel/fluxscrollprogress` (repo root): Reading-progress bar
- `zeerebel/fluxsmoothscroll` (repo root): Smooth scroll (Lenis)
- `zeerebel/bubble_cursor` (bubble-cursor/): Bubble/smoke cursor
- `zeerebel/super-duper-cursor-v2.0.4a` (repo root): Dual cursor, GSAP-based (v3.0.x line)
- `zeerebel/super-duper-cursor-pro` (repo root): Dual (library-free) + jelly cursor (v4.x line)

All were audited for cross-browser and host-theme robustness in Nov 2026, then fixed from those audits. The recurring weaknesses found across the suite, and now the **checklist for any change here**:

1. **State kept only in an `<html>` class gets wiped** by theme scripts. Set markers from the plugin's own script, keep them with a `MutationObserver`, or key behaviour off the plugin's own elements/attributes.
2. **Settings must not depend on a separate inline script** that optimizers strip or delay. Ship settings as a `data-*` attribute / JSON node, read the old global only as a fallback, and have built-in defaults.
3. **Optimizer opt-outs** on the plugin's own `<script>`/`<style>` tags via `script_loader_tag`: `data-no-optimize="1" data-no-defer="1" data-cfasync="false" data-nowprocket data-pagespeed-no-defer data-no-minify`.
4. **Never hide something native (cursor, page content, scroll) until the replacement is confirmed running**, and always fail safe (visible, scrollable, clickable) if JS or CSS is stripped, delayed or throws.
5. **Skip page-builder editors and previews**: `is_customize_preview()`, `vc_editable`, `vc_action`, `elementor-preview`, `et_fb`, `fl_builder`, `bricks`, `ct_builder`, `is_embed()`, `is_feed()`. Keep the plugin's own `*_should_load` filter as the last word.
6. **Guard against two copies** (constants, class, functions) so a second copy cannot fatal; guard the JS against double init.
7. **Beat host CSS safely**: prefixed classes, `:where()` resets, `:is(.x, #boost)` boosts only where proven necessary. Salient styles `body[data-form-style] button/input`, global `img`/`a`, and has `#header-outer` at z-index 9998.
8. Respect `prefers-reduced-motion` (live, not just at load), touch devices, RTL, print.
9. Multisite-aware `uninstall.php`. Do not leave work running while idle or off-screen.
10. Do not overwrite a host's globals (e.g. `window.gsap`, `window.THREE`, AMD `define`).

## Environment notes (Claude Code on the web)

- Only **Chromium** is installed. `require('playwright')` works globally. **Never run `playwright install`.** Firefox, Safari/WebKit, Samsung Internet and real iOS can **not** be run here: label anything about them "by code reading, not run". Do not claim cross-browser coverage you did not run.
- Use the ports named in this repo's tests; keep throwaway files in the session scratchpad, not the repo.
- No real WordPress/Salient/optimizer plugin is available unless this repo says otherwise.
- When a server must outlive a shell command, start it with `setsid ... </dev/null &` in its own call; do not `pkill -f` a pattern that matches your own command line.
- npm registry is reachable (`npm pack <pkg>` into a fresh empty directory is fine for checking a vendored library).

## Working agreements

- Work on a `claude/...` branch. **Never push to `main`.** The branch `claude/vigilant-cannon-o5rz3s` carries the audit, the fixes and the open draft PR listed above; continue there unless told otherwise. Open PRs as **drafts**.
- Commit messages end with the trailer lines the session gives you. Do not put model names in commit messages, PR text, code comments or docs.
- GitHub posts (comments, reviews) end with the attribution footer the session specifies; only comment when the owner asks.
- Run this repo's tests before every push and report the real results. If something was not run or not verified, say so. Never skip, disable or loosen a test to get green.
- Keep `README.md`, `readme.txt`, `CHANGELOG.md` and `HANDOFF.md` (where present) in step with the code, and bump the version in the plugin header **and** its constant together.
