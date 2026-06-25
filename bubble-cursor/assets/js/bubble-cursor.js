/*!
 * Bubble Cursor — dot + ring follower with hover "View" bubble
 * Pairs with fluid-cursor.js (the smoke layer). Both are orchestrated here.
 *
 * Reads settings from window.BubbleCursorSettings (injected by the WordPress
 * plugin) or falls back to data-* attributes / sensible defaults so the file
 * also works standalone in the bundled demo.
 */
(function (window, document) {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function isTouch() {
    return ('ontouchstart' in window) ||
      (navigator.maxTouchPoints > 0) ||
      window.matchMedia('(hover: none), (pointer: coarse)').matches;
  }

  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function nowMs() {
    return (window.performance && window.performance.now) ? window.performance.now() : Date.now();
  }

  // el.closest() throws on an invalid selector — guard so a typo in a settings
  // field can never break the per-frame hit-test.
  function safeClosest(el, sel) {
    if (!sel || !el || !el.closest) return null;
    try { return el.closest(sel); } catch (e) { return null; }
  }

  ready(function () {
    try {
    var s = window.BubbleCursorSettings || {};

    var num = function (v, d) { return (v === undefined || v === null || isNaN(v)) ? d : Number(v); };

    var settings = {
      enableFluid: s.enableFluid !== undefined ? !!s.enableFluid : true,
      enableRing: s.enableRing !== undefined ? !!s.enableRing : true,
      enableDot: s.enableDot !== undefined ? !!s.enableDot : true,
      hideNativeCursor: s.hideNativeCursor !== undefined ? !!s.hideNativeCursor : false,
      hideOnTouch: s.hideOnTouch !== undefined ? !!s.hideOnTouch : true,
      dotColor: s.dotColor || '#ffffff',
      ringColor: s.ringColor || '#ffffff',
      hoverText: s.hoverText !== undefined ? s.hoverText : 'View',
      hoverEffect: s.hoverEffect !== undefined ? !!s.hoverEffect : true,
      hoverSelector: s.hoverSelector || 'a[href], button:not(:disabled), input[type="submit"], input[type="button"], .elementor-button, [data-bubble-cursor-hover]',
      hoverTextSelector: s.hoverTextSelector || '',
      textSelector: s.textSelector || '[data-bubble-cursor-text]',
      ringSize: num(s.ringSize, 40),
      dotSize: num(s.dotSize, 8),
      ringSpeed: Math.max(0.03, Math.min(0.9, num(s.ringSpeed, 0.2))),
      ringBorder: num(s.ringBorder, 1.5),
      cursorOpacity: num(s.cursorOpacity, 1),
      smokeOpacity: num(s.smokeOpacity, 1),
      smokeBlend: s.smokeBlend || '',
      autoContrast: s.autoContrast !== undefined ? !!s.autoContrast : false,
      mixBlend: s.mixBlend || '',
      fluid: s.fluid || {}
    };

    if (settings.hideOnTouch && isTouch()) {
      return; // bail entirely on touch devices
    }

    var reduced = prefersReducedMotion();
    var root = document.documentElement;
    root.classList.add('bc-active');
    root.style.setProperty('--bc-opacity', settings.cursorOpacity);
    if (settings.hideNativeCursor) root.classList.add('bc-hide-native');

    // Auto-contrast: blend the dot/ring against the page so they invert to stay
    // visible on any background (white on dark, dark on light). Forcing white
    // gives a clean inversion under the "difference" blend mode.
    if (settings.autoContrast) {
      root.classList.add('bc-auto-contrast');
      settings.dotColor = '#ffffff';
      settings.ringColor = '#ffffff';
    }

    /* ---- Fluid (smoke) layer ------------------------------------- */
    if (settings.enableFluid && !reduced && window.BubbleCursorFluid) {
      var canvas = document.createElement('canvas');
      canvas.className = 'bc-fluid-canvas';
      canvas.setAttribute('aria-hidden', 'true');
      canvas.style.opacity = settings.smokeOpacity;
      if (settings.smokeBlend) canvas.style.mixBlendMode = settings.smokeBlend;
      document.body.appendChild(canvas);
      window.BubbleCursorFluid.start(canvas, settings.fluid);
    }

    /* ---- Dot + ring follower ------------------------------------- */
    var dot = null, ring = null, ringLabel = null;
    var hasFollower = (settings.enableDot || settings.enableRing) && !reduced;

    if (hasFollower) {
      if (settings.enableDot) {
        dot = document.createElement('div');
        dot.className = 'bc-dot';
        dot.setAttribute('aria-hidden', 'true');
        dot.style.setProperty('--bc-dot-size', settings.dotSize + 'px');
        dot.style.setProperty('--bc-dot-color', settings.dotColor);
        document.body.appendChild(dot);
      }
      if (settings.enableRing) {
        ring = document.createElement('div');
        ring.className = 'bc-ring';
        ring.setAttribute('aria-hidden', 'true');
        ring.style.setProperty('--bc-ring-size', settings.ringSize + 'px');
        ring.style.setProperty('--bc-ring-color', settings.ringColor);
        ring.style.setProperty('--bc-ring-border', settings.ringBorder + 'px');
        if (settings.mixBlend) ring.style.mixBlendMode = settings.mixBlend;
        ringLabel = document.createElement('span');
        ringLabel.className = 'bc-ring__label';
        ring.appendChild(ringLabel);
        document.body.appendChild(ring);
      }
    }

    /* ---- Movement + hover (frame-based, stable) ------------------ */
    var mouseX = window.innerWidth / 2, mouseY = window.innerHeight / 2;
    var ringX = mouseX, ringY = mouseY;
    var visible = false;
    var rafId = null;

    // Hover state is changed ONLY when it actually differs — no per-element
    // event thrash, so the ring transitions once and never throbs.
    var hoverState = '';        // '' | 'hover' | 'text'
    var hoverLabel = '';
    var lastHitX = null, lastHitY = null;

    function onMove(e) {
      mouseX = e.clientX;
      mouseY = e.clientY;
      if (!visible) {
        visible = true;
        root.classList.add('bc-visible');
      }
      if (dot) {
        dot.style.transform = 'translate3d(' + mouseX + 'px,' + mouseY + 'px,0) translate(-50%,-50%)';
      }
    }

    // Resolve the hover label for an element (explicit attribute or Elementor setting).
    function resolveHoverText(el) {
      var explicit = safeClosest(el, '[data-bubble-cursor-text]');
      if (explicit) return explicit.getAttribute('data-bubble-cursor-text');
      // Elements matching the user's "hover text selector" show the global word.
      if (settings.hoverTextSelector && settings.hoverText && safeClosest(el, settings.hoverTextSelector)) {
        return settings.hoverText;
      }
      var elementor = safeClosest(el, '[data-settings]');
      if (elementor) {
        try {
          var data = JSON.parse(elementor.getAttribute('data-settings'));
          if (data && data.wcf_enable_cursor_hover_effect_text) {
            return data.wcf_enable_cursor_hover_effect_text;
          }
        } catch (err) { /* ignore malformed JSON */ }
      }
      return null;
    }

    // Hit-test under the pointer once per frame; flip classes only on a real change.
    function updateHover() {
      if (!settings.hoverEffect) return;
      if (mouseX === lastHitX && mouseY === lastHitY) return; // pointer hasn't moved
      lastHitX = mouseX; lastHitY = mouseY;

      var desired = '', label = '';
      var el = document.elementFromPoint(mouseX, mouseY);
      if (el && el.closest) {
        var text = resolveHoverText(el);
        if (text && settings.hoverText !== false) { desired = 'text'; label = text; }
        else if (safeClosest(el, settings.hoverSelector)) { desired = 'hover'; }
      }
      if (desired === hoverState && label === hoverLabel) return; // no change → no DOM write

      hoverState = desired;
      hoverLabel = label;
      root.classList.toggle('bc-hover', desired !== '');
      root.classList.toggle('bc-hover-text', desired === 'text');
      if (ringLabel) ringLabel.textContent = (desired === 'text') ? label : '';
    }

    var lastFrameT = nowMs();
    function animate() {
      var t = nowMs();
      var dt = t - lastFrameT;
      lastFrameT = t;
      if (dt > 100) dt = 100; // avoid a big jump after a background tab / stall
      // Frame-rate-independent easing: the ring catches up at a consistent rate
      // whether the page runs at 30, 60 or 144 fps (the smoke sim varies it),
      // so the motion stays smooth instead of stepping unevenly.
      var f = 1 - Math.pow(1 - settings.ringSpeed, dt / 16.667);
      ringX += (mouseX - ringX) * f;
      ringY += (mouseY - ringY) * f;
      if (ring) {
        ring.style.transform = 'translate3d(' + ringX + 'px,' + ringY + 'px,0) translate(-50%,-50%)';
      }
      updateHover();
      rafId = window.requestAnimationFrame(animate);
    }

    if (hasFollower) {
      window.addEventListener('mousemove', onMove, { passive: true });
      window.addEventListener('mouseout', function (e) {
        if (!e.relatedTarget && !e.toElement) {
          visible = false;
          root.classList.remove('bc-visible');
        }
      });
      // Content can slide under a still pointer (smooth-scroll themes) — re-hit-test.
      window.addEventListener('scroll', function () { lastHitX = null; }, { passive: true });
      animate();
    }

    /* ---- Cleanup on unload --------------------------------------- */
    window.addEventListener('pagehide', function () {
      if (rafId) window.cancelAnimationFrame(rafId);
      if (window.BubbleCursorFluid) window.BubbleCursorFluid.stop();
    });
    } catch (err) {
      // Never let a cursor problem take the page down — fail silently.
      if (window.console && console.warn) { console.warn('[BubbleCursor] init skipped:', err); }
    }
  });
})(window, document);
