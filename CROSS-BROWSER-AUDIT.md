# Bubble Cursor: cross-browser and host-theme audit

Audited version: 1.1.0 (commit c2a83dd). Audit only: no plugin source was modified.

## Summary

**Verdict: solid engine, fragile host integration.** The WebGL engine and the dot/ring follower are written defensively (try/catch around init and the render loop, context-loss handling, debounced resize, WebGL1/WebGL2 fallbacks, no jQuery, namespaced globals). In Chromium there were **zero plugin JS errors** across every scenario, and hostile Salient-style host CSS did not break the layout. The problems are all in how the plugin survives things the host does *to* it: a script that rewrites `<html class>`, optimizer plugins, bfcache navigation, page-builder editors, and a second copy of the cursor.

Findings: **12 total = 1 High, 7 Medium, 4 Low.** Nine were reproduced by running code (Chromium or PHP); the rest are marked "by code reading, not run".

| # | Sev | Finding | Evidence |
|---|-----|---------|----------|
| 1 | High | All visible state lives in `<html>` classes; if a script rewrites `className`, the cursor goes invisible for good | Ran |
| 2 | Med | Settings travel in a separate inline script; no optimizer opt-outs; failure is silent (defaults used) | Ran + read |
| 3 | Med | `hide_native` plus reduced motion (or no followers) leaves the user with no cursor at all | Ran |
| 4 | Med | `pagehide` destroys the engine; nothing restores it on bfcache `pageshow` | Ran (synthetic events) |
| 5 | Med | Runs inside WPBakery frontend editor / Elementor preview / Customizer preview | Read |
| 6 | Med | No double-load guard (two cursors); PHP fatals if the file is loaded twice | Ran |
| 7 | Med | Touch-screen laptops lose the cursor entirely | Ran (hasTouch emulation) |
| 8 | Med | Smoke simulation renders every frame even when idle | Ran (draw-call count) |
| 9 | Low | A bad hover selector throws on every `mouseover` | Ran |
| 10 | Low | CSS polish: ring size depends on host `box-sizing`; hover state ignores size and colour settings; `100vw`/`100vh` canvas | Ran + read |
| 11 | Low | WebGL robustness: no `webglcontextrestored`; WebGL2 gate is stricter than needed (Safari/iOS may lose bloom) | Read |
| 12 | Low | WP housekeeping: page-cache not purged on settings save; uninstall single-site only | Read |

## What was tested

**Ran (Chromium only, via Playwright, headless, software GL):**
- A static fixture that reproduces the plugin's real front-end output: the real `bubble-cursor.css`, `fluid-cursor.js` and `bubble-cursor.js` served unmodified, with `window.BubbleCursorSettings` emitted exactly as `build_js_settings()` would with defaults (`wp_add_inline_script(..., 'before')` equivalent), scripts after `</body>` content (footer). This is not a real WordPress render; PHP output was only syntax-checked (`php -l`, clean).
- Desktop 1440x800: baseline, mouse move, hover over link, hover over `data-bubble-cursor-text` element, scrolling a 3000px page, window resize 1440 to 900 and back, RTL (`dir="rtl"`).
- Hostile host CSS emulating Salient: `button{all:unset}` / `body[data-form-style] button`, global `img,canvas,video{max-width:100%;height:auto}`, `body div{position:relative}`, global `*{box-sizing:border-box}`, `#header-outer` at z-index 9998, `a:hover{opacity:.7}`, `scroll-behavior:smooth`.
- A script that sets `document.documentElement.className` (a) 50 ms after `load`, (b) on DOMContentLoaded, (c) mid-session.
- Inline settings script stripped (the plugin has no inline *head* script; its only inline script is the settings assignment).
- Prefers-reduced-motion, with and without `hide_native`; `hide_native` with both followers off.
- Devices: iPhone 13, Pixel 7, iPad (gen 7), iPad Pro 11, plus desktop with `hasTouch`; also `hideOnTouch=false` on iPhone 13 and iPad.
- Invalid hover selector; hovering an element then removing it; hovering then scrolling the element away; `pagehide`/`pageshow` synthetic events; idle draw-call counting via a `drawElements` wrapper.
- PHP: `php -l` on both PHP files; a stubbed harness that includes `bubble-cursor.php` twice.
- Console: only Chromium "GPU stall due to ReadPixels" performance warnings from software GL (not from the plugin, not errors). No `pageerror` except in the invalid-selector test (finding 9).

