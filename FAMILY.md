# The Flux family of WordPress plugins

> Canonical copy. Every repo in the family carries an identical `FAMILY.md`; the shared kit's
> source lives in `kit/includes/flux-family/`. When this file changes, copy it to every repo
> (`tools/sync-family.sh`).

Owner: **zeerebel** (Mark Phu). The Flux plugins are small, library-light WordPress plugins for
portfolio and agency sites. Each one works on its own. Together they are one **family**: the same
conventions, one shared "Flux" dashboard, one support page, one set of rules for working next to
each other, and one way to add the next plugin.

## The members

Final names are the target; until a repo is renamed on GitHub the repo name in the second column
is the live one. Versions change often: the **Flux** dashboard on a site lists what is active, and
`tools/refresh-family-docs.py` regenerates the version tables in every repo's docs.

| Plugin (final name) | Repo today | Group | What it does |
| --- | --- | --- | --- |
| `flux-reel` | `zeerebel/kurato` (`flux-reel/` folder; move to its own repo) | ui | Scroll timecode in the header, film-style menu overlay, footer reel |
| `flux-backgrounds` | `zeerebel/flux-backgrounds` | visual | Animated WebGL backgrounds (12 modes) |
| `flux-preloader` | `zeerebel/flux-preloader` | visual | Intro splash that counts up while the page loads |
| `flux-reveal` | `zeerebel/flux-reveal` | visual | Blur-in scroll reveals for headings and sections |
| `flux-smooth-scroll` | `zeerebel/fluxsmoothscroll` | scroll | Weighted smooth scrolling (Lenis) |
| `flux-scroll-progress` | `zeerebel/fluxscrollprogress` | scroll | Slim reading-progress bar |
| `flux-jelly-cursor` | `zeerebel/fluxjellycursor` | **cursor** | Elastic jelly blob cursor |
| `flux-bubble-cursor` | `zeerebel/bubble_cursor` | **cursor** | Dot, ring and smoke-trail cursor |
| `super-duper-cursor` | `zeerebel/super-duper-cursor` | **cursor** | Dual-layer cursor that reacts to what you hover (WordPress.org edition, no shared kit) |
| `super-duper-cursor-pro` | `zeerebel/super-duper-cursor-pro` | **cursor** | Dual and jelly cursor engines in one plugin |
| `flux-core` | `zeerebel/flux-core` | ui | Shared engine: scheduler, scroll bus, observers, registry, animation adapter (the dependency of the next five) |
| `flux-split` | `zeerebel/flux-split` | visual | Split text into lines, words or characters with staggered reveals (needs `flux-core`) |
| `flux-marquee` | `zeerebel/flux-marquee` | visual | Infinite marquee that reacts to scroll (needs `flux-core`) |
| `flux-pin` | `zeerebel/flux-pin` | scroll | Pinned scroll scenes and horizontal-scroll galleries (needs `flux-core`) |
| `flux-magnetic` | `zeerebel/flux-magnetic` | ui | Magnetic hover for buttons and cards (needs `flux-core`; draws no cursor, so not in group `cursor`) |
| `flux-distort` | `zeerebel/flux-distort` | visual | WebGL hover distortion for images (needs `flux-core`) |

Not in the family: `TM-Pointer` (someone else's CodeCanyon plugin), the archived
`super-cursor-hybrid`, `super-duper-cursor-v2.0.4a` (GSAP edition, superseded, archive
recommended), the Reframe theme.

### Siblings that touch each other

- **Scrolling:** `flux-reel` (timecode), `flux-scroll-progress` and `flux-reveal` read the window
  scroll; `flux-smooth-scroll` (Lenis) keeps scrolling on the window. A theme's own smooth scroll
  must be off when `flux-smooth-scroll` is on. These pairings are expected to work but were not
  tested together.
- **Flux Core:** `flux-split`, `flux-marquee`, `flux-pin`, `flux-magnetic` and `flux-distort` need `flux-core` (one shared scheduler, window scroll bus and registry). Without it they print an admin notice and leave the page untouched. GSAP is never bundled and is off by default.
- **Headings:** `flux-reveal` and `flux-split` can both target headings; `flux-reveal` skips text that `flux-split` owns. `flux-magnetic` skips elements a cursor plugin already pulls.
- **Cursors:** the four cursor plugins are mutually exclusive (the one-cursor rule). `super-duper-cursor-pro`
  contains a dual engine ported from `super-duper-cursor` and a jelly engine ported from
  `flux-jelly-cursor`. `super-duper-cursor` and the archived GSAP edition share function names and must
  never be active together.
- **Layers:** `flux-backgrounds` is a fixed canvas behind the content; `flux-reel` draws a bar and
  overlay (default z-index 99000); cursors draw above everything; `flux-preloader` covers all of it
  until the page has loaded (with a failsafe so it can never trap a visitor).

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

## Keeping the family docs in sync

The sibling tables in every repo's `CLAUDE.md`, `HANDOFF.md` and `README.md` are generated:
run `python3 tools/refresh-family-docs.py` (in the hub folder `flux-family-hub`, to become the repo
`zeerebel/flux-family`), review `git diff` in each repo, commit and push. Run it whenever a
version, a repo name or the member list changes. `tools/sync-family.sh` copies the kit and this
file to plugin repos.

## Where the details live

Per repo: `README.md` (what it is), `CLAUDE.md` (rules for Claude Code), `HANDOFF.md` (status and
how to resume), `CHANGELOG.md`, `CROSS-BROWSER-AUDIT.md` (Nov 2026 audit and fixes). The repo map
is `REPOS.md` in the `reframe` repo.
