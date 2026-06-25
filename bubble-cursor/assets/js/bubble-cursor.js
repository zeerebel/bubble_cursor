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
      hoverSelector: s.hoverSelector || 'a[href], button:not(:disabled), input[type="submit"], input[type="button"], .elementor-button, [data-bubble-cursor-hover]',
      textSelector: s.textSelector || '[data-bubble-cursor-text]',
      ringSize: num(s.ringSize, 40),
      dotSize: num(s.dotSize, 8),
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

    /* ---- Movement (lerp follow) ---------------------------------- */
    var mouseX = window.innerWidth / 2, mouseY = window.innerHeight / 2;
    var ringX = mouseX, ringY = mouseY;
    var visible = false;
    var rafId = null;

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

    function animate() {
      ringX += (mouseX - ringX) * 0.18;
      ringY += (mouseY - ringY) * 0.18;
      if (ring) {
        ring.style.transform = 'translate3d(' + ringX + 'px,' + ringY + 'px,0) translate(-50%,-50%)';
      }
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
      document.addEventListener('mousedown', function () { root.classList.add('bc-down'); });
      document.addEventListener('mouseup', function () { root.classList.remove('bc-down'); });
      if (ring) animate();
    }

    /* ---- Hover state + "View" text ------------------------------- */
    function resolveHoverText(el) {
      // Explicit per-element text wins (data attribute or Elementor container setting).
      var explicit = el.closest('[data-bubble-cursor-text]');
      if (explicit) return explicit.getAttribute('data-bubble-cursor-text');
      var elementor = el.closest('[data-settings]');
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

    if (hasFollower && (ring || dot)) {
      document.addEventListener('mouseover', function (e) {
        var target = e.target;
        if (!target || !target.closest) return;

        var text = resolveHoverText(target);
        var isHover = !!target.closest(settings.hoverSelector);

        if (text && settings.hoverText !== false) {
          root.classList.add('bc-hover', 'bc-hover-text');
          if (ringLabel) ringLabel.textContent = text;
        } else if (isHover) {
          root.classList.add('bc-hover');
          root.classList.remove('bc-hover-text');
          if (ringLabel) ringLabel.textContent = '';
        }
      });

      document.addEventListener('mouseout', function (e) {
        var target = e.target;
        if (!target || !target.closest) return;
        if (target.closest(settings.hoverSelector) || target.closest('[data-bubble-cursor-text]') || target.closest('[data-settings]')) {
          // Only clear if we're actually leaving the interactive element.
          var to = e.relatedTarget;
          if (to && to.closest && (to.closest(settings.hoverSelector) || to.closest('[data-bubble-cursor-text]'))) return;
          root.classList.remove('bc-hover', 'bc-hover-text');
          if (ringLabel) ringLabel.textContent = '';
        }
      });
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
