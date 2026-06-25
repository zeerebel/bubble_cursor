# Bubble Cursor — Project Handoff

> Snapshot for continuing work in a fresh session, and for releasing/selling the plugin.
> **Current version: 1.5.1** · Repo: `zeerebel/bubble_cursor` · Dev branch: `claude/eloquent-dijkstra-hf4e32`

---

## 1. What this is

A self-contained **WordPress plugin** that recreates the cursor on the TreeThemes
"Deep" Elementor demo (`Exposure.html` in this repo is the saved reference page).
It layers:

1. **WebGL "smoke" fluid trail** — a real-time fluid simulation under the pointer.
2. **Dot + ring custom cursor** — a dot that tracks tightly and a ring that eases
   behind it, growing into a **"View" bubble** over links/buttons.
3. **Opt-in extras** — colour modes, auto-contrast, magnetic ring, click burst,
   elastic ring, image preview, adaptive performance, quick presets.

Works on **any theme** (Elementor not required). No external services, no tracking,
no build step — plain PHP + vanilla JS + CSS.

---

## 2. Current state

- **v1.5.1**, all tests green (see §8).
- **Git:** PR #1 (v1.0.0) and PR #2 (v1.1.0) are **merged to `main`**. **PR #3 is open**
  and contains everything from v1.2.0 → v1.5.1. So `main` currently sits at v1.1.0;
  **merge PR #3 to bring `main` to v1.5.1.**
- All development is on branch `claude/eloquent-dijkstra-hf4e32` (per project rules).

---

## 3. Repository layout

```
bubble-cursor/                 ← THE PLUGIN (zip THIS folder to install)
  bubble-cursor.php            ← main file: class, defaults, presets, sanitize, enqueue, settings UI
  uninstall.php                ← deletes the option row on uninstall
  readme.txt                   ← WordPress.org-format readme + changelog
  assets/
    css/bubble-cursor.css      ← all cursor/preview styles (CSS-variable driven)
    js/fluid-cursor.js         ← WebGL fluid engine (the smoke)  — window.BubbleCursorFluid
    js/bubble-cursor.js        ← dot/ring/hover/magnetic/preview/etc. — reads window.BubbleCursorSettings
    js/LICENSE-fluid.txt       ← MIT attribution for the fluid engine
demo/
  index.html                  ← standalone preview (loads the real plugin assets)
  preview-*.png               ← screenshots
tests/                         ← dev tests + harness (NOT shipped in the plugin zip)
README.md                      ← project overview
HANDOFF.md                     ← this file
Exposure.html                  ← original Deep-demo reference page
Screenshot_*.jpeg              ← original reference screenshot
```

The installable zip must contain the `bubble-cursor/` folder at its root:
```bash
cd <repo root> && zip -r bubble-cursor.zip bubble-cursor -x '*.DS_Store' '*/.*'
```

---

## 4. Architecture & data flow

```
defaults()  ──┐
              ├─ get_options()  (saved values merged over defaults; never undefined)
saved option ─┘        │
                       ▼
           (on save)  sanitize()  ── clamps/validates EVERY field; applies a chosen
                       │            preset ONCE; never stores apply_preset
                       ▼
              build_js_settings()  ── emits a typed PHP array
                       │
                       ▼
   wp_add_inline_script('…','window.BubbleCursorSettings = ' . wp_json_encode($s), 'before')
                       │     (printed BEFORE the engine scripts)
                       ▼
   fluid-cursor.js  +  bubble-cursor.js  read window.BubbleCursorSettings
```

Key conventions:
- **Smoke params** go under `settings.fluid.*` with `UPPER_CASE` keys that match the
  engine's config object (e.g. `SPLAT_FORCE`, `COLOR_MODE`, `BLOOM`, `ADAPTIVE`).
- **Cursor params** are top-level `camelCase` (e.g. `ringSize`, `autoContrast`,
  `imagePreview`, `previewSelector`).
- Assets are enqueued **front-end only**, gated by `should_load()` (the `enable`
  master switch + `scope` = all/front-page).
- Types are preserved because we use **`wp_add_inline_script` + `wp_json_encode`**,
  NOT `wp_localize_script` (which stringifies booleans/numbers).

