# Bubble Cursor: working notes for Claude

This file is read automatically by Claude Code at the start of a session. Read it fully, then `HANDOFF.md` / `CROSS-BROWSER-AUDIT.md` if they exist in this repo.

- **Repo:** `zeerebel/bubble_cursor`  **Version:** 1.3.1  **Open draft PR:** zeerebel/bubble_cursor#7 (branch `claude/vigilant-cannon-o5rz3s`; nothing merged to `main` yet)

## What this is

A custom cursor: a dot and ring follower plus an optional WebGL fluid/smoke trail (`fluid-cursor.js`). Plugin folder is `bubble-cursor/`. The Reframe theme has a built-in bubble cursor; only one should be active.

## Where things are

- `bubble-cursor/bubble-cursor.php`, `assets/js/bubble-cursor.js`, `assets/js/fluid-cursor.js`, `assets/css/bubble-cursor.css`; filter `bubble_cursor_should_load`; constant `BUBBLE_CURSOR_VERSION`.
- **Do not modify** `Exposure.html`, the `demo/` images or the screenshot jpeg (owner reference material).

## How to test

- `./tests/run.sh` (php -l, PHP harness, node --check, Chromium suite; 98 PHP + 160 browser checks + the kit test (9 scenarios)). See `tests/README.md`.

## Debugging

- `DEBUGGING.md` at the repo root is the owner's debugging guide (file map, data flow, failure-mode checklist, discrepancies found). Keep it in step with the code; it is outside the plugin zip.

## Flux Family Kit

Since 1.3.0 the plugin carries the shared family kit: `bubble-cursor/includes/flux-family/loader.php` and `core.php` (kit 1.0.0), `tests/test-family-kit.php`, and `FAMILY.md` at the repo root (read it). The main file requires the loader, offers the core, registers slug `flux-bubble-cursor` (group `cursor`) and prints the card at the foot of the settings page with `flux_family_render_card( 'Flux Bubble Cursor' )`. The kit loads in wp-admin only. The plugin lives in the `bubble-cursor/` subfolder, so the kit must stay inside it; `tests/run.sh` runs an unmodified copy of the kit test from a scratch dir (the test expects `../includes/flux-family`). **Do not edit the copied kit files here: edit them in the family hub (`flux-family-hub`) and sync** (`FLUX_REPO_ROOT=<repo root> tools/sync-family.sh <repo>/bubble-cursor`). The repo now has a root `LICENSE` (GPL-2.0).

## Current state (as of Nov 2026)

Audit (12 findings) fixed in 1.2.0: visible state no longer lives only in `<html>` classes, settings via `data-bc-settings`, optimizer opt-outs, native cursor hidden only after the follower exists, bfcache restore, builder guards, once-only guard, touch-laptop support (dormant until a real mouse moves), idle smoke loop sleeps, WebGL context recovery, cache purge on save, multisite uninstall. `CROSS-BROWSER-AUDIT.md` has the findings and a 'Resolved in 1.2.0' section.

## 1.3.1 (what changed)

Hover word is real (`hover_text` switch + `hover_label`, default "View"; opt-outs `data-bubble-cursor-no-text` / empty `data-bubble-cursor-text`), `ring_blend` select (JS `mixBlend`), dead `textSelector` removed, live reduced motion, one-cursor stand-down (flags `__fluxJellyActive`, `__superCursorActive`, `__sdcpJellyActive`, `__sdcpDualActive`; we set `__bubbleCursorBooted`). Build the zip with `git archive --format=zip --prefix=flux-bubble-cursor/ -o flux-bubble-cursor.zip HEAD:bubble-cursor`.

## Open items and unverified claims

- The WebGL2 half-float linear-filtering change is unverified on Safari/iOS hardware.
- Firefox, Safari, real WordPress, optimizer plugins, real bfcache not run.

## The owner and the setup

- The owner builds portfolio/agency sites on WordPress with the **Salient theme (WPBakery)**, sometimes other themes, behind cache/optimizer plugins (WP Rocket, LiteSpeed, Cloudflare Rocket Loader, Autoptimize). The plugin must work on any theme and must never break the page.
- The owner's `salient_builder` repo (separate) holds Salient's plugin sources only. The real Salient *theme* is **not available** in any repo, so Salient behaviour has only ever been emulated with hostile CSS.
- The owner works from a phone through Claude Code on the web. Keep replies short and plain: what changed, what was tested, what was not.
- **Reply convention:** the owner's own `CLAUDE.md` asks that every reply include the word "sensei". Do that if you see that file's rule in your instructions.

## The Flux family (you have siblings)

This repo is one member of the **Flux family** of WordPress plugins by the same owner. Read `FAMILY.md` (the identical file sits in every sibling repo): it explains how the plugins fit together, the shared kit, the one-cursor rule and what to check before selling. Versions below were read from each plugin header on 2026-11-26.

