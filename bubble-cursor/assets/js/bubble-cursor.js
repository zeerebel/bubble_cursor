/*!
 * Bubble Cursor — dot + ring follower with hover "View" bubble
 * Pairs with fluid-cursor.js (the smoke layer). Both are orchestrated here.
 *
 * Settings are read from, in order: window.BubbleCursorSettings (injected by
 * the WordPress plugin), the data-bc-settings JSON attribute on this script's
 * own <script id="bubble-cursor-js"> tag (survives optimizers that drop or
 * delay the inline script), then built-in defaults (one console.warn).
 *
 * One cursor only: if another Flux cursor plugin has flagged itself
 * (window.__fluxJellyActive, __superCursorActive, __sdcpJellyActive,
 * __sdcpDualActive) this script stands down, draws nothing and leaves the
 * native cursor alone. It sets window.__bubbleCursorBooted when it starts so
 * a sibling that boots later can do the same check.
 *
 * Reduced motion is live: the follower and the smoke are removed (and the
 * native cursor comes back) when the preference turns on, and rebuilt when it
 * turns off.
 *
 * State that decides visibility lives on the dot/ring elements, not on <html>
 * classes. The html.bc-* classes are markers that are re-asserted if a host
 * script rewrites document.documentElement.className.
 */
(function (window, document) {
  'use strict';

  var DEFAULT_HOVER_SELECTOR = 'a[href], button:not(:disabled), input[type="submit"], input[type="button"], .elementor-button, [data-bubble-cursor-hover]';
  var EDITOR_QUERY = /[?&](vc_editable|vc_action|elementor-preview|et_fb|fl_builder|ct_builder|brizy-edit|brizy-edit-iframe|customize_changeset_uuid|customize_messenger_channel)(=|&|$)/;

  var OTHER_CURSOR_FLAGS = ['__fluxJellyActive', '__superCursorActive', '__sdcpJellyActive', '__sdcpDualActive'];
  var warnedOther = false;

  // Returns the name of the first other-cursor flag that is truthy, or ''.
  function otherCursor() {
    for (var i = 0; i < OTHER_CURSOR_FLAGS.length; i++) {
      if (window[OTHER_CURSOR_FLAGS[i]]) return OTHER_CURSOR_FLAGS[i];
    }
    return '';
  }

  function standDown(flag) {
    if (warnedOther) return;
    warnedOther = true;
    warn('another cursor plugin is active (window.' + flag + '); standing down so only one cursor is drawn.');
  }

  function warn() {
    if (window.console && console.warn) {
      console.warn.apply(console, ['[BubbleCursor]'].concat([].slice.call(arguments)));
    }
  }

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  // Capability test, not touch presence: a touch-screen laptop driven by a
  // mouse must still get the cursor; phones and tablets (no hover-capable,
  // fine-pointer input) must not.
  function hasMouse() {
    var mm = window.matchMedia;
    if (mm && mm.call(window, '(any-hover: none), (any-hover: hover)').matches) {
      return mm.call(window, '(any-hover: hover) and (any-pointer: fine)').matches;
    }
    // Browsers without any-hover: fall back to the classic touch sniff.
    var touch = ('ontouchstart' in window) ||
      (navigator.maxTouchPoints > 0) ||
      (mm && mm.call(window, '(hover: none), (pointer: coarse)').matches);
    return !touch;
  }

  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function selectorOk(sel) {
    if (typeof sel !== 'string' || !sel) return false;
    try { document.createElement('i').matches(sel); return true; } catch (e) { return false; }
  }

  function parseHex(c) {
    var m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(c || '').trim());
    if (!m) return null;
    var h = m[1];
    if (h.length === 3) h = h.charAt(0) + h.charAt(0) + h.charAt(1) + h.charAt(1) + h.charAt(2) + h.charAt(2);
    return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)];
  }

  function loadSettings() {
    var g = window.BubbleCursorSettings;
    if (g && typeof g === 'object') return g;
    var el = document.getElementById('bubble-cursor-js') || document.querySelector('script[data-bc-settings]');
    var raw = el && el.getAttribute('data-bc-settings');
    if (raw) {
      try {
        var parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object') return parsed;
      } catch (e) { /* fall through to defaults */ }
    }
    warn('settings not found (window.BubbleCursorSettings / data-bc-settings); using built-in defaults.');
    return {};
  }

  ready(function () {
    try {
      init();
    } catch (err) {
      // Never let a cursor problem take the page down — fail silently.
      warn('init skipped:', err);
    }
  });

  function alreadyBooted() {
    return !!window.__bubbleCursorBooted || !!document.querySelector('.bc-dot, .bc-ring, .bc-fluid-canvas');
  }

  function init() {
    var body = document.body;
    if (!body) return;

    // Builder editors / previews: never draw over the editing surface.
    if (/\belementor-editor-active\b/.test(body.className) || EDITOR_QUERY.test(window.location.search)) return;

    if (alreadyBooted()) return;

    var other = otherCursor();
    if (other) { standDown(other); return; }

    var s = loadSettings();
    var num = function (v, d) { return (v === undefined || v === null || isNaN(v)) ? d : Number(v); };

    var settings = {
      enableFluid: s.enableFluid !== undefined ? !!s.enableFluid : true,
      enableRing: s.enableRing !== undefined ? !!s.enableRing : true,
      enableDot: s.enableDot !== undefined ? !!s.enableDot : true,
      hideNativeCursor: s.hideNativeCursor !== undefined ? !!s.hideNativeCursor : false,
      hideOnTouch: s.hideOnTouch !== undefined ? !!s.hideOnTouch : true,
      dotColor: s.dotColor || '#ffffff',
      ringColor: s.ringColor || '#ffffff',
      // hoverText is the on/off switch for any word; hoverLabel is the default word.
      // Older PHP sent the word itself as hoverText (false = off), still honoured.
      hoverText: !(s.hoverText === false || s.hoverText === 0 || s.hoverText === ''),
      hoverLabel: typeof s.hoverLabel === 'string' ? s.hoverLabel : (typeof s.hoverText === 'string' && s.hoverText ? s.hoverText : 'View'),
      hoverSelector: s.hoverSelector || DEFAULT_HOVER_SELECTOR,
      ringSize: num(s.ringSize, 40),
      dotSize: num(s.dotSize, 8),
      ringBorder: num(s.ringBorder, 1.5),
      cursorOpacity: num(s.cursorOpacity, 1),
      smokeOpacity: num(s.smokeOpacity, 1),
      smokeBlend: s.smokeBlend || '',
      mixBlend: s.mixBlend || '',
      fluid: s.fluid || {}
    };

    if (!selectorOk(settings.hoverSelector)) {
      warn('invalid hover selector "' + settings.hoverSelector + '"; using the default selector.');
      settings.hoverSelector = DEFAULT_HOVER_SELECTOR;
    }

    if (settings.hideOnTouch && !hasMouse()) {
      // Touch-only by media queries (phones, tablets). Do not bail for good:
      // stay dormant and start only if a real mouse shows up (a pointer event
      // with pointerType "mouse"; taps never produce one). Without Pointer
      // Events there is nothing reliable to wait for, so bail.
      if (!window.PointerEvent) return;
      // Chromium (and emulators) synthesise a mouse pointermove at a fixed
      // spot after layout even with no mouse attached, so one event is not
      // proof: require the mouse to actually travel a few pixels (or click).
      var first = null;
      var wake = function (e) {
        if (e.pointerType !== 'mouse') return;
        if (e.type === 'pointermove') {
          if (!first) { first = { x: e.clientX, y: e.clientY }; return; }
          if (Math.abs(e.clientX - first.x) + Math.abs(e.clientY - first.y) < 4) return;
        }
        window.removeEventListener('pointermove', wake, true);
        window.removeEventListener('pointerdown', wake, true);
        try { run(e); } catch (err) { warn('init skipped:', err); }
      };
      window.addEventListener('pointermove', wake, true);
      window.addEventListener('pointerdown', wake, true);
      return;
    }

    run(null);

    function run(firstEvent) {
      // Once-only: a second copy of the script (or another cursor) must not
      // create a second set of canvas/dot/ring.
      if (alreadyBooted()) return;
      // A sibling may have booted while we waited for a real mouse.
      var late = otherCursor();
      if (late) { standDown(late); return; }
      window.__bubbleCursorBooted = true;

      var root = document.documentElement;

      /* ---- Fluid (smoke) layer ------------------------------------- */
      var canvas = null;

      function mountFluid() {
        if (!(settings.enableFluid && !prefersReducedMotion() && window.BubbleCursorFluid)) return;
        unmountFluid();
        canvas = document.createElement('canvas');
        canvas.className = 'bc-fluid-canvas';
        canvas.setAttribute('aria-hidden', 'true');
        canvas.style.opacity = settings.smokeOpacity;
        if (settings.smokeBlend) canvas.style.mixBlendMode = settings.smokeBlend;
        document.body.appendChild(canvas);
        if (!window.BubbleCursorFluid.start(canvas, settings.fluid)) {
          // No WebGL: do not leave an empty full-viewport canvas behind.
          if (canvas.parentNode) canvas.parentNode.removeChild(canvas);
          canvas = null;
        }
      }

      function unmountFluid() {
        if (window.BubbleCursorFluid) window.BubbleCursorFluid.stop();
        if (canvas && canvas.parentNode) canvas.parentNode.removeChild(canvas);
        canvas = null;
      }

      /* ---- Dot + ring follower ------------------------------------- */
      var dot = null, ring = null, ringLabel = null;
      var followerOn = false;

      function wantFollower() {
        return (settings.enableDot || settings.enableRing) && !prefersReducedMotion();
      }

      function mountFollower() {
        if (followerOn) return;
        if (settings.enableDot) {
          dot = document.createElement('div');
          dot.className = 'bc-dot';
          dot.setAttribute('aria-hidden', 'true');
          dot.style.setProperty('--bc-dot-size', settings.dotSize + 'px');
          dot.style.setProperty('--bc-dot-color', settings.dotColor);
          dot.style.setProperty('--bc-opacity', settings.cursorOpacity);
          document.body.appendChild(dot);
        }
        if (settings.enableRing) {
          ring = document.createElement('div');
          ring.className = 'bc-ring';
          ring.setAttribute('aria-hidden', 'true');
          ring.style.setProperty('--bc-ring-size', settings.ringSize + 'px');
          ring.style.setProperty('--bc-ring-color', settings.ringColor);
          ring.style.setProperty('--bc-ring-border', settings.ringBorder + 'px');
          ring.style.setProperty('--bc-opacity', settings.cursorOpacity);
          var rgb = parseHex(settings.ringColor);
          if (rgb) {
            var lum = (0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]) / 255;
            ring.style.setProperty('--bc-hover-fill', 'rgba(' + rgb.join(',') + ',0.12)');
            ring.style.setProperty('--bc-text-fill', settings.ringColor);
            ring.style.setProperty('--bc-label-color', lum > 0.55 ? '#000' : '#fff');
          }
          if (settings.mixBlend) ring.style.mixBlendMode = settings.mixBlend;
          ringLabel = document.createElement('span');
          ringLabel.className = 'bc-ring__label';
          ring.appendChild(ringLabel);
          document.body.appendChild(ring);
        }
        followerOn = true;
      }

      /* ---- State + <html> markers ---------------------------------- */
      var visible = false;
      var hoverState = null; // null | 'hover' | 'text'
      var down = false;
      var stopped = false;
      var observer = null;

      function toggleClass(name, on) {
        if (root.classList.contains(name) !== on) root.classList.toggle(name, on);
      }

      // The html classes are convenience markers only. Re-assert them from JS
      // state; a host rewriting className can no longer break the cursor.
      function syncMarkers() {
        if (stopped) return;
        toggleClass('bc-active', true);
        toggleClass('bc-visible', visible);
        toggleClass('bc-hover', hoverState !== null);
        toggleClass('bc-hover-text', hoverState === 'text');
        toggleClass('bc-down', down);
        // Hide the native pointer only while something is actually drawn in
        // its place: a follower exists, motion is allowed and it has appeared.
        toggleClass('bc-hide-native', settings.hideNativeCursor && followerOn && visible);
      }

      function observeMarkers() {
        if (observer || !window.MutationObserver) return;
        observer = new MutationObserver(syncMarkers);
        observer.observe(root, { attributes: true, attributeFilter: ['class'] });
      }

      function unobserveMarkers() {
        if (observer) { observer.disconnect(); observer = null; }
      }

      function setVisible(v) {
        visible = v;
        [dot, ring].forEach(function (el) {
          if (!el) return;
          if (v) el.setAttribute('data-bc-visible', '1'); else el.removeAttribute('data-bc-visible');
        });
        syncMarkers();
      }

      function setHover(state, text) {
        hoverState = state;
        if (ring) {
          if (state) ring.setAttribute('data-bc-state', state); else ring.removeAttribute('data-bc-state');
          ringLabel.textContent = (state === 'text' && text) ? text : '';
        }
        // The dot only steps aside when a ring takes over the hover display.
        if (dot) {
          if (state && ring) dot.setAttribute('data-bc-hover', '1'); else dot.removeAttribute('data-bc-hover');
        }
        syncMarkers();
      }

      // Remove the follower and give the native cursor back (reduced motion on).
      function unmountFollower() {
        if (rafId) window.cancelAnimationFrame(rafId);
        rafId = null;
        [dot, ring].forEach(function (el) {
          if (el && el.parentNode) el.parentNode.removeChild(el);
        });
        dot = null; ring = null; ringLabel = null;
        followerOn = false;
        visible = false;
        hoverState = null;
        down = false;
        syncMarkers();
      }

      observeMarkers();

      /* ---- Movement (lerp follow) ---------------------------------- */
      var mouseX = window.innerWidth / 2, mouseY = window.innerHeight / 2;
      var ringX = mouseX, ringY = mouseY;
      var rafId = null;

      function placeRing() {
        ring.style.transform = 'translate3d(' + ringX + 'px,' + ringY + 'px,0) translate(-50%,-50%)';
      }

      function animate() {
        rafId = null;
        if (stopped || !ring) return;
        var dx = mouseX - ringX, dy = mouseY - ringY;
        if (Math.abs(dx) < 0.05 && Math.abs(dy) < 0.05) {
          // Converged: snap, write once, and park the loop until the next move.
          ringX = mouseX; ringY = mouseY;
          placeRing();
          return;
        }
        ringX += dx * 0.18;
        ringY += dy * 0.18;
        placeRing();
        rafId = window.requestAnimationFrame(animate);
      }

      function wakeRing() {
        if (ring && !rafId && !stopped) rafId = window.requestAnimationFrame(animate);
      }

      function onMove(e) {
        if (!followerOn) return;
        mouseX = e.clientX;
        mouseY = e.clientY;
        if (!visible) {
          // First appearance: start the ring on the pointer instead of easing in
          // from wherever it last was.
          ringX = mouseX; ringY = mouseY;
          if (ring) placeRing();
          setVisible(true);
        }
        if (dot) {
          dot.style.transform = 'translate3d(' + mouseX + 'px,' + mouseY + 'px,0) translate(-50%,-50%)';
        }
        wakeRing();
      }

      // Listeners are attached once and gated on followerOn, so a live
      // reduced-motion toggle never has to add or remove them.
      if (settings.enableDot || settings.enableRing) {
        window.addEventListener('mousemove', onMove, { passive: true });
        window.addEventListener('mouseout', function (e) {
          if (followerOn && !e.relatedTarget && !e.toElement) setVisible(false);
        });
        document.addEventListener('mousedown', function () { if (followerOn) { down = true; syncMarkers(); } });
        document.addEventListener('mouseup', function () { if (followerOn) { down = false; syncMarkers(); } });
      }

      /* ---- Hover state + hover word -------------------------------- */
      // The word for a hovered element: '' means no word (plain enlarged ring).
      // Order: opt-out attribute, the element's own data-bubble-cursor-text
      // (an empty one is an opt-out too), the Elementor setting, then the
      // default label for elements matching the hover selector.
      function hoverWord(el, isHover) {
        if (el.closest('[data-bubble-cursor-no-text]')) return '';
        var explicit = el.closest('[data-bubble-cursor-text]');
        if (explicit) return explicit.getAttribute('data-bubble-cursor-text') || '';
        var elementor = el.closest('[data-settings]');
        if (elementor) {
          try {
            var data = JSON.parse(elementor.getAttribute('data-settings'));
            if (data && data.wcf_enable_cursor_hover_effect_text) {
              return String(data.wcf_enable_cursor_hover_effect_text);
            }
          } catch (err) { /* ignore malformed JSON */ }
        }
        return isHover ? settings.hoverLabel : '';
      }

      if (settings.enableDot || settings.enableRing) {
        document.addEventListener('mouseover', function (e) {
          if (!followerOn) return;
          try {
            var target = e.target;
            if (!target || !target.closest) return;

            var isHover = !!target.closest(settings.hoverSelector);
            var word = settings.hoverText ? hoverWord(target, isHover) : '';

            if (word) {
              setHover('text', word);
            } else if (isHover) {
              setHover('hover');
            }
          } catch (err) { /* a hover glitch must never throw */ }
        });

        document.addEventListener('mouseout', function (e) {
          if (!followerOn) return;
          try {
            var target = e.target;
            if (!target || !target.closest) return;
            if (target.closest(settings.hoverSelector) || target.closest('[data-bubble-cursor-text]') || target.closest('[data-settings]')) {
              // Only clear if we're actually leaving the interactive element.
              var to = e.relatedTarget;
              if (to && to.closest && (to.closest(settings.hoverSelector) || to.closest('[data-bubble-cursor-text]'))) return;
              setHover(null);
            }
          } catch (err) { /* ignore */ }
        });
      }

      /* ---- Live reduced motion ------------------------------------- */
      function applyMotionPref() {
        if (stopped) return;
        if (prefersReducedMotion()) {
          unmountFollower();
          unmountFluid();
        } else {
          if (wantFollower()) mountFollower();
          if (!canvas) mountFluid();
          syncMarkers();
        }
      }

      var motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
      if (motionQuery) {
        if (motionQuery.addEventListener) motionQuery.addEventListener('change', applyMotionPref);
        else if (motionQuery.addListener) motionQuery.addListener(applyMotionPref);
      }

      if (wantFollower()) mountFollower();
      syncMarkers();
      mountFluid();
      if (firstEvent && followerOn) onMove(firstEvent);

      /* ---- Page lifecycle (unload + bfcache) ------------------------ */
      window.addEventListener('pagehide', function () {
        stopped = true;
        if (rafId) window.cancelAnimationFrame(rafId);
        rafId = null;
        unobserveMarkers();
        unmountFluid();
        toggleClass('bc-hide-native', false);
      });

      // Back/forward cache restore: the page comes back with JS state intact
      // but the GL context and loops are gone. Rebuild them.
      window.addEventListener('pageshow', function (e) {
        if (!e.persisted || !stopped) return;
        stopped = false;
        observeMarkers();
        applyMotionPref(); // rebuilds the smoke (and honours a reduced-motion change made while away)
        syncMarkers();
        wakeRing();
      });
    } // run()
  }
})(window, document);
