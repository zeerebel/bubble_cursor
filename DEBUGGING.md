# Bubble Cursor: debugging guide

For the owner, to debug alone, including from a phone. Plain language. This file sits at the repo root, so it is **not** in the plugin zip (see section 3). Keep it in step with the code. Anything marked "by code reading" or "unverified" was not run.

## 1. What was built and why

Bubble Cursor recreates the cursor of the TreeThemes "Deep" demo (`Exposure.html` is the saved reference page) so it runs on any WordPress theme. It is two layers:

1. **Smoke trail:** a WebGL fluid simulation on a full-screen canvas that paints colourful smoke under the mouse.
2. **Dot and ring:** a small dot that tracks the pointer, and an outline ring that eases behind it. The ring grows over links and buttons, and fills with a word (for example "View") over elements that carry a hover label.

The rules that shaped it (from the Nov 2026 audit, `CROSS-BROWSER-AUDIT.md`):
- **Never hide the native cursor until the replacement is drawn.** `hide_native` is off by default and only takes effect while the dot or ring is visible.
- **Fail silently.** Any error in init or in the render loop is caught; the page must never break.
- **State lives on the cursor's own elements**, not only on `<html>` classes, because theme scripts sometimes rewrite `<html class>`.
- **Settings travel two ways** (inline script and a `data-bc-settings` attribute) so an optimizer that strips one does not reset your settings.

The Reframe theme has the same bubble cursor built in (Cursor Style "Bubble"). Use the theme's or this plugin, never both.

## 2. Tech stack

- PHP 7.2+ and WordPress 5.6+ (plugin header). One class `Bubble_Cursor`, no Composer, no build step. The "Tested up to 6.8" line in `readme.txt` is unverified (never run on real WordPress).
- JavaScript: plain ES5 style, no jQuery, no libraries, two files. IIFEs, no modules.
- CSS: one small file, prefixed `bc-` classes.
- **Bundled library:** the WebGL fluid engine in `fluid-cursor.js` is a trimmed, dependency-free port of **Pavel Dobryakov's WebGL-Fluid-Simulation**, **MIT licence** (Copyright 2017 Pavel Dobryakov), https://github.com/PavelDoGreat/WebGL-Fluid-Simulation. The licence text is in `bubble-cursor/assets/js/LICENSE-fluid.txt` and is shipped in the zip. The plugin code itself is GPL-2.0-or-later (root `LICENSE`).
- Browser APIs used: WebGL2 (falls back to WebGL1), half-float textures, `requestAnimationFrame`, `matchMedia` (`any-hover`, `any-pointer`, `prefers-reduced-motion`), Pointer Events (only for waking on touch-only devices), `MutationObserver` (re-asserts the `<html>` marker classes), `pagehide`/`pageshow` (bfcache), `webglcontextlost`/`webglcontextrestored`, `Element.closest`.
- Test tooling: PHP CLI (stub harness, no real WordPress), Node (v22 here) with Playwright driving **Chromium only** (`require('playwright')`; never run `playwright install`), a tiny Node static server inside `tests/run.js`, `bash`.

## 3. File map

Plugin folder `bubble-cursor/` (this is all that ships):
- `bubble-cursor.php`: bootstrap, constants, options and defaults, `should_load()`, enqueue, optimizer opt-outs, cache purge, settings page, sanitising, kit wiring.
- `assets/js/bubble-cursor.js`: the front-end brain: reads settings, decides if a mouse exists, builds dot/ring/canvas, follow loop, hover states, `<html>` markers, bfcache.
- `assets/js/fluid-cursor.js`: the WebGL smoke engine. Exposes `window.BubbleCursorFluid`.
- `assets/js/LICENSE-fluid.txt`: MIT licence for the fluid engine.
- `assets/css/bubble-cursor.css`: canvas, dot, ring, label, hover and hide-native rules.
- `uninstall.php`: deletes the option on plugin delete (loops every site on multisite, plus the network option).
- `includes/flux-family/loader.php`, `core.php`: shared Flux Family Kit (admin only: Flux menu, one-cursor warning, support card). **Do not edit here**; change it in the family hub and sync.
- `readme.txt`: WordPress-style readme and changelog.