**Only read, not run:** everything about Firefox, Safari, iOS WebKit, Edge, Samsung Internet and older versions; real WordPress behaviour (enqueue output, caching and optimizer plugins, multisite, uninstall); the WPBakery and Elementor editors; real bfcache; real iOS `100vh`.

## Findings

### 1. High: cursor state lives only in `<html>` classes and is lost if the host rewrites `className`
- Where: `assets/js/bubble-cursor.js:63-65` (adds `bc-active`, `bc-hide-native`), `:117` (adds `bc-visible` once), `:172-188` (hover classes); `assets/css/bubble-cursor.css:80-82` (`html.bc-visible .bc-dot, .bc-ring { opacity: var(--bc-opacity) }`), `:86-101` (hover rules).
- What breaks: `.bc-dot` and `.bc-ring` have `opacity: 0` until `html.bc-visible` exists. `visible` is a JS flag that is set once, so `classList.add('bc-visible')` is never retried. Any theme or optimizer script that does `document.documentElement.className = ...` (the classic `no-js` to `js` swap does exactly this) wipes the classes. All browsers affected, any theme that does it. I could not confirm that Salient itself does this; the pattern is known from the sibling Flux Reel bug.
- Evidence (Chromium): rewrite 50 ms after load: html class became `theme-js loaded`, dot and ring `opacity: 0` permanently, even after further mouse moves. Rewrite at DOMContentLoaded (before init) survived. Wiping the class mid-session with `hide_native` on: dot opacity 0, native cursor shown. Hover/"View" state also vanishes, since `bc-hover*` are html classes too.
- Fix: stop depending on html classes for visibility. Put the state on the elements themselves (e.g. a `data-bc-visible` attribute or inline `style.opacity` on `.bc-dot`/`.bc-ring`, hover as classes on the ring), and keep the html markers as a convenience only. Re-assert markers with a `MutationObserver` on `documentElement` `attributes: ['class']` (re-add `bc-active`, `bc-visible`, `bc-hide-native`, hover classes from the JS state) and disconnect on `pagehide`.

### 2. Medium: settings channel and script tags are optimizer-fragile; failure is silent
- Where: `bubble-cursor.php:184-206` (`wp_enqueue_script` x2, `wp_add_inline_script(..., 'before')` on `bubble-cursor-fluid`); no `script_loader_tag` filter anywhere (grep confirmed); no `strategy`/`defer`.
- What breaks: WP Rocket (delay JS, remove-unused), LiteSpeed (JS delay/combine), Cloudflare Rocket Loader, Autoptimize can delay, reorder or strip the inline `BubbleCursorSettings` script independently of the external file. Then `bubble-cursor.js` runs with `window.BubbleCursorSettings` undefined and silently uses its built-in defaults (colour, sizes, hover text, selector, smoke tuning all reset), with no console message. Reproduced in the fixture: with the inline script stripped, `settings:false` and the cursor still renders with defaults.
- Fix: add a `script_loader_tag` filter for both handles adding `data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false" data-pagespeed-no-defer data-wpfc-render="false" nowprocket` (and `data-no-delay`-style attributes you use elsewhere). Make the settings robust: also emit the settings as a JSON `data-bc-settings` attribute on the `bubble-cursor` script tag (id is `bubble-cursor-js`) and have the JS read the global first, then that attribute, then defaults. Log a single `console.warn` when falling back to defaults.

