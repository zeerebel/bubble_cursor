# Changelog

## v1.3.1 - Hover label, ring blend, live reduced motion, one-cursor stand-down

- **"View" is real.** New `hover_label` option (settings page: "Default hover label", default
  "View", sanitised, max 40 characters, blank = no word) and `hover_text` is now the on/off switch
  ("Hover word"). Links and buttons that match the hover selector show the label when they have no
  own text. Per-element `data-bubble-cursor-text` still wins; `data-bubble-cursor-no-text` (or an
  empty `data-bubble-cursor-text`) opts an element out. JS settings: `hoverText` (bool) and
  `hoverLabel`; an older settings object where `hoverText` is the word itself still works.
  Pre-1.3.1 saved options are migrated on read (the old word becomes the label).
- **Ring blend mode.** New `ring_blend` option (whitelist: normal, screen, lighten, overlay,
  difference, exclusion, color-dodge, hard-light, soft-light) sent as `mixBlend`.
- **Dead code removed.** The unused `textSelector` read is gone.
- **Reduced motion is live.** The `prefers-reduced-motion` change event now removes the dot, ring
  and smoke (stopping their loops) and restores the native cursor, and rebuilds them when it turns
  off. The CSS rule that hides the native cursor now sits inside
  `@media (prefers-reduced-motion: no-preference)`.
- **One cursor only.** At boot (and again when a dormant touch-laptop wakes) the script checks
  `window.__fluxJellyActive`, `__superCursorActive`, `__sdcpJellyActive`, `__sdcpDualActive`; if
  any is truthy it mounts nothing, does not hide the native cursor and logs one `console.warn`.
  `window.__bubbleCursorBooted` is still set when it starts, for siblings to check.
- **Docs.** README install now gives the exact `git archive --prefix=flux-bubble-cursor/` command.
- Tests: PHP harness and Chromium suite extended (see `tests/README.md`). Chromium only; Firefox,
  Safari and Safari WebGL2 not run.

## v1.3.0 - Flux Family Kit

Joins the Flux family. Admin only; the front end is unchanged.

- Ships the shared kit in `bubble-cursor/includes/flux-family/` (`loader.php`, `core.php`, kit 1.0.0)
  and registers as `flux-bubble-cursor` (group `cursor`). The kit adds a top-level
  **Flux** admin menu and a support card (Stripe, Buy Me a Coffee, PayPal; plain
  links, no scripts) at the foot of Settings > Bubble Cursor.
- `FAMILY.md` added at the repo root; `tests/test-family-kit.php` added and run by
  `tests/run.sh`; new checks in `tests/php-harness.php` for registration, the
  settings-page card and a silent front end.
- Added a root `LICENSE` file (GPL-2.0), copied from the sibling Flux repos. The repo
  had none before.
- Verified with PHP stubs and Chromium only; not run on real WordPress.

## v1.2.0

Cross-browser and host-theme audit fixes; see `CROSS-BROWSER-AUDIT.md` ("Resolved in 1.2.0")
and the changelog in `bubble-cursor/readme.txt`.