Repo root (not in the zip):
- `DEBUGGING.md`: this file. `README.md`, `CHANGELOG.md`, `HANDOFF.md`, `CLAUDE.md`, `FAMILY.md`, `CROSS-BROWSER-AUDIT.md`, `LICENSE`.
- `Exposure.html`, `demo/` (`index.html`, `preview-smoke.png`, `preview-hover.png`), the `Screenshot_...treethemes.com.jpeg`: **owner reference material. Do not modify.** `demo/index.html` loads the plugin's real CSS/JS without WordPress (`php -S 127.0.0.1:8765` from the repo root, then `/demo/index.html`).
- `tests/run.sh`, `tests/run.js` (browser), `tests/php-harness.php`, `tests/test-family-kit.php`, `tests/README.md`.

**How the zip is built:** there is no `build.sh` and no `.gitattributes` in this repo. The zip is made from only the plugin subfolder, so everything at the repo root is automatically outside it:

```bash
git archive --format=zip --prefix=bubble-cursor/ -o bubble-cursor.zip HEAD:bubble-cursor
```

Checked: that command lists exactly nine files, all under `bubble-cursor/` (php x2, js x3 incl. the licence, css, readme, kit x2). `DEBUGGING.md`, `tests/` and the reference files are not in it. Without `--prefix` the zip has no top folder and WordPress installs it under a wrong name.

## 4. Data flow

1. You save **Settings > Bubble Cursor**. `sanitize()` clamps and cleans every value into the option `bubble_cursor_options`. Saving also purges page caches (section 5).
2. On each front-end request `enqueue_assets()` loads the option merged over defaults. `should_load()` says no for: option `enable` off, `is_admin()`, builder/preview requests, scope "front page only" off the front page, or the `bubble_cursor_should_load` filter returning false. Then nothing is printed.
3. If loading: it enqueues the CSS and two footer scripts (`bubble-cursor-fluid`, then `bubble-cursor` which depends on it). `build_js_settings()` turns the option into typed JSON (filter `bubble_cursor_js_settings`). That JSON is printed **twice**: as an inline `window.BubbleCursorSettings = {...};` before the fluid script, and as a `data-bc-settings="..."` attribute on the `<script id="bubble-cursor-js">` tag (added by `filter_script_tag()`). Both scripts also get optimizer opt-out attributes.
4. `bubble-cursor.js` waits for DOM ready, then `init()`. It reads settings in this order: `window.BubbleCursorSettings` (wins if it is an object), then the `data-bc-settings` attribute, then built-in defaults (with one `console.warn`).
5. `init()` stops quietly if: `body` has `elementor-editor-active`, the URL has a builder query arg, the cursor already exists (`window.__bubbleCursorBooted` or an existing `.bc-dot/.bc-ring/.bc-fluid-canvas`), or another cursor plugin's flag is truthy (one `console.warn` naming it; nothing is mounted and the native cursor is left alone; the flag is checked again when a dormant touch-laptop wakes). It validates the hover selector (bad one falls back to the default, with a warning).
6. **Mouse check.** If `hideOnTouch` is on and the device has no hover-capable fine pointer (`(any-hover: hover) and (any-pointer: fine)` false), it stays **dormant** and only starts when a real mouse (`pointerType` "mouse") moves at least 4 px or clicks. Without Pointer Events it gives up.
7. `run()` builds the layers: canvas (if smoke on, reduced motion off, `BubbleCursorFluid` present) and dot/ring (if enabled and reduced motion off). Since 1.3.1 reduced motion is live: the `change` event on the media query removes the dot, ring, canvas and loops (native cursor back) or rebuilds them. `BubbleCursorFluid.start(canvas, settings.fluid)` returns null with no WebGL, and the canvas is removed.
8. On the first `mousemove` the dot and ring get `data-bc-visible`, so CSS fades them in. The dot is moved directly; the ring eases toward the pointer (18 % per frame) in a loop that parks itself when it is within 0.05 px.
9. Hovering: `mouseover` checks the hover selector and the label sources. Ring gets `data-bc-state="hover"` or `"text"`; the dot gets `data-bc-hover` (hidden). The word comes from, in order: `data-bubble-cursor-no-text` (none), the element's own `data-bubble-cursor-text` (empty = none), an Elementor `data-settings` JSON key `wcf_enable_cursor_hover_effect_text`, then the **Default hover label** for elements matching the hover selector. With the "Hover word" switch off there is never a word.
10. `<html>` markers (`bc-active`, `bc-visible`, `bc-hover`, `bc-hover-text`, `bc-down`, `bc-hide-native`) are kept from JS state and re-applied by a MutationObserver if a script wipes them. CSS only depends on one of them: `bc-hide-native`.
11. Smoke loop: runs while the mouse moves, parks itself after the pointer is idle long enough for the smoke to fade (at least 3 s, at most 12 s), clears the canvas, and wakes on the next move.
12. `pagehide` stops everything. A **persisted** `pageshow` (back/forward cache) rebuilds the smoke on a fresh canvas and restarts the ring.