### 3. Medium: `hide_native` can leave the visitor with no cursor at all
- Where: `bubble-cursor.js:61-65` and `:80`; `bubble-cursor.css:20-23`.
- What breaks: `bc-hide-native` is added *before* the reduced-motion check, and the dot/ring are skipped when `prefers-reduced-motion` is set (`hasFollower` is false). CSS then applies `cursor: none !important` to every element with nothing drawn in its place. Same when the admin turns off both dot and ring but leaves "Hide native cursor" on. Accessibility problem for reduced-motion users; default is off, so only sites that enabled it are affected.
- Evidence (Chromium): reduced-motion plus `hideNativeCursor:true`: `body` computed cursor `none`, 0 dots, 0 rings, 0 canvases. Same with followers disabled.
- Fix: only add `bc-hide-native` after the dot or ring actually exists (and is visible, i.e. after the first mouse move), remove it on teardown or when elements are gone; in PHP/admin, ignore `hide_native` when neither follower is enabled.

### 4. Medium: engine is torn down on `pagehide` and never restored from bfcache
- Where: `bubble-cursor.js:195-198` (cancels the ring rAF, calls `BubbleCursorFluid.stop()` which destroys the sim and calls `WEBGL_lose_context`). No `pageshow` handler.
- What breaks: Back/forward navigation restored from the bfcache (Safari, Firefox, Chrome) fires `pagehide` (persisted) then `pageshow` on return; the page is resumed with the JS state intact but the smoke is dead, the context is lost for good, and the ring no longer eases. By code reading for real bfcache; the logic was reproduced with synthetic events.
- Evidence (Chromium, synthetic `pagehide`/`pageshow` with `persisted:true`): `isRunning()` false; after moving the mouse the dot followed but the ring stayed at its old position.
- Fix: in `pagehide` only stop when `!e.persisted`; for persisted, pause. Add a `pageshow` handler that, when `e.persisted`, restarts the rAF loop and re-creates the fluid sim (new canvas or fresh context). Re-run the marker re-assertion from finding 1.

### 5. Medium: cursor loads inside page-builder editors and previews
- Where: `bubble-cursor.php:149-166` (`should_load` only checks enable, `is_admin()`, scope; the `bubble_cursor_should_load` filter exists but nothing uses it).
- What breaks: The WPBakery frontend editor (`?vc_editable=true`, the Salient workflow) and Elementor preview render the real front end in an iframe where `is_admin()` is false. The full-viewport canvas and the ring appear over the editing surface; with `hide_native` on the pointer disappears in the editor. Also Customizer preview. By code reading, not run (no WordPress here); the fixture ignores the query string, confirming only that nothing in the JS guards against it.
- Fix: in `should_load` return false for `is_customize_preview()`, `isset($_GET['vc_editable'])` / `function_exists('vc_is_page_editable') && vc_is_page_editable()` / `vc_is_inline()`, Elementor (`class_exists('\Elementor\Plugin') && \Elementor\Plugin::$instance->preview->is_preview_mode()` and `?elementor-preview`), plus `et_fb`, `fl_builder`, `ct_builder`, `brizy-edit`. Use `sanitize_key`/`isset` only (read-only checks, no nonce needed). Also bail in JS if `body` has `elementor-editor-active` or the page is in a builder iframe.

