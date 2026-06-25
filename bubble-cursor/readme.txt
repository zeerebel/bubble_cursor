=== Bubble Cursor — Smokey Fluid Cursor ===
Contributors: zeerebel
Tags: cursor, custom cursor, fluid, webgl, smoke, mouse, elementor
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.0
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

* Hidden automatically on touch devices (phones/tablets).
* The heavy smoke layer is disabled for visitors who set
  "prefers reduced motion" in their OS.
* Gracefully does nothing if the browser has no WebGL.

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

== Changelog ==

= 1.0.0 =
* Initial release: WebGL smoke fluid layer, dot + ring follower, "View" hover
  bubble, and a full settings screen.

== Credits ==

The bundled WebGL fluid engine is adapted from Pavel Dobryakov's
WebGL-Fluid-Simulation (MIT License). See assets/js/LICENSE-fluid.txt.