The canonical list of every option + default is the **`defaults()`** method in
`bubble-cursor.php` — treat that as the source of truth.

---

## 5. Feature → settings map

| Feature | Option key(s) | Default |
|---|---|---|
| Master on/off, where to load | `enable`, `scope` (`all`/`front`) | on, all |
| Layers | `enable_fluid`, `enable_ring`, `enable_dot`, `hide_native`, `hide_on_touch` | smoke+ring+dot on, native shown, hidden on touch |
| Cursor colours | `dot_color`, `ring_color` | white |
| Hover word | `hover_text`, `hover_effect` (grow on/off), `hover_selector`, `hover_text_selector` | "View", on |
| Shape & transparency | `dot_size`, `ring_size`, `ring_speed`, `ring_border`, `cursor_opacity`, `smoke_opacity`, `smoke_blend` | 8, 40, 0.2, 1.5, 1, 1, "" |
| Adapt to background | `auto_contrast` | off |
| Extra effects | `magnetic`, `click_burst`, `elastic`, `image_preview`, `preview_selector`, `preview_size`, `adaptive` | all off, 180px |
| Smoke colours | `color_mode` (rainbow/palette/single), `single_color`, `pal_color_1..5`, `pal_on_1..5` | rainbow |
| Smoke tuning | `colorful`, `bloom`, `bloom_intensity`, `intensity`, `curl`, `quality` (low/med/high), `splat_force`, `splat_radius`, `density_dissipation`, `velocity_dissipation` | demo values |
| Quick presets | `apply_preset` (transient; neon/mono/minimal/smoke) — applied once, never stored | — |

Per-element overrides (no settings needed): `data-bubble-cursor-text="Open"`,
`data-bubble-cursor-hover`, `data-bubble-cursor-image="URL"`.

---

## 6. The fluid engine — `assets/js/fluid-cursor.js`

- Trimmed, dependency-free port of **Pavel Dobryakov's WebGL-Fluid-Simulation (MIT)**.
- Public API: `window.BubbleCursorFluid.start(canvas, config)`, `.stop()`, `.pause()`,
  `.resume()`, `.burst(clientX, clientY)`, `.isRunning()`.
- The `DEFAULTS` object at the top lists every tunable.
- Robustness (important — these prevent the kinds of failures that crash tabs):
  - Frees framebuffers before re-allocating on resize (**no GPU memory leak**).
  - **Debounced** resize re-init; handles `webglcontextlost`.
  - Render loop wrapped in `try/catch` (stops cleanly on a runtime error).
  - Full GPU teardown (`_freeGL` + lose-context) on destroy/unload.
  - **Adaptive** mode (`ADAPTIVE`): measures real frame time and steps quality down
    (dye res → bloom → lower dye) on sustained low FPS; monotonic, ignores stalls.
  - `PRESERVE_DRAWING_BUFFER` flag (off by default; used by tests to sample pixels).
  - Returns `unsupported` and no-ops if WebGL is unavailable.

---

## 7. The cursor — `assets/js/bubble-cursor.js`

- Reads `window.BubbleCursorSettings`; whole init wrapped in `try/catch` (a cursor
  error can never take the page down).
- **Bails entirely on touch devices and for `prefers-reduced-motion`.**
- One `document.elementFromPoint()` hit-test **per frame, only when the pointer moved**,
  feeds hover state + magnet target + image preview (this replaced `mouseover`/`mouseout`
  which thrashed and made the ring throb/ripple — see changelog 1.2.1).
- **Frame-rate-independent ring easing** (consistent at 30/60/144 fps — changelog 1.2.2).
- `safeClosest()` wraps every `el.closest()` so a bad selector typed in settings can
  never throw.
- Magnetic morph: ring resizes to hug the hovered element's bounds/corner-radius.
- Image preview: a `.bc-preview` div eases to the cursor; only `opacity` is CSS-transitioned
  (transform is written per-frame, so transitioning it would rubber-band).
- Pauses the smoke on `visibilitychange` (background tabs).

---

## 8. Testing (see `tests/README.md` for commands)