### 6. Medium: no guard against loading twice (two cursors); PHP fatals on double include
- Where: `bubble-cursor.js` (no once-only guard); `bubble-cursor.php:23-27, 544-551` (unguarded `define()`, class and function declarations).
- What breaks: The README says to use *either* the Reframe theme's built-in "Bubble" cursor *or* this plugin. Nothing enforces that; the same code in two places gives two canvases, dots and rings. A second copy of the plugin in a different folder (zip re-upload) triggers a PHP fatal.
- Evidence: loading `bubble-cursor.js` twice produced 2 canvases, 2 dots, 2 rings (only the last fluid sim is live; `BubbleCursorFluid.start()` destroys the first sim but leaves its canvas). Including `bubble-cursor.php` twice in a stubbed PHP harness: `PHP Fatal error: Cannot redeclare bubble_cursor_activate()`.
- Fix: JS: `if (window.__bubbleCursorBooted || document.querySelector('.bc-dot,.bc-ring,.bc-fluid-canvas')) return; window.__bubbleCursorBooted = true;` (also check the theme's marker class if the Reframe theme sets one). PHP: wrap constants, class and activation function in `if ( ! defined( 'BUBBLE_CURSOR_VERSION' ) )` / `class_exists` / `function_exists`, and return early instead of redeclaring.

### 7. Medium: touch-screen laptops (and some hybrids) lose the cursor completely
- Where: `bubble-cursor.js:20-24, 57-59`.
- What breaks: `isTouch()` returns true when `'ontouchstart' in window` or `navigator.maxTouchPoints > 0`, which holds on any Windows/Chromebook laptop with a touch panel even when driven by a mouse (Chrome, Edge, Firefox, Samsung DeX). With `hideOnTouch` (the default) the whole plugin bails at load.
- Evidence (Chromium, desktop context with `hasTouch:true`): html class stays `no-js`, 0 elements created. Phones and tablets bailing out is correct and was confirmed (iPhone 13, Pixel 7, iPad gen 7, iPad Pro 11: no elements, no errors).
- Fix: decide by capability, not by touch presence: use `matchMedia('(any-hover: hover) and (any-pointer: fine)')` as the gate (with the current test as fallback where `any-hover` is unsupported), or start dormant and enable on the first real `mousemove` whose `pointerType` is `mouse`.
- Note: with `hideOnTouch=false` on phone/tablet emulation the dot appears after a tap and stays; the fluid listens to `touchmove`. Not a bug, just an unrecommended setting.

### 8. Medium: the smoke simulation renders every frame even with no input
- Where: `assets/js/fluid-cursor.js:1056-1075` (`start()` loop always runs `frame()`), `:730` (`step`), `:802` (`render`). Ring loop in `bubble-cursor.js:124-131` also writes a transform every frame.
- What breaks: a full-viewport WebGL fluid solve (20 pressure iterations plus bloom) runs continuously on every page for every visitor, including when the mouse is still, the pointer is outside the window, or the smoke has fully faded. Battery and fan cost on laptops; compounds with other WebGL pages. Browser pauses rAF in hidden tabs, so that part is fine.
- Evidence (Chromium, software GL, no mouse movement): 172 `drawElements` calls in 2 s. Absolute number is not meaningful for real GPUs; the point is it is non-zero when idle.
- Fix: stop the rAF loop once all pointers are idle and dye/velocity have decayed (e.g. 3 s after the last splat, or when the pointer left the window), restart on `mousemove`; skip the ring transform write when the ring has converged (delta below 0.05px).

### 9. Low: an invalid hover selector throws on every `mouseover`
- Where: `bubble-cursor.js:169, 184, 187` (`closest(settings.hoverSelector)` outside any try/catch); `bubble-cursor.php:316` (`sanitize_text_field` does not validate CSS).
- Evidence: selector `a[href, .x` produced 4 uncaught `SyntaxError: ... is not a valid selector` page errors from four mouse moves; hover state never applies.
- Fix: validate once at init with a `try { document.createElement('i').matches(sel) } catch` and fall back to the default selector, and `console.warn` once.

### 10. Low: CSS polish
- **Ring size depends on the host's `box-sizing`.** `.bc-ring` has no `box-sizing`. Fixture: 42x42 on a plain page, 40x40 when the host sets `* {box-sizing:border-box}` (Salient does). Add `box-sizing: border-box` to `.bc-dot` and `.bc-ring` (`bubble-cursor.css:30-48`).
- **Hover state ignores the size and colour settings.** `html.bc-hover .bc-ring` is fixed at 64px and `bc-hover-text` at 90px with a hard-coded white fill and black label (`:86-101, 63`). Verified: `ringSize=100` shrinks to 64px on hover; with `ringColor:#ff0000` the hover background is still white-ish. Derive hover sizes from the configured size (e.g. `calc(var(--bc-ring-size) * 1.6)`) and expose a hover/label colour variable.
- **Canvas sized with `100vw`/`100vh`** (`:12-13`). On desktops with a classic scrollbar `100vw` includes the scrollbar so the pointer-to-canvas mapping is a few percent off at the right edge; on iOS `100vh` includes the collapsing URL bar (only reachable if `hideOnTouch` is off). Use `inset: 0; width: 100%; height: 100%` (or `100dvh` with a `100vh` fallback). By code reading, not run (headless Chromium uses overlay scrollbars).

### 11. Low: WebGL robustness (by code reading, not run)
- **No `webglcontextrestored` handling** (`fluid-cursor.js:1040-1045` handles loss only): after a GPU reset or GPU switch the smoke stays dead until reload. Rebuild the sim on `webglcontextrestored`.
- **The WebGL2 path gates linear filtering on `OES_texture_float_linear`** (`fluid-cursor.js:93-94`) although the textures are half-float, whose linear filtering is core in WebGL2. On devices lacking that extension (typically iOS Safari, some mobile GPUs) the code falls back to a 512 dye with shading and bloom off (the fallback itself is fine and works). Consider checking half-float linear support instead. Cannot verify the actual extension availability on Safari from here.
- The rest of the engine (WebGL1 fallback, half-float format probing, `getExtension` guards, passive touch listener) reads as compatible with Firefox, Safari 15+, Edge and Samsung Internet; no CSS `@property`, `color-mix`, `:has`, container queries, `inert`, `structuredClone`, `scrollend`, `backdrop-filter`, `requestIdleCallback` or `ResizeObserver`/`IntersectionObserver` is used.

### 12. Low: WordPress housekeeping (by code reading, not run)
- Settings are baked into the page HTML, but saving settings does not purge any page cache (no hook on `update_option_bubble_cursor_options` calling `rocket_clean_domain()`, `do_action('litespeed_purge_all')`, `w3tc_flush_all()`, etc.), so visitors may keep old settings until the cache expires.
- `uninstall.php` deletes the option for the current site only; on multisite, loop `get_sites()` or use `delete_site_option` as appropriate.
- Asset `ver` is the plugin version, so edited JS/CSS without a version bump is served stale (use `filemtime` or always bump).

### Checked and fine
- No jQuery or `$`; everything is an IIFE; globals are namespaced (`BubbleCursorFluid`, `BubbleCursorSettings`).
- Admin screen: `manage_options` check, Settings API nonce, all output escaped, input sanitised and clamped; `uninstall.php` guarded by `WP_UNINSTALL_PLUGIN`. Admin hooks do not run extra work on the front end beyond constructing the class.
- Enqueue: handles, versions and `in_footer = true` are correct; dependency order fluid then main is correct.
- z-index: cursor at 2147483640/2147483646 stays above Salient's `#header-outer` (9998), with `pointer-events: none` so it never blocks clicks. Host `body div{position:relative}`, `img,canvas{max-width:100%;height:auto}`, `button{all:unset}` did not alter the dot, ring or canvas (single-class selectors beat element selectors).
- RTL, scrolled pages and window resize (canvas and framebuffers resize correctly) behaved.
- Hover cleanup after the hovered element is removed or scrolled away worked in Chromium; the Firefox/Safari behaviour here is unverified.

## Not tested / limits
- **Firefox, Safari and iOS WebKit, Edge, Samsung Internet and older versions were not run (not available here).** Everything about them is "by code reading, not run". Edge is Chromium, so the Chromium results largely apply to it.
- Real GPU behaviour: headless Chromium used software GL, so performance and visual quality of the smoke were not judged; only that it runs without errors.
- No real WordPress: PHP was syntax-checked and exercised only through a stub harness. No real Salient, WPBakery, Elementor, WP Rocket, LiteSpeed, Rocket Loader or Autoptimize. The fixture reproduces the optimizer failure mode (inline settings missing), not the plugins.
- Real bfcache was not exercised (headless automation disables it); only synthetic `pagehide`/`pageshow`.
- Whether Salient's own scripts rewrite `<html class>` is unconfirmed; finding 1 is verified against a synthetic rewrite.
- Test scripts lived in the session scratchpad and are not committed; the fix session should add its own tests (see prompt).

---

## Resolved in 1.2.0

All 12 findings were addressed in 1.2.0. "Test" names the section of `tests/run.js` (Chromium, `[n]`) or `tests/php-harness.php` (stubbed WordPress, `[X]`) that covers it; run both with `./tests/run.sh`. **Firefox, Safari and iOS WebKit were not run** (not available here), real WordPress, real optimizer plugins, the real page-builder editors and real bfcache were not exercised either; the "Ran / by code reading" labels above still apply to everything outside the tests listed here.

| # | Status | What changed | Test |
|---|--------|--------------|------|
| 1 | Fixed | Visibility is `data-bc-visible` on the dot/ring, hover is `data-bc-state` on the ring (`data-bc-hover` on the dot). `html.bc-*` classes are markers only, re-asserted by a `MutationObserver` (disconnected on `pagehide`, reconnected on persisted `pageshow`). `--bc-opacity` moved onto the elements. | `[2]` rewrite after load and at DOMContentLoaded, mid-session wipe, wipe while hovering |
| 2 | Fixed | PHP `script_loader_tag` filter adds the opt-out attributes to both handles (and their inline scripts) and mirrors the settings JSON into `data-bc-settings` on `#bubble-cursor-js`. JS reads global, then attribute, then defaults with one `console.warn`. The inline global is kept. Not tested against real WP Rocket / LiteSpeed / Rocket Loader / Autoptimize. | `[3]` (stripped inline script, neither channel), `[C]` (tag output, escaping, idempotence) |
| 3 | Fixed | `bc-hide-native` is only set while a follower exists, motion is allowed and the cursor is visible; removed on teardown. `sanitize()` forces `hide_native` to 0 with both followers off, and the JS setting is neutralised for old saved options. Settings text updated. | `[4]`, `[D]` |
| 4 | Fixed | `pagehide` stops everything (loop, fluid, observer); persisted `pageshow` rebuilds the fluid sim on a fresh canvas, restarts the ring and re-attaches the observer. Verified with synthetic events only. | `[5]` |
| 5 | Fixed | `should_load()` returns false for the Customizer preview, `vc_editable`, `vc_is_page_editable()`/`vc_is_inline()`, Elementor preview mode and `?elementor-preview`, `et_fb`, `fl_builder`, `ct_builder`, `brizy-edit`(`-iframe`). JS also bails on `body.elementor-editor-active` and the same query args. Not run inside a real editor. | `[B]` (PHP), `[12]` (JS) |
| 6 | Fixed | JS once-only guard (`window.__bubbleCursorBooted` or existing elements); PHP returns early if `BUBBLE_CURSOR_VERSION` is defined and the class/function declarations are guarded. The Reframe theme's own marker class was not checked, so coexistence relies on the DOM-element check. | `[6]`, `[A]` |
| 7 | Fixed | Gate is `(any-hover: hover) and (any-pointer: fine)` with the old sniff as fallback where `any-hover` is unsupported. Devices that still report touch-only start dormant and switch on after a real mouse (`pointerType === "mouse"`) travels a few pixels or clicks; this was needed because Chromium's `hasTouch` emulation reports no hover/fine pointer, and because Chromium synthesises one mouse `pointermove` at load even on emulated phones (a single event is therefore not trusted). | `[7]` (hasTouch desktop dormant then active; iPhone 13, Pixel 7, iPad gen 7, iPad Pro 11 stay empty, including after taps) |
| 8 | Fixed | The fluid loop parks itself after the pointer has been idle for `max(3 s, ~4.6/fade s)` (capped at 12 s, so the smoke has faded), clears the canvas, and `mousemove`/`touchmove` wake it. The ring loop parks when it converges (under 0.05 px). Measured as draw calls and style writes in software GL; GPU/battery effect not measured. | `[8]` |
| 9 | Fixed | Selector validated once at init; falls back to the default with one `console.warn`; mouse handlers are also wrapped in try/catch. | `[9]` |
| 10 | Fixed | `box-sizing: border-box` on dot and ring; hover sizes are `calc(var(--bc-ring-size) * 1.6 / 2.25)`; `--bc-hover-fill`, `--bc-text-fill`, `--bc-label-color` set from the ring colour; canvas uses `inset: 0; width/height: 100%`. The `100vw` scrollbar case was not run (headless uses overlay scrollbars); only that the canvas equals the viewport. | `[1]`, `[10]` |
| 11 | Fixed (partly unverified) | `webglcontextrestored` rebuilds the sim (tested with `WEBGL_lose_context`). The WebGL2 path no longer requires `OES_texture_float_linear` (half-float linear filtering is core in WebGL2); the 512/no-bloom fallback is kept for WebGL1 without the half-float linear extension. Not verified on real Safari/iOS hardware. | `[11]` |
| 12 | Fixed | Settings save (`update_option_`/`add_option_`) purges WP Rocket, LiteSpeed, W3TC, Autoptimize and SG Optimizer when present; `uninstall.php` loops `get_sites()` and also calls `delete_site_option`; asset `ver` is `1.2.0-<filemtime>`. Not run on real WordPress or multisite. | `[E]`, `[F]`, `[C]` |

Known limits of the fixes: the idle timeout is wall-clock while the simulation advances per frame, so on a very slow GPU the smoke can still be visible when the loop parks (the canvas is then cleared); the Reframe theme cursor and real optimizer plugins were not tested; `hideOnTouch=false` still shows the cursor after a tap on phones, as before.

---

## Prompt for the fix session

Paste everything below into a new thread.

```
You are working in the WordPress plugin repo /home/user/bubble_cursor (GitHub zeerebel/bubble_cursor), plugin folder bubble-cursor/, current version 1.1.0. Read CROSS-BROWSER-AUDIT.md at the repo root first; it lists 12 findings with file:line and evidence. Implement every fix below, add tests, bump the version and update docs. Work on a claude/... branch; never push to main. Keep the plugin dependency-free (no jQuery, no build step). Host themes include Salient (WPBakery), so be hostile-CSS and optimizer safe.

FIXES

1. (High) State must survive `document.documentElement.className = ...`. In assets/js/bubble-cursor.js and assets/css/bubble-cursor.css, stop relying on html classes for visibility: show/hide the dot and ring via a data attribute or inline style on the elements themselves, and hover/hover-text via a class or data attribute on the ring. Keep html classes (bc-active, bc-visible, bc-hide-native, bc-hover, bc-hover-text) only as optional markers, and re-assert them from JS state with a MutationObserver on documentElement (attributes: ['class']); disconnect it on pagehide and re-connect on persisted pageshow.

2. (Med) In bubble-cursor.php add a script_loader_tag filter for handles 'bubble-cursor-fluid' and 'bubble-cursor' adding data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false" data-pagespeed-no-defer data-wpfc-render="false" nowprocket (and data-no-delay style attributes). Also emit the settings JSON as a data-bc-settings attribute on the 'bubble-cursor' script tag (id bubble-cursor-js) and make the JS read window.BubbleCursorSettings first, then that attribute, then defaults, with one console.warn when it falls back to defaults. Keep the inline global for backwards compatibility.

3. (Med) Only add bc-hide-native after the dot or ring really exists (ideally after the first mouse move), remove it on teardown, never apply it under prefers-reduced-motion or when both followers are disabled. In sanitize(), force hide_native to 0 when both enable_dot and enable_ring are 0, and mention it in the settings description.

4. (Med) pagehide: only destroy when !e.persisted; on persisted pagehide pause, and add a pageshow handler that restarts the ring rAF loop and recreates the fluid sim (fresh canvas or context) when e.persisted.

5. (Med) should_load(): return false for is_customize_preview(), WPBakery frontend editor (isset($_GET['vc_editable']), vc_is_page_editable() / vc_is_inline() when they exist), Elementor preview (?elementor-preview, \Elementor\Plugin::$instance->preview->is_preview_mode() when the class exists), and et_fb, fl_builder, ct_builder, brizy-edit. Read-only isset checks, no nonce needed. In JS also bail when body has class elementor-editor-active. Keep the bubble_cursor_should_load filter.

6. (Med) JS: once-only guard (window.__bubbleCursorBooted or existing .bc-dot/.bc-ring/.bc-fluid-canvas in the DOM) so a second copy, or the Reframe theme's built-in Bubble cursor, does not create a second cursor. PHP: guard constants, the Bubble_Cursor class and bubble_cursor_activate() with defined()/class_exists()/function_exists() and return early instead of fataling when the file is loaded twice.

7. (Med) Replace isTouch() with a capability test: bail only when matchMedia('(any-hover: hover) and (any-pointer: fine)') is false (fall back to the old check only when any-hover is unsupported), or start dormant and enable on the first mousemove from a mouse. Touch-screen laptops with a mouse must get the cursor; phones and tablets must still bail.

8. (Med) Idle loop: stop the fluid rAF loop once pointers are idle and the sim has decayed (about 3 s after the last splat or when the pointer leaves the window), restart on mousemove. Skip ring transform writes when it has converged (under 0.05px). Do not break the smoke look.

9. (Low) Validate hoverSelector once at init (try document.createElement('i').matches(sel)); fall back to the default selector and console.warn once. Never throw from the mouseover/mouseout handlers.

10. (Low) CSS: add box-sizing: border-box to .bc-dot and .bc-ring; derive hover sizes from --bc-ring-size (e.g. calc(var(--bc-ring-size) * 1.6)) instead of fixed 64px/90px; add CSS variables for hover fill and label colour and set them from the ring colour in JS; replace the canvas 100vw/100vh with inset:0 / width:100% / height:100% (use 100dvh with a 100vh fallback if needed). Keep specificity low and prefixed (bc-); use :where() for any reset, and :is(.bc-x, #boost) only if a host rule is proven to override.

11. (Low) fluid-cursor.js: handle webglcontextrestored by rebuilding the sim; for the WebGL2 path check half-float linear filtering support instead of requiring OES_texture_float_linear, keeping the existing 512/no-bloom fallback when linear filtering is unavailable.

12. (Low) Purge page caches on settings save (hook update_option_bubble_cursor_options; call WP Rocket rocket_clean_domain, do_action('litespeed_purge_all'), w3tc_flush_all, autoptimizeCache::clearall, each only if it exists). uninstall.php: also handle multisite (loop get_sites(), delete_option per site, and delete_site_option). Use filemtime() for asset versions or always bump BUBBLE_CURSOR_VERSION.

TESTS (create tests/ in the repo, Playwright for Node, Chromium only, no `playwright install`; add a short tests/README.md and an npm-free runner script)
- A static fixture page that loads the real plugin css/js with hostile host CSS (Salient-like: body[data-form-style] button, img/canvas max-width, body div{position:relative}, *{box-sizing:border-box}, #header-outer z-index 9998).
- Assertions, desktop 1440x800 plus iPhone 13 / Pixel 7 / iPad emulation (do not set deviceScaleFactor): cursor visible after mouse move; hover and "Open" text states; no console errors or pageerrors; html className rewrite after load does not hide the cursor (finding 1); inline settings stripped still uses data-bc-settings (2); hide_native with reduced motion leaves a visible native cursor (3); synthetic pagehide/pageshow persisted restores ring and smoke (4); script loaded twice yields exactly one canvas, dot and ring (6); hasTouch desktop context still shows the cursor while iPhone/iPad/Pixel do not (7); invalid hover selector produces no pageerror (9); ring is 40px under both box-sizing modes and hover size follows the configured ring size (10); RTL, scrolled page and window resize.
- A PHP harness (php CLI, stubbed WP functions) that includes bubble-cursor.php twice without fatal, and calls sanitize() and should_load() branches including the editor query args.
- Run php -l on all PHP, run the whole suite, and fix until green.

RELEASE
- Bump version to 1.2.0 in bubble-cursor.php (header and BUBBLE_CURSOR_VERSION), readme.txt (Stable tag, changelog), and README.md (version mention in the identity block).
- Update readme.txt FAQ/changelog and README.md for: editor auto-disable, touch-laptop behaviour, optimizer opt-outs, cache purge, once-only guard (and that it is safe next to the Reframe theme cursor, though only one should be enabled).
- Update CROSS-BROWSER-AUDIT.md with a "Resolved in 1.2.0" section listing each finding and the test that covers it; keep the "not run" labels for Firefox/Safari/iOS.
- Do not modify Exposure.html, demo/ images, or the screenshot. Commit on the claude/... branch with the usual trailers, push that branch only, and open a draft PR. State plainly in the PR that Firefox and Safari were not run.
```
