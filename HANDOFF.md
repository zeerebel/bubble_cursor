# Bubble Cursor — Handoff

<!-- handoff:start -->
## START HERE: current status and how to resume (updated Nov 22, 2026)

**Bubble Cursor**, version **1.3.1**, repo `zeerebel/bubble_cursor`, open draft PR `zeerebel/bubble_cursor#7` on branch `claude/vigilant-cannon-o5rz3s` (not merged to `main`). If you were told to "read the handoff", read this block, then `CLAUDE.md` (shared rules and the Flux-suite hardening checklist), then `CROSS-BROWSER-AUDIT.md` if present, then the rest of this file.

<!-- family:start -->
### Family context (siblings)

This plugin is one of the **Flux family**: see `FAMILY.md` (identical in every sibling repo) and the sibling table in `CLAUDE.md`. Short version: `flux-reel` (ui), `flux-backgrounds` (visual), `flux-preloader` (visual), `flux-reveal` (visual), `flux-smooth-scroll` (scroll), `flux-scroll-progress` (scroll), `flux-jelly-cursor` (cursor), `super-duper-cursor` (cursor), `super-duper-cursor-pro` (cursor).

- One of four cursor plugins: only ONE may be active. The Reframe theme has the same bubble cursor built in (Cursor Style "Bubble"): use the theme style or this plugin, never both.
- Its plugin lives in the `bubble-cursor/` subfolder (the kit is in `bubble-cursor/includes/flux-family/`); the repo root also holds owner reference files that must not be changed.

If you change anything a sibling also depends on (scrolling, z-index, cursor hiding, admin menus, the shared kit in `includes/flux-family/`), follow "Working across the family" in `CLAUDE.md` and report which siblings you checked.
<!-- family:end -->

### What this is
A custom cursor: a dot and ring follower plus an optional WebGL fluid/smoke trail (`fluid-cursor.js`). Plugin folder is `bubble-cursor/`. The Reframe theme has a built-in bubble cursor; only one should be active.

### Flux Family Kit (since 1.3.0)
Shared kit in `bubble-cursor/includes/flux-family/` (`loader.php`, `core.php`, kit 1.0.0), test `tests/test-family-kit.php` (run by `tests/run.sh`), `FAMILY.md` at the repo root, registered as `flux-bubble-cursor` (group `cursor`), card at the foot of Settings > Bubble Cursor. Admin only. **Do not edit the copied kit, edit it in the hub and sync.** Root `LICENSE` (GPL-2.0) added in 1.3.0.

### Where things are
- `bubble-cursor/bubble-cursor.php`, `assets/js/bubble-cursor.js`, `assets/js/fluid-cursor.js`, `assets/css/bubble-cursor.css`; filter `bubble_cursor_should_load`; constant `BUBBLE_CURSOR_VERSION`.
- **Do not modify** `Exposure.html`, the `demo/` images or the screenshot jpeg (owner reference material).

### Debugging
- Owner's debugging guide (failure modes, console checks, file map, discrepancies): `DEBUGGING.md` at the repo root (not in the plugin zip).

### How to test
- `./tests/run.sh` (php -l, PHP harness, node --check, Chromium suite; 98 PHP + 160 browser checks + the kit test (9 scenarios); the browser check 'hidden before first move' is timing-dependent in headless Chromium (it fires a trusted mousemove at (0,0) after load): it failed in the baseline run and in one 1.3.1 run, passed in the final run). See `tests/README.md`.

### Status
Audit (12 findings) fixed in 1.2.0: visible state no longer lives only in `<html>` classes, settings via `data-bc-settings`, optimizer opt-outs, native cursor hidden only after the follower exists, bfcache restore, builder guards, once-only guard, touch-laptop support (dormant until a real mouse moves), idle smoke loop sleeps, WebGL context recovery, cache purge on save, multisite uninstall. `CROSS-BROWSER-AUDIT.md` has the findings and a 'Resolved in 1.2.0' section.

### 1.3.1 changes
- Hover word: `hover_text` (on/off) + `hover_label` (default "View"); JS keys `hoverText`, `hoverLabel`. Opt-outs: `data-bubble-cursor-no-text`, empty `data-bubble-cursor-text`.
- `ring_blend` option -> `mixBlend`; `textSelector` removed.
- Live reduced motion (`applyMotionPref()` on the media query change; follower and smoke unmounted/remounted; hide-native CSS inside `@media (prefers-reduced-motion: no-preference)`).
- One-cursor stand-down: flags `__fluxJellyActive`, `__superCursorActive`, `__sdcpJellyActive`, `__sdcpDualActive`; we publish `__bubbleCursorBooted`.
- `FAMILY.md` still says this repo has no LICENSE; it has had one since 1.3.0. FAMILY.md is generated from the hub, so fix it there (not edited here).

### Open items and unverified claims
- The WebGL2 half-float linear-filtering change is unverified on Safari/iOS hardware.
- Firefox, Safari, real WordPress, optimizer plugins, real bfcache not run.

### Suggested next steps (owner decides)
1. Review the draft PR diff, then mark it ready and merge to `main` when satisfied (nothing is merged yet).
2. Install on a **staging copy** of the owner's Salient site, one plugin at a time; confirm in real Chrome, **Firefox and Safari incl. the owner's phone** (never run in this environment).
3. Check the open items above, especially anything marked 'from memory' or 'unverified'.
4. Take a backup before updating a live site; clear every cache (plugin, host, CDN) after updating. A stale cache can show the old behaviour.

### Suite roadmap (owner decisions pending)

The owner wants a premium portfolio look in the same vein as the Flux plugins. Proposed, **not started**: a shared **Flux Core** plugin that loads GSAP once and exposes it to the others, then **Flux Split** (SplitText + ScrollTrigger line/char reveals), **Flux Pin** (pinned/horizontal sections), **Flux Morph** (Flip), **Flux Draw** (DrawSVG/MorphSVG), **Flux Magnet** (Inertia/Observer), **Flux Scramble**, **Flux Marquee**. Facts checked: the npm package `gsap` 3.15.1 ships every plugin (SplitText, ScrollSmoother, MorphSVG, DrawSVG, Flip, Inertia, Observer, ScrambleText...) under a 'no charge' licence; read gsap.com/standard-license before bundling in anything sold. Open questions for the owner: one shared core or standalone plugins that each bundle GSAP; the portfolio URL or screenshots to match. Only one thing may own scrolling (Lenis, ScrollSmoother and Salient's own smooth scroll conflict); never overwrite a host `window.gsap`.

### Honest limits
Everything was tested in **Chromium only** with emulation (phones/tablets/touch/reduced motion/RTL) and stubbed WordPress. Firefox, Safari/WebKit, Samsung Internet, real iOS, real Salient, real optimizer plugins and page-builder editors were **not** run unless this file says so for a specific item.
<!-- handoff:end -->

This repo had no earlier HANDOFF.md. For deeper detail read `README.md`, `readme.txt`, `CROSS-BROWSER-AUDIT.md` (findings and a 'Resolved' section) and the source files listed under 'Where things are'.
