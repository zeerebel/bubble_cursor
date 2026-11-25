# Changelog

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