No build step. Two layers:

- **PHP (no browser):**
  - `php tests/php/bridge-test.php` — sanitization, clamping, and the typed-JSON bridge.
  - `php tests/php/render-test.php` — renders the *entire* settings page under stubbed
    WP functions with `error_reporting(E_ALL)`; **fails on any PHP notice/warning**
    (catches a missing default, broken tag, etc.).
- **Front-end (headless Chromium via Playwright):** start `php -S 127.0.0.1:8765` from
  the repo root, then `node tests/playwright/run.mjs`. Covers smoke render (no shader
  errors), the "View" bubble, ring convergence, palette/single colours (pixel-sampled),
  auto-contrast blend, image preview, magnetic morph, and hover stability.

Every change in this project was verified with these before shipping. Keep that bar.

---

## 9. Cutting a release

1. Bump the version in **three** places: the `Version:` header and the
   `BUBBLE_CURSOR_VERSION` constant in `bubble-cursor.php`, and `Stable tag:` in
   `readme.txt`. (The version also cache-busts the front-end assets.)
2. Add a `readme.txt` changelog entry.
3. Run the full test battery (§8).
4. Commit, push the dev branch, update/merge the PR.
5. Build the zip (§3) for manual distribution.

---

## 10. Distribution checklist

### A. Share directly / GitHub
- Ship the zip from §3. Recipients install via **Plugins → Add New → Upload Plugin**.
- Already met: GPL-2.0+ license, MIT attribution for the engine, no external calls.

### B. WordPress.org plugin directory (free hosting)
Already satisfied: `readme.txt` format, full input sanitization + output escaping,
text domain `bubble-cursor` on strings, unique prefixes (`bubble_cursor_` /
`BUBBLE_CURSOR_` / `.bc-` / `window.BubbleCursor*`), unminified JS source bundled,
no tracking/remote calls, `uninstall.php` cleanup.
**Still needed before submission:**
- Pick a **unique slug** and confirm it's free on WordPress.org.
- Confirm **"Tested up to"** matches the current WP version.
- Provide directory assets: `icon-128x128.png`, `banner-772x250.png`, and
  `screenshot-1.png`, `screenshot-2.png`… referenced in `readme.txt`.
- (Optional but recommended) add a `languages/bubble-cursor.pot` for i18n.
- Submit for review, then push to the assigned SVN repo (`trunk` + a `tags/1.5.1`).

### C. Selling / donation
- It's GPL, so code can be redistributed by buyers — the standard WP model is to sell
  **support, updates, and convenience** (not to restrict the code).
- A paid/"Pro" path would need a licensing+update layer (e.g. EDD Software Licensing or
  Freemius) — **not built yet**; that's future work.
- Keep the MIT attribution (`assets/js/LICENSE-fluid.txt`) intact in any distribution.

---

## 11. Known limits & future ideas

- The smoke is GPU-heavy on low-end devices — **Quality: Low** + **Adaptive performance**
  mitigate it; it's also off on touch and reduced-motion.
- Image preview takes the first `<img>`/background of the matched element; unusual markup
  may need the `data-bubble-cursor-image` attribute.
- Not yet: i18n `.pot`, admin live-preview, settings import/export, a Pro/licensing layer.
- Effect ideas floated but not built: trailing-dots tail, I-beam over text inputs,
  spotlight/mask, per-section `data-bubble-cursor-*` overrides.

---

## 12. Gotchas for the next session

- **Don't use `wp_localize_script`** for settings — it stringifies; keep
  `wp_add_inline_script` + `wp_json_encode`.
- Every new option must be added to **`defaults()`** (so `get_options()` never returns
  undefined) **and** `sanitize()` (clamp/validate) **and** `build_js_settings()` (to reach
  JS) **and** the settings UI. The render test will fail loudly if you forget a default.
- The preset dropdown is a **one-shot action** that's never stored; it resets on load
  (id `bc-apply-preset` + `autocomplete=off` + a reset script) so it can't silently
  re-apply over saved tweaks (changelog 1.5.1).
- Per project rules, develop on `claude/eloquent-dijkstra-hf4e32` and open/keep the PR.
