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
      magnetic: s.magnetic !== undefined ? !!s.magnetic : false,
      clickBurst: s.clickBurst !== undefined ? !!s.clickBurst : false,
      elastic: s.elastic !== undefined ? !!s.elastic : false,
      imagePreview: s.imagePreview !== undefined ? !!s.imagePreview : false,
      previewSelector: s.previewSelector || '',
      previewSize: Math.max(60, Math.min(420, num(s.previewSize, 180))),
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
    if (settings.magnetic) root.classList.add('bc-magnetic');

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

    /* ---- Image preview ------------------------------------------- */
    var preview = null, previewSrc = '';
    if (settings.imagePreview && settings.previewSelector && !reduced) {
      preview = document.createElement('div');
      preview.className = 'bc-preview';
      preview.setAttribute('aria-hidden', 'true');
      preview.style.width = settings.previewSize + 'px';
      preview.style.height = settings.previewSize + 'px';
      document.body.appendChild(preview);
    }

    /* ---- Movement + hover (frame-based, stable) ------------------ */
    var mouseX = window.innerWidth / 2, mouseY = window.innerHeight / 2;
    var ringX = mouseX, ringY = mouseY;
    var previewX = mouseX, previewY = mouseY;
    var visible = false;
    var rafId = null;

    // Hover state is changed ONLY when it actually differs — no per-element
    // event thrash, so the ring transitions once and never throbs.
    var hoverState = '';        // '' | 'hover' | 'text'
    var hoverLabel = '';
    var lastHitX = null, lastHitY = null;
    var magnetEl = null;        // element the ring is magnetised to (when enabled)

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

    // Morph the ring to hug the magnetised element (or revert when null).
    function applyMagnetMorph(elm) {
      if (!ring) return;
      if (elm) {
        var r = elm.getBoundingClientRect();
        var w = Math.min(Math.round(r.width) + 14, 260);
        var h = Math.min(Math.round(r.height) + 14, 260);
        ring.style.width = w + 'px';
        ring.style.height = h + 'px';
        var br = parseFloat(window.getComputedStyle(elm).borderRadius) || 8;
        ring.style.borderRadius = Math.min(br + 6, Math.min(w, h) / 2) + 'px';
      } else {
        ring.style.width = '';
        ring.style.height = '';
        ring.style.borderRadius = '';
      }
    }

    // Resolve the image URL to preview for an element (data attr, <img>, or bg).
    function resolvePreviewImage(el) {
      var m = safeClosest(el, settings.previewSelector);
      if (!m) return null;
      var explicit = m.getAttribute('data-bubble-cursor-image');
      if (explicit) return explicit;
      var img = m.querySelector('img');
      if (img && (img.currentSrc || img.src)) return img.currentSrc || img.src;
      var bg = window.getComputedStyle(m).backgroundImage;
      if (bg && bg !== 'none') {
        var um = bg.match(/url\(["']?(.*?)["']?\)/);
        if (um && um[1]) return um[1];
      }
      return null;
    }

    // Flip hover classes + magnet target for the element under the pointer.
    // (Called once per frame from animate(), only when the pointer has moved.)
    function updateHover(el) {
      var desired = '', label = '', mEl = null;
      if (el && el.closest) {
        var text = resolveHoverText(el);
        if (text && settings.hoverText !== false) { desired = 'text'; label = text; }
        else if (safeClosest(el, settings.hoverSelector)) { desired = 'hover'; }
        if (settings.magnetic) mEl = safeClosest(el, settings.hoverSelector);
      }
      // Magnetic runs even when the grow effect is off; morph only on a change.
      if (settings.magnetic && mEl !== magnetEl) { magnetEl = mEl; applyMagnetMorph(mEl); }

      if (!settings.hoverEffect) return;
      if (desired === hoverState && label === hoverLabel) return; // no change → no DOM write
      hoverState = desired;
      hoverLabel = label;
      root.classList.toggle('bc-hover', desired !== '');
      root.classList.toggle('bc-hover-text', desired === 'text');
      if (ringLabel) ringLabel.textContent = (desired === 'text') ? label : '';
    }

    // Show/hide the image preview for the element under the pointer.
    function updatePreview(el) {
      var src = el ? resolvePreviewImage(el) : null;
      if (src === previewSrc) return;
      previewSrc = src;
      if (src) {
        preview.style.backgroundImage = 'url("' + String(src).replace(/"/g, '\\"') + '")';
        root.classList.add('bc-preview-on');
      } else {
        root.classList.remove('bc-preview-on');
      }
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

      // One hit-test per frame (only when the pointer moved) feeds hover, magnet
      // and preview — cheap, and avoids the old mouseover/mouseout thrash.
      if (mouseX !== lastHitX || mouseY !== lastHitY) {
        lastHitX = mouseX; lastHitY = mouseY;
        var hitEl = (settings.hoverEffect || settings.magnetic || preview)
          ? document.elementFromPoint(mouseX, mouseY) : null;
        if (settings.hoverEffect || settings.magnetic) updateHover(hitEl);
        if (preview) updatePreview(hitEl);
      }

      // Magnetic: aim at the hovered element's centre so the ring snaps onto it.
      var tx = mouseX, ty = mouseY;
      if (settings.magnetic && magnetEl) {
        var mr = magnetEl.getBoundingClientRect();
        if (mr.width && mr.height) { tx = mr.left + mr.width / 2; ty = mr.top + mr.height / 2; }
      }

      var gapX = tx - ringX, gapY = ty - ringY;
      ringX += gapX * f;
      ringY += gapY * f;

      if (ring) {
        var tf = 'translate3d(' + ringX + 'px,' + ringY + 'px,0) translate(-50%,-50%)';
        // Elastic squash/stretch along the direction of travel (idle ring only).
        if (settings.elastic && hoverState === '' && !magnetEl) {
          var speed = Math.sqrt(gapX * gapX + gapY * gapY);
          var stretch = Math.min(speed * 0.012, 0.5);
          if (stretch > 0.01) {
            var ang = Math.atan2(gapY, gapX);
            tf += ' rotate(' + ang + 'rad) scale(' + (1 + stretch).toFixed(3) + ',' + (1 - stretch * 0.4).toFixed(3) + ')';
          }
        }
        ring.style.transform = tf;
      }

      if (preview) {
        previewX += (mouseX - previewX) * f;
        previewY += (mouseY - previewY) * f;
        preview.style.transform = 'translate3d(' + previewX + 'px,' + previewY + 'px,0) translate(-50%,-50%)';
      }

      rafId = window.requestAnimationFrame(animate);
    }

    var runLoop = hasFollower || !!preview;
    if (runLoop) {
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

    /* ---- Pause the smoke when the tab is hidden (saves CPU/battery) -- */
    document.addEventListener('visibilitychange', function () {
      if (!window.BubbleCursorFluid) return;
      if (document.hidden) { window.BubbleCursorFluid.pause(); }
      else { window.BubbleCursorFluid.resume(); }
    });

    /* ---- Click burst -------------------------------------------- */
    if (settings.clickBurst) {
      document.addEventListener('mousedown', function (e) {
        // Smoke puff into the fluid (if it is running).
        if (window.BubbleCursorFluid && window.BubbleCursorFluid.isRunning()) {
          window.BubbleCursorFluid.burst(e.clientX, e.clientY);
        }
        // Expanding ring ripple at the click point.
        var rip = document.createElement('div');
        rip.className = 'bc-ripple';
        rip.setAttribute('aria-hidden', 'true');
        rip.style.left = e.clientX + 'px';
        rip.style.top = e.clientY + 'px';
        rip.style.borderColor = settings.ringColor;
        document.body.appendChild(rip);
        var remove = function () { if (rip.parentNode) rip.parentNode.removeChild(rip); };
        rip.addEventListener('animationend', remove);
        window.setTimeout(remove, 900); // fallback if animationend doesn't fire
      }, { passive: true });
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