## 5. Everything you can set or watch

**Settings page options** (option `bubble_cursor_options`; defaults and clamps from `sanitize()`):
- General: `enable` (1), `scope` all|front (all), `hide_on_touch` (1).
- Layers: `enable_fluid` (1), `enable_ring` (1), `enable_dot` (1), `hide_native` (0; forced to 0 on save if both dot and ring are off).
- Colours and hover: `dot_color` (#ffffff), `ring_color` (#ffffff) (hex only, else default), `hover_text` (1; on/off switch for any word in the ring), `hover_label` ("View"; the default word, max 40 characters, blank = no default word), `ring_blend` (blank, screen, lighten, overlay, difference, exclusion, color-dodge, hard-light, soft-light), `hover_selector` (default `a[href], button:not(:disabled), input[type="submit"], input[type="button"], .elementor-button, [data-bubble-cursor-hover]`; blank resets to default).
- Shape: `dot_size` 2 to 40 px (8), `ring_size` 10 to 120 px (40), `ring_border` 0 to 8 px (1.5), `cursor_opacity` 0.1 to 1 (1), `smoke_opacity` 0.1 to 1 (1).
- Smoke: `colorful` (1), `bloom` (1), `bloom_intensity` 0 to 2 (0.8), `intensity` 0.2 to 3 (1), `curl` 0 to 50 (30), `quality` low|medium|high (medium = dye resolution 512 / 1024 / 1440), `smoke_blend` (blank, screen, lighten, overlay, difference, color-dodge, hard-light, soft-light), `splat_force` 100 to 20000 (6000), `splat_radius` 0.01 to 1 (0.25), `density_dissipation` 0.5 to 4 (0.98), `velocity_dissipation` 0.5 to 4 (0.98).

**JS settings keys** (the JSON; PHP sends all of them): `enableFluid`, `enableRing`, `enableDot`, `hideNativeCursor`, `hideOnTouch`, `dotColor`, `ringColor`, `hoverText` (bool, word on/off), `hoverLabel` (string, default word), `mixBlend` (ring blend mode, '' = normal), `hoverSelector`, `dotSize`, `ringSize`, `ringBorder`, `cursorOpacity`, `smokeOpacity`, `smokeBlend`, `fluid` {`SPLAT_FORCE`, `SPLAT_RADIUS`, `DENSITY_DISSIPATION`, `VELOCITY_DISSIPATION`, `CURL`, `INTENSITY`, `BLOOM_INTENSITY`, `DYE_RESOLUTION`, `COLORFUL`, `BLOOM`}. An older settings object where `hoverText` is the word itself (or false) still works. `textSelector` was removed in 1.3.1. Other engine keys such as `SIM_RESOLUTION`, `MAX_DPR` (2), `IDLE_MS` (3000) can also be passed inside `fluid` via the filter (by code reading).

**Data attributes you put on page elements:** `data-bubble-cursor-text="Open"` (ring shows that word; empty = no word), `data-bubble-cursor-no-text` (no word on this element or inside it), `data-bubble-cursor-hover` (triggers the enlarged ring).

**Attributes and classes the plugin adds:** on `.bc-dot` / `.bc-ring`: `data-bc-visible`; on the ring: `data-bc-state` (`hover` or `text`); on the dot: `data-bc-hover`. Elements: `.bc-fluid-canvas` (z-index 2147483640, `aria-hidden`), `.bc-dot`, `.bc-ring` (z-index 2147483646), `.bc-ring__label`; all `pointer-events: none`. CSS variables (set inline): `--bc-dot-size`, `--bc-dot-color`, `--bc-ring-size`, `--bc-ring-color`, `--bc-ring-border`, `--bc-opacity`, `--bc-hover-fill`, `--bc-text-fill`, `--bc-label-color`. On `<html>`: the six `bc-*` marker classes above. On the script tag: `data-bc-settings` plus the opt-outs `data-no-optimize`, `data-no-defer`, `data-no-minify`, `data-no-delay`, `data-cfasync="false"`, `data-pagespeed-no-defer`, `data-wpfc-render="false"`, `nowprocket`.

**PHP filters:** `bubble_cursor_should_load( bool $load, array $options )` (last word on loading), `bubble_cursor_js_settings( array $settings, array $options )`. Also the kit's `flux_family_exclusive_groups`.
**PHP constants:** `BUBBLE_CURSOR_VERSION` (1.3.1), `BUBBLE_CURSOR_FILE`, `BUBBLE_CURSOR_URL`, `BUBBLE_CURSOR_PATH`, `BUBBLE_CURSOR_OPTION` (`bubble_cursor_options`). Class `Bubble_Cursor` (singleton), function `bubble_cursor_activate`. Script handles `bubble-cursor-fluid`, `bubble-cursor` (tag id `bubble-cursor-js`); style handle `bubble-cursor`. Asset version is `1.3.1-<file modified time>`.
**WordPress hooks it uses:** `wp_enqueue_scripts`, `admin_menu`, `admin_init`, `plugin_action_links_...`, `script_loader_tag`, `update_option_bubble_cursor_options`, `add_option_bubble_cursor_options` (both call the cache purge), activation hook. The purge calls, each only if present: WP Rocket `rocket_clean_domain`, LiteSpeed `litespeed_purge_all`, W3 Total Cache `w3tc_flush_all`, Autoptimize `autoptimizeCache::clearall`, SG Optimizer `sg_cachepress_purge_cache`.
**JS globals:** `window.BubbleCursorSettings`, `window.__bubbleCursorBooted` (true once started; siblings can check it), flags it **reads** to stand down (any truthy one means another cursor plugin is active): `window.__fluxJellyActive`, `window.__superCursorActive`, `window.__sdcpJellyActive`, `window.__sdcpDualActive`; `window.BubbleCursorFluid` with `start(canvas, config)`, `stop()`, `isRunning()`, `isSleeping()`, `info()` (returns `{webgl2, linear, dyeResolution, bloom}` or null).
**Events:** the plugin fires no custom events. It listens to the `change` event of `(prefers-reduced-motion: reduce)` (live reduced motion), `mousemove`, `mouseover`, `mouseout`, `mousedown`, `mouseup`, `touchmove` (smoke only), `resize` (200 ms debounce), `pagehide`, `pageshow`, `webglcontextlost`, `webglcontextrestored`, and `pointermove`/`pointerdown` while dormant.

## 6. Turning on debug logging

**There is no debug switch** (no `debug` key exists in the code). What you have:

- The plugin logs only problems, prefixed `[BubbleCursor]`: `settings not found ... using built-in defaults`, `invalid hover selector ...`, `init skipped: ...`, `shader compile error` / `program link error`, `fluid stopped after a runtime error`. Open the browser console and look for that prefix.
- Paste these in the console (desktop; on a phone use a remote inspector or the demo page on a laptop):
  - `BubbleCursorFluid.info()` shows which GL path is live. `null` means the smoke is not running.
  - `BubbleCursorFluid.isRunning()` and `BubbleCursorFluid.isSleeping()` (sleeping with a still mouse is normal).
  - `window.__bubbleCursorBooted` is `true` once the cursor started.
  - `document.querySelectorAll('.bc-dot,.bc-ring,.bc-fluid-canvas').length` is 3 with defaults. 0 means it never started. More than 3 means two cursors.
  - `window.BubbleCursorSettings` (the settings the page actually got) and `JSON.parse(document.getElementById('bubble-cursor-js').getAttribute('data-bc-settings'))` (the attribute copy).
  - `document.documentElement.className` (look for `bc-active`) and `getComputedStyle(document.body).cursor` (`none` only when hide-native is on and the cursor is drawn).
  - `matchMedia('(any-hover: hover) and (any-pointer: fine)').matches` must be `true` for the cursor to start on its own. `matchMedia('(prefers-reduced-motion: reduce)').matches` must be `false` for anything to draw.
- In the Elements inspector select `.bc-dot`: it should have `data-bc-visible="1"` after you move the mouse.
- Temporarily set values with no PHP: add `<script>window.BubbleCursorSettings = {enableFluid:false};</script>` before the plugin script. The global wins over the attribute. Remove it afterwards.

## 7. Debugging checklist

Always first: clear every cache (plugin, host, CDN, browser), because settings are baked into the page HTML. Then reload with the console open.

**No cursor at all**
1. Page source: search for `bubble-cursor.js`. Not there? The plugin did not load: Enable cursor off, Scope "front page only" on an inner page, you are logged in inside a builder/Customizer, or the URL has `vc_editable`, `elementor-preview`, `et_fb`, `fl_builder`, `ct_builder`, `brizy-edit`. Those skips are on purpose. A theme or other plugin using the `bubble_cursor_should_load` filter can also say no.
2. Script present but `window.__bubbleCursorBooted` is undefined: check the console for `init skipped`. Check the next three items.
3. **Is it a phone or tablet, or a device reporting touch only?** With "Hide on touch" on, the cursor stays dormant until a real mouse moves 4 px or clicks. Test on a laptop with a mouse. Taps never wake it.
4. **Reduced motion on** (OS accessibility setting)? Then nothing is drawn, by design. This is read once at page load, so reload after changing the OS setting (by code reading).
5. Dot and ring both off and smoke off in settings? Then there is nothing to show.
6. Elements exist (`.bc-dot` present) but invisible: inspect `.bc-dot`. No `data-bc-visible` means no `mousemove` reached the window (an overlay iframe, or a script stopping events). Has it, but `opacity: 0`: Cursor opacity setting, or a host rule overriding it.
7. After the mouse leaves the window the cursor hides on purpose and returns on the next move.

**Double cursor**
1. Count: the query in section 6 should give 3 (dot, ring, canvas). Two dots/rings means two copies of this cursor code ran. The once-only guard stops a second copy of this plugin, and a second set of elements with the same class names.
2. Most likely **a different cursor is also active**: the Reframe theme's Cursor Style "Bubble" or another cursor plugin (Flux Jelly Cursor, Super Duper Cursor, Super Duper Cursor Pro, TM-Pointer, a Salient cursor option). The guard only recognises this plugin's own class names, so a different cursor will still draw next to it. Turn all but one off. See the Reframe and one-cursor items below.
3. Native arrow plus the dot: not a bug. "Hide native cursor" is off by default (the Deep demo keeps the arrow). Turn it on in Layers.

**Native cursor hidden but no follower**
This should never last; treat it as a bug. The code only adds `bc-hide-native` while a follower exists, motion is allowed and the cursor is visible, and removes it on `pagehide`.
1. `document.documentElement.className`: does it contain `bc-hide-native`? Is `.bc-dot` / `.bc-ring` in the page and visible?
2. A host script that deletes the dot or ring from the DOM, or a CSS rule hiding them (`display:none`, `visibility`, `opacity !important`), would leave the arrow hidden (by code reading; no test removes the elements).
3. Rescue now: untick "Hide native cursor", save, clear caches.
4. Over an `<iframe>` (video embed, map) the follower cannot track the mouse while the arrow is still hidden (by code reading, not run). Avoid hide-native on pages with large iframes.

**Smoke trail missing**
1. `BubbleCursorFluid.info()`: `null` plus no `.bc-fluid-canvas` means no WebGL, reduced motion, "Smoke trail" off, or `fluid-cursor.js` did not load (Network tab; an optimizer delaying it). No WebGL is silent by design; dot and ring still work.
2. Canvas exists but nothing shows: Smoke opacity low? Blend mode set (a "screen" blend on a white page is invisible)? Move the mouse quickly; slow moves make faint puffs. Smoke fades after about 3 to 12 s of stillness and the loop sleeps (`isSleeping()`).
3. Smoke is behind content you expected it above? It is not: the canvas is z-index 2147483640 with `pointer-events: none`. If it is hidden, a rule such as `canvas { display:none }` is in the theme.
4. Browser blocks hardware acceleration or WebGL (battery saver, remote desktop): smoke off, no error.

**Smoke slow or heavy**
1. Settings > Smoke tuning > Quality: choose Low (dye 512).
2. Turn off Bloom glow; lower Swirl.
3. The simulation caps the pixel ratio at 2 and sleeps when idle. `BubbleCursorFluid.isSleeping()` should become `true` a few seconds after you stop moving; if the page is still busy at idle, something else is drawing.
4. If WebGL1 is used and `info().linear` is `false`, the engine automatically drops to 512 with no bloom (that is the fallback working).
5. Software rendering (no GPU, VM, some headless browsers) is slow by nature.

**WebGL context lost**
Happens after a GPU reset, GPU switch (laptop) or too many WebGL canvases on the page. The code stops the loop on `webglcontextlost` and rebuilds everything on `webglcontextrestored` (tested with the `WEBGL_lose_context` extension in Chromium). If the smoke stays dead: reload; check `BubbleCursorFluid.info()`; reduce other WebGL on the page (for example Flux Backgrounds). On back/forward navigation the smoke is rebuilt by `pageshow` (tested only with synthetic events; real bfcache not run).

**Touch laptops, tablets and phones**
- Phones and tablets: no cursor, on purpose.
- Touch-screen laptop with mouse or trackpad: should work, because the test is "hover-capable fine pointer exists", not "has touch". If the browser reports touch only, move the mouse about 4 px or click once to wake it.
- Want it on touch anyway: untick "Hide on touch devices". Then it runs on tapped devices too (it still follows only mouse events, so on a pure phone the dot and ring may sit idle; smoke follows touch moves).
- Chromium emulation can fake one mouse move on load; that is why a single move does not wake it.

**Reframe theme's built-in bubble cursor**
1. The theme's Cursor Style = "Bubble" runs the identical code. Using both: the once-only guard looks for existing `.bc-dot/.bc-ring/.bc-fluid-canvas` and for `window.__bubbleCursorBooted`, so whichever starts first wins and the second does nothing. That relies on the theme using the same class names and global; **this was never tested with the real Reframe theme** (unverified). Whoever loses may still change settings you did not expect (the theme's settings vs this plugin's).
2. Fix: set the theme's Cursor Style to Off/None, or deactivate this plugin.

**Another cursor plugin active (one-cursor rule)**
1. In wp-admin open the **Flux** menu. The shared kit warns when two plugins of group `cursor` are active (Flux Jelly Cursor, Flux Bubble Cursor, Super Duper Cursor, Super Duper Cursor Pro). It only knows Flux-family plugins that carry the kit (Super Duper Cursor does not carry it, by its docs), so also check Plugins by hand.
2. Keep one. The front end does not detect other cursors for you.
3. Never run `super-duper-cursor-v2.0.4a` next to `super-duper-cursor` (same function names; PHP fatal). Not related to this plugin but easy to hit.

**Settings do not apply / old look**
1. Cached page: clear plugin, host and CDN caches. Saving settings purges WP Rocket, LiteSpeed, W3TC, Autoptimize and SG Optimizer (each only if installed); other caches (Cloudflare, host-level, Kinsta, WP Engine and similar) are not purged by the plugin.
2. Compare `window.BubbleCursorSettings` with what you saved. If it is `undefined` and the console says "settings not found", both channels were stripped and defaults are in use (see optimizers).
3. A bad hover selector falls back to the default with a warning.

**"View" never appears** (fixed in 1.3.1, section 10): links and buttons show the "Default hover label" unless: "Hover word" is unchecked, the label is blank, the element has `data-bubble-cursor-no-text` or an empty `data-bubble-cursor-text`, or the hover selector does not match it. Clear caches: settings are baked into the page HTML.

**Console errors**
Look for the `[BubbleCursor]` prefix first. Errors from other files are not ours (check the file name in the stack). A "GPU stall due to ReadPixels" message is a browser performance note from software GL, not an error.

**Conflicts**
- **Salient / WPBakery:** Salient styles `body[data-form-style] button/input`, global `img`/`a` and has `#header-outer` at z-index 9998. The cursor sits far above that and ignores clicks. Hostile Salient-like CSS (`button{all:unset}`, `img,canvas{max-width:100%}`, `body div{position:relative}`, `*{box-sizing:border-box}`) was emulated and did not break it. **Real Salient not run.** Salient's own smooth scrolling or custom cursor options are not known to this plugin; if Salient has a cursor option on, turn it off (unverified that Salient offers one). The editor (`vc_editable`) is skipped on purpose.
- **Optimizers (WP Rocket, LiteSpeed, Cloudflare Rocket Loader, Autoptimize):** both script tags carry the opt-out attributes listed in section 5, and settings are mirrored in `data-bc-settings`. **Not tested against the real plugins.** If it still fails: exclude `bubble-cursor.js` and `fluid-cursor.js` from delay, defer, combine and minify; in WP Rocket also exclude `BubbleCursorSettings` from "delay JS". If a combine feature merges the files, the `id="bubble-cursor-js"` tag disappears and only the global channel remains. The fluid script must run before the main script (the main one depends on it).
- **Flux Reel:** its menu overlay and footer sit around z-index 99000, below the cursor (2147483640 and up), so the cursor draws above them (by code reading, not tested together).
- **Flux Backgrounds:** it draws a fixed canvas behind the page; the smoke canvas is above everything. Two WebGL contexts on one page raise the chance of a context loss on weak GPUs (unverified).
- **Flux Preloader:** covers the page while loading; the cursor script waits for DOM ready and may start drawing over or under it depending on its z-index (not checked).
- **Flux Smooth Scroll, Flux Scroll Progress, Flux Reveal:** the cursor reads only mouse position, not scroll. No known clash. Not tested together.
- **Flux Jelly Cursor, Super Duper Cursor (+ Pro):** these are cursors: one-cursor rule above.
- **Two copies of this plugin:** the second returns quietly (constant check) instead of a fatal.

**Admin or kit problems**
The Flux menu and support card come from `includes/flux-family/` and load only in wp-admin. They cannot affect visitors. Do not patch the files; report kit bugs to the hub.

## 8. Tests: how to run and what each covers

Run everything: `./tests/run.sh` (needs PHP, Node and a global Playwright that already has Chromium). It exits non-zero on any failure and ends with `ALL GREEN` or `FAILURES`. Chromium only. No real WordPress, no Firefox/Safari.

In order:
1. `php -l` on every PHP file (plugin and tests).
2. `php tests/php-harness.php`: loads `bubble-cursor.php` twice with fake WordPress functions. Sections: [A] double include does not fatal, [B] `should_load()` (admin, Customizer, `vc_editable`, other builder args, WPBakery, Elementor, scope), [C] enqueue and the script-tag filter (opt-out attributes, idempotent, `data-bc-settings` JSON and escaping), [D] `sanitize()` and the JS settings, [E] cache purge on save, [F] `uninstall.php` single site and multisite.
3. Flux Family Kit test (9 scenarios): an unmodified copy of `tests/test-family-kit.php` is run from a scratch directory that links to `bubble-cursor/includes`. Do not edit the kit test.
4. `node --check` on both plugin JS files and `tests/run.js`.
5. `node tests/run.js` (port 9190, env `BC_TEST_PORT`): serves the real plugin CSS/JS inside a fixture page with hostile Salient-like CSS. Sections: [1] baseline, visibility, hover and text states, [2] `<html class>` rewrites, [3] settings channels incl. stripped inline script, [4] hide-native with reduced motion and with no followers, [5] bfcache `pagehide`/`pageshow` (synthetic), [6] once-only guard, [7] touch phones/tablets vs touch-screen laptop (iPhone 13, Pixel 7, iPad gen 7, iPad Pro 11 emulation), [8] idle loop (draw calls and style writes), [9] invalid hover selector, [10] CSS, [11] WebGL context restore and filtering path, [12] builder editors (JS side), [13] RTL, scroll, resize, [14] default hover label and opt-outs, [15] ring blend mode, [16] live reduced motion (`emulateMedia` toggled mid-visit, stale-marker CSS check, zero rAF), [17] one-cursor stand-down (each flag, falsy flags, touch device).

Counts at 1.3.1 (measured): 98 PHP and 160 browser checks plus the kit's 9 scenarios. The browser check 'hidden before first move' is intermittent in this headless Chromium (it fires a trusted mousemove at 0,0 after load, so the dot can already be visible); it failed in the pre-1.3.1 baseline run too, and passed in the final 1.3.1 run (160/160). Software GL makes `requestAnimationFrame` slow, so the tests poll instead of using fixed sleeps. Do not loosen a test to get green.

## 9. Known limits and what was not tested

- **Not run:** Firefox, Safari (desktop and iOS), Samsung Internet, Edge as a separate browser. Anything about them is by code reading, not run.
- **WebGL2 half-float linear filtering on real Safari/iOS hardware is unverified.** Since 1.2.0 the WebGL2 path no longer requires `OES_texture_float_linear` (half-float linear filtering is core in WebGL2); the old gate made some mobile/Safari GPUs lose bloom. If smoke looks wrong on iPhone/Mac Safari, that change is the first suspect. Real GPU speed and look were never judged (software GL only).
- **Not run:** real WordPress (PHP uses a stub harness), real multisite, real Salient/WPBakery/Elementor editors, real WP Rocket, LiteSpeed, Rocket Loader, Autoptimize, real bfcache (headless disables it), real touch hardware, the real Reframe theme cursor.
- Reduced motion is live since 1.3.1 (tested in Chromium with `emulateMedia`); other browsers' change events are by code reading, not run.
- The idle timer is wall-clock, but the simulation advances per frame, so on a very slow GPU the smoke can still be visible when the loop parks (the canvas is then cleared).
- With "Hide on touch" off, a tapped phone still shows the cursor after a tap.
- The follower tracks window mouse events only: it freezes over cross-origin iframes (by code reading).
- `Exposure.html` is a saved third-party page used only as reference. The cursor look is a replica of the TreeThemes "Deep" demo; the fluid engine is MIT. No ThemeForest or Kurato code is involved here, but check the licence of any design you copy before selling (see `FAMILY.md` "Before you sell").

## 10. Discrepancies found

Found in the docs-only pass, **fixed in 1.3.1** unless noted:
1. **`FAMILY.md` line about the licence is stale (open, not ours to edit).** It says `bubble_cursor` has no root `LICENSE` file yet. The repo has one since 1.3.0 (`LICENSE`, GPL-2.0; see `CHANGELOG.md`). This file is identical in every sibling repo and is generated from the hub, so fix it there.
2. **The "View" text was not a default label. FIXED in 1.3.1.** New "Default hover label" (`hover_label`, default "View", blank = no word); `hover_text` is now the on/off switch. Opt-outs: `data-bubble-cursor-no-text`, empty `data-bubble-cursor-text`. Older saved options are migrated on read.
3. **`textSelector` was dead. FIXED in 1.3.1:** removed from the JS and the docs.
4. **`mixBlend` had no admin field. FIXED in 1.3.1:** "Ring blend mode" select (`ring_blend`), whitelisted in `sanitize()`, sent as `mixBlend`.
5. **Install instruction in `README.md` said "Zip the folder". FIXED in 1.3.1:** README now gives `git archive --format=zip --prefix=flux-bubble-cursor/ -o flux-bubble-cursor.zip HEAD:bubble-cursor` (section 3 explains why `--prefix` matters).
6. **Reduced motion was read once at load. FIXED in 1.3.1:** live (see section 4 step 7).
7. Checked and **consistent** (no discrepancy): multisite-aware `uninstall.php` (loops `get_sites()` plus `delete_site_option`, covered by harness section [F]); cache purge on save; optimizer opt-outs; builder skips; once-only guard; `hide_native` safeguards.
8. Unverified claim in docs: `readme.txt` "Tested up to: 6.8" (never run on real WordPress). `README.md` says the theme and the plugin can both be on without a second cursor; that depends on the Reframe theme using identical class names, not checked.
9. **Not run:** Firefox, Safari (incl. Safari WebGL2 half-float), Samsung Internet, real WordPress. The sibling flags are only set by siblings that implement them; the sibling plugins were not run together with this one (the flags were pre-set by the tests).
