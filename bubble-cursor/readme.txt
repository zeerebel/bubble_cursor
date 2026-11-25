=== Bubble Cursor — Smokey Fluid Cursor ===
Contributors: zeerebel
Tags: cursor, custom cursor, fluid, webgl, smoke, mouse, elementor
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A colourful WebGL "smoke" fluid trail plus a dot + ring custom cursor with a "View" hover bubble. A replica of the TreeThemes "Deep" theme cursor, for any WordPress theme.

== Description ==

Bubble Cursor recreates the eye-catching cursor seen on the TreeThemes "Deep"
Elementor demo. It layers two effects on top of your site:

1. **Smoke (fluid) trail** — a real-time WebGL fluid simulation that paints a
   colourful, glowing smoke wherever the mouse moves.
2. **Dot + ring cursor** — a small dot that tracks the pointer with an outline
   ring that eases behind it, and grows into a filled "View" bubble when you
   hover links, buttons, or any element you tag.

It works on **any theme** — Elementor is not required, but Elementor containers
that use the "Cursor Hover Effect Text" setting are detected automatically.

Everything is configurable from **Settings → Bubble Cursor**: enable/disable
each layer, colours, hover text, where it loads, and the smoke physics.

Performance & accessibility:

* Hidden automatically on touch-only devices (phones/tablets). Touch-screen
  laptops used with a mouse or trackpad still get the cursor.
* Not loaded inside page-builder editors and previews (WPBakery, Elementor,
  Divi, Beaver Builder, Brizy, the Customizer).
* The heavy smoke layer is disabled for visitors who set
  "prefers reduced motion" in their OS.
* Gracefully does nothing if the browser has no WebGL.

Part of the Flux family: see FAMILY.md in the repository for how the Flux plugins fit together (one shared Flux dashboard in the admin, one support card, one-cursor rule).

== Installation ==

1. Upload the `bubble-cursor` folder to `/wp-content/plugins/`, or install the
   zip via Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins menu.
3. Visit **Settings → Bubble Cursor** to tune colours, hover text, and where it
   loads. Defaults match the Deep demo.

== Frequently Asked Questions ==

= Do I need Elementor? =
No. It runs on any theme. Elementor "Cursor Hover Effect Text" settings are
picked up automatically when present.

= How do I show custom hover text on a specific element? =
Add `data-bubble-cursor-text="Open"` (or any word) to the element.

= How do I make an element trigger the enlarged ring? =
It already triggers on links and buttons. For anything else, add
`data-bubble-cursor-hover` to the element, or edit the hover selector in
settings.

= It does not show on my phone =
That is intentional — a fluid mouse cursor has no meaning on touch screens.

= It does not show on my touch-screen laptop =
It should: the plugin checks for a hover-capable fine pointer rather than for a
touch screen. If a browser reports touch only, the cursor starts the first time
a real mouse is moved.

= Why is the cursor missing in the page builder editor? =
On purpose. It is not loaded in the WPBakery frontend editor, Elementor editor
and preview, Divi/Beaver Builder/Brizy editors or the Customizer preview, so it
does not cover the editing surface. Use the `bubble_cursor_should_load` filter
if you need different behaviour.

= My optimizer / caching plugin breaks the settings =
Both scripts carry the usual opt-out attributes (data-no-optimize,
data-no-defer, data-cfasync="false", nowprocket and friends) and the settings
are also stored on the script tag as `data-bc-settings`, so they survive a
delayed or removed inline script. Saving the settings purges WP Rocket,
LiteSpeed, W3 Total Cache, Autoptimize and SG Optimizer caches.

= Can I run it next to the Reframe theme cursor? =
It will not create a second cursor (a once-only guard stops duplicates), but
you should still enable only one: the theme's Cursor Style "Bubble" or this
plugin.

= "Hide native cursor" left me without a pointer =
Fixed in 1.2.0: the native pointer is only hidden while the custom cursor is
visible, never for reduced-motion visitors, and the option is ignored if both
the dot and the ring are off.

== Changelog ==

= 1.3.0 =
* New: joins the Flux family. A shared "Flux" admin dashboard (lists the active Flux plugins, their versions and settings links, and warns if two cursor plugins are active) and a support card at the foot of Settings > Bubble Cursor, which can be hidden under Flux > Options. Admin only: nothing changes on the front end, and the plugin still works alone.
* New: the repository now has a root LICENSE file (GPL-2.0).

= 1.2.0 =
* Fix: cursor no longer disappears for good when a theme or optimizer script
  rewrites the `<html>` class; state now lives on the cursor elements and the
  html marker classes are re-asserted.
* Fix: touch-screen laptops with a mouse get the cursor (capability check
  instead of touch detection); phones and tablets still do not.
* Fix: "Hide native cursor" can no longer leave visitors with no pointer
  (reduced motion, both followers off, before the cursor appears).
* Fix: back/forward-cache restores rebuild the smoke and ring instead of
  leaving them dead; WebGL context restore rebuilds the smoke.
* Fix: an invalid hover selector no longer throws on every mouse move.
* New: not loaded in page-builder editors/previews and the Customizer.
* New: loading the cursor twice does not create two cursors; a second copy of
  the plugin file no longer causes a PHP fatal.
* New: optimizer opt-out attributes on both scripts, settings mirrored in a
  `data-bc-settings` attribute, one console warning if defaults are used.
* New: page caches are purged when settings are saved.
* Performance: the smoke loop and the ring stop drawing when the pointer is
  idle and the smoke has faded, and resume on mouse move.
* CSS: ring size no longer depends on the host's box-sizing; hover sizes follow
  the configured ring size; hover fill/label colours follow the ring colour;
  smoke canvas no longer uses 100vw/100vh.
* WebGL2: linear filtering is no longer gated on OES_texture_float_linear
  (not verified on Safari/iOS hardware).
* Housekeeping: uninstall handles multisite; asset versions include file mtime.

= 1.1.0 =
* New controls: dot/ring size, ring thickness, cursor opacity, smoke opacity,
  smoke blend mode, smoke intensity/brightness, bloom intensity, swirl, and a
  Low/Medium/High quality preset.
* Stability: framebuffers are now freed on resize (fixes a GPU-memory leak),
  resize handling is debounced, WebGL context-loss is handled gracefully, and
  both the engine loop and front-end init are wrapped so a cursor error can
  never take the page down.

= 1.0.0 =
* Initial release: WebGL smoke fluid layer, dot + ring follower, "View" hover
  bubble, and a full settings screen.

== Credits ==

The bundled WebGL fluid engine is adapted from Pavel Dobryakov's
WebGL-Fluid-Simulation (MIT License). See assets/js/LICENSE-fluid.txt.