| Plugin | Repo | Group | Version | What it does |
| --- | --- | --- | --- | --- |
| Flux Reel | [`zeerebel/kurato`](https://github.com/zeerebel/kurato) (in the `flux-reel/` folder of the Kurato repo; move to its own `flux-reel` repo) | ui | 1.3.1 | Scroll timecode, film-style menu overlay and footer reel |
| Flux Backgrounds | [`zeerebel/flux-backgrounds`](https://github.com/zeerebel/flux-backgrounds) | visual | 0.10.0 | Animated WebGL backgrounds (12 modes) |
| Flux Preloader | [`zeerebel/flux-preloader`](https://github.com/zeerebel/flux-preloader) | visual | 1.5.1 | Intro splash that counts up while the page loads |
| Flux Reveal | [`zeerebel/flux-reveal`](https://github.com/zeerebel/flux-reveal) | visual | 1.8.1 | Blur-in scroll reveals for headings and sections |
| Flux Smooth Scroll | [`zeerebel/fluxsmoothscroll`](https://github.com/zeerebel/fluxsmoothscroll) (rename the repo to `flux-smooth-scroll`) | scroll | 1.3.1 | Weighted smooth scrolling (Lenis) |
| Flux Scroll Progress | [`zeerebel/fluxscrollprogress`](https://github.com/zeerebel/fluxscrollprogress) (rename the repo to `flux-scroll-progress`) | scroll | 1.4.1 | Slim reading-progress bar |
| Flux Jelly Cursor | [`zeerebel/fluxjellycursor`](https://github.com/zeerebel/fluxjellycursor) (rename the repo to `flux-jelly-cursor`) | cursor | 1.4.1 | Elastic jelly blob cursor |
| **Flux Bubble Cursor (you)** | [`zeerebel/bubble_cursor`](https://github.com/zeerebel/bubble_cursor) (rename the repo to `flux-bubble-cursor`) | cursor | 1.3.1 | Dot, ring and smoke-trail cursor |
| Super Duper Cursor | [`zeerebel/super-duper-cursor`](https://github.com/zeerebel/super-duper-cursor) | cursor | 3.2.0 | Dual-layer cursor that reacts to what you hover (WordPress.org edition, no shared kit) |
| Super Duper Cursor Pro | [`zeerebel/super-duper-cursor-pro`](https://github.com/zeerebel/super-duper-cursor-pro) | cursor | 4.3.1 | Dual and jelly cursor engines in one plugin |
| Flux Core | [`zeerebel/flux-core`](https://github.com/zeerebel/flux-core) | ui | 1.0.0 | Shared engine: scheduler, scroll bus, registry, animation adapter |
| Flux Split | [`zeerebel/flux-split`](https://github.com/zeerebel/flux-split) | visual | 1.1.0 | Split-text (lines/words/chars) staggered reveals; builds on Flux Core |
| Flux Marquee | [`zeerebel/flux-marquee`](https://github.com/zeerebel/flux-marquee) | visual | 1.0.0 | Infinite marquee that reacts to scroll; builds on Flux Core |
| Flux Pin | [`zeerebel/flux-pin`](https://github.com/zeerebel/flux-pin) | scroll | 1.0.0 | Pinned scroll scenes and horizontal-scroll galleries; builds on Flux Core |
| Flux Magnetic | [`zeerebel/flux-magnetic`](https://github.com/zeerebel/flux-magnetic) | ui | 1.0.0 | Magnetic hover: elements ease toward the pointer and spring back; builds on Flux Core |
| Flux Distort | [`zeerebel/flux-distort`](https://github.com/zeerebel/flux-distort) | visual | 1.0.0 | WebGL hover distortion for images; builds on Flux Core |

Not in the family:
- `super-duper-cursor-v2.0.4a`: GSAP edition of the dual cursor (3.0.x). **Not in the family:** superseded, archive recommended. Never run it next to `super-duper-cursor` (same function names, fatal).
- `super-cursor-hybrid`: Earlier GSAP cursor. **Not in the family:** archived/superseded.
- `TM-Pointer`: A third-party CodeCanyon plugin (author egemenerd). **Not in the family**, not yours to publish.

### How you relate to your siblings

- One of four cursor plugins: only ONE may be active. The Reframe theme has the same bubble cursor built in (Cursor Style "Bubble"): use the theme style or this plugin, never both.
- Its plugin lives in the `bubble-cursor/` subfolder (the kit is in `bubble-cursor/includes/flux-family/`); the repo root also holds owner reference files that must not be changed.

### Working across the family

- A change here can matter to a sibling: if you touch scrolling, z-index, cursor hiding, admin menus or the shared kit, read the sibling rows above and say in your report which ones you checked.
- **The shared kit** (`includes/flux-family/` in each plugin, except `super-duper-cursor`) is the same two files everywhere. Do not edit your copy alone: change one copy, run `php tests/test-family-kit.php`, copy the two files and the test to every sibling, bump the kit version in `core.php`, and re-run each sibling's suite. The family docs are regenerated from one script (`tools/refresh-family-docs.py` in the `flux-family-hub` folder if present, otherwise ask the owner).
- Names: the final names in the table are the target; until a repo is renamed on GitHub, the repo name above is the live one.

### Rules that apply across the family

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
