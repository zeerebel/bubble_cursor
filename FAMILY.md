# The Flux family of WordPress plugins

> Canonical copy. Every repo in the family carries an identical `FAMILY.md`; the shared kit's
> source lives in `kit/includes/flux-family/`. When this file changes, copy it to every repo
> (`tools/sync-family.sh`).

Owner: **zeerebel** (Mark Phu). The Flux plugins are small, library-light WordPress plugins for
portfolio and agency sites. Each one works on its own. Together they are one **family**: the same
conventions, one shared "Flux" dashboard, one support page, one set of rules for working next to
each other, and one way to add the next plugin.

## The members

| Plugin (final repo name) | Group | What it does |
| --- | --- | --- |
| `flux-reel` | ui | Scroll timecode in the header, film-style menu overlay, footer reel |
| `flux-backgrounds` | visual | Animated WebGL backgrounds (12 modes) |
| `flux-preloader` | visual | Intro splash that counts up while the page loads |
| `flux-reveal` | visual | Blur-in scroll reveals for headings and sections |
| `flux-smooth-scroll` | scroll | Weighted smooth scrolling (Lenis) |
| `flux-scroll-progress` | scroll | Slim reading-progress bar |
| `flux-jelly-cursor` | **cursor** | Elastic jelly blob cursor |
| `flux-bubble-cursor` | **cursor** | Dot, ring and smoke-trail cursor |
| `super-duper-cursor` | **cursor** | Dual-layer cursor that reacts to what you hover (WordPress.org edition) |
| `super-duper-cursor-pro` | **cursor** | Dual and jelly cursor engines in one plugin |

Not in the family: `TM-Pointer` (someone else's CodeCanyon plugin), the archived
`super-cursor-hybrid` and `super-duper-cursor-v2.0.4a`, the Reframe theme.

## How they fit together

1. **Install any subset.** Every plugin carries its own copy of the shared kit, so none of them
   needs another one installed.
2. **One kit, highest version wins.** `includes/flux-family/` is identical in every plugin. When
   several are active, each *offers* its copy and the highest kit version loads once
   (`flux_family_offer()` in `loader.php`). Kit changes are therefore always backwards
   compatible: **never change the signatures in `loader.php`.**
3. **One dashboard.** The kit adds a top-level **Flux** admin menu that lists the active family
   plugins, their versions and settings links, what else is in the family, and warnings.
4. **The one-cursor rule.** A page can only have one custom cursor. The four plugins in the
   `cursor` group register themselves as exclusive: if two are active, the dashboard and each
   plugin's settings screen show a warning. (A WordPress theme's own cursor can't be detected.)
5. **One support card.** Each plugin's settings page shows the same "Support the Flux family"
   card (Stripe, Buy Me a Coffee, PayPal). The links are plain links to pages hosted by those
   services: the plugins hold no payment keys, load no payment scripts and send no data. Site
   owners can hide the card under **Flux > Options**.
6. **Shared hardening rules.** Every plugin follows the checklist in its `CLAUDE.md`: no state
   only in the `<html>` class, settings that survive optimizers, optimizer opt-outs on its own
   tags, never hide something native until the replacement is running, skip page-builder editors,
   guard against two copies, respect reduced motion, multisite-aware uninstall.

## Setting the Stripe link (once, by the author)

The Stripe button stays hidden until a link is set. In Stripe, create a **Payment Link** for a
"customers choose what to pay" donation (or a fixed price), copy its `https://buy.stripe.com/...`
URL, then put it in `Flux_Family_Core::DEFAULT_STRIPE_URL` in `kit/includes/flux-family/core.php`,
bump the kit version, and sync the kit to every plugin. A site can also override it with
`define( 'FLUX_FAMILY_STRIPE_URL', 'https://…' );` in `wp-config.php`. Only `https` links are
accepted; anything else is dropped.

## Adding the next plugin to the family

1. Copy `kit/includes/flux-family/` into the plugin as `includes/flux-family/`.
2. In the plugin's main file, near the top:
   ```php
   require_once __DIR__ . '/includes/flux-family/loader.php';
   flux_family_offer( '1.0.0', __DIR__ . '/includes/flux-family/core.php' );
   flux_family_register( array(
       'slug'         => 'flux-yourthing',        // the final repo name
       'name'         => 'Flux Yourthing',
       'version'      => YOURPLUGIN_VERSION,
       'group'        => 'visual',                // cursor | scroll | visual | ui
       'description'  => 'One line.',
       'settings_url' => function () { return admin_url( 'options-general.php?page=your-page' ); },
   ) );
   ```
3. On the settings page, call `flux_family_render_card( 'Flux Yourthing' );`.
4. Add the plugin to `catalogue()` in `core.php` and to the table above; bump the kit version.
5. Copy this `FAMILY.md` into the new repo, add a `CLAUDE.md` and `HANDOFF.md` like the others.
6. Tests: run `php tests/test-family-kit.php` in the plugin and keep its own suite green.

## Before you sell (checklist, not legal advice)

- **Licence.** WordPress plugins are GPL. You can sell GPL plugins, and customers may share them;
  what you sell is the download, updates, support and convenience. Keep `LICENSE` in every repo
  (**`bubble_cursor` has no root `LICENSE` file yet: add one**).
- **Third-party code and credit.** Keep each bundled library's licence file next to it: Three.js
  (`flux-backgrounds/vendor/three.LICENSE`), Lenis (`fluxsmoothscroll/vendor/lenis.LICENSE`),
  the WebGL fluid simulation (`bubble-cursor/assets/js/LICENSE-fluid.txt`), fonts under the SIL
  Open Font Licence in `flux-reel/assets/fonts/`. List them in each `readme.txt` credits.
- **Flux Reel is the one to clear first.** It reproduces the look of the purchased **Kurato**
  theme (ThemeForest licence). The code was written from scratch and no theme CSS or images are
  copied, but do not sell it until you have checked that licence or changed the design enough.
  Never ship the Kurato zip with it (hence the separate `flux-reel` repo).
- **Privacy.** The plugins collect nothing and the support card is only links. Flux Reel can load
  Google Fonts if you choose one of those fonts: say so in its readme (it is a GDPR point in the EU).
- **Taking payment.** Stripe Payment Links are enough for donations. To *sell* plugins you also
  need delivery (a download and, if you want, update/licence keys) and tax handling: for example
  WooCommerce or Easy Digital Downloads with Stripe, or a "merchant of record" service that handles
  sales tax and VAT for you. Decide this before launch; the plugins do not enforce licences.
- **Testing.** Everything was tested in Chromium only. Before selling, test each plugin in real
  Chrome, Firefox and Safari (including a phone) on a staging copy of a real site.
- **WordPress.org.** `super-duper-cursor` is meant for the plugin directory, so it does not use
  the shared kit (the directory requires each plugin's text domain to match its own slug, and
  discourages extra admin menus). It keeps its own support box and links to this family.

## Where the details live

Per repo: `README.md` (what it is), `CLAUDE.md` (rules for Claude Code), `HANDOFF.md` (status and
how to resume), `CHANGELOG.md`, `CROSS-BROWSER-AUDIT.md` (Nov 2026 audit and fixes). The repo map
is `REPOS.md` in the `reframe` repo.
