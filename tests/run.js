/* Bubble Cursor browser tests: Chromium only, via Playwright for Node.
 * Run with ./run.sh (or `node tests/run.js` if `playwright` already resolves).
 * Serves the real plugin css/js unmodified from ../bubble-cursor/assets and
 * builds fixture pages on the fly. Not a real WordPress render. */
'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium, devices } = require('playwright');

const PORT = Number(process.env.BC_TEST_PORT || 9190);
const ASSETS = path.resolve(__dirname, '../bubble-cursor/assets');
const MIME = { '.js': 'text/javascript', '.css': 'text/css', '.html': 'text/html' };

/* ---------------- fixture page ---------------- */
const DEFAULT_SETTINGS = {
  enableFluid: true, enableRing: true, enableDot: true, hideNativeCursor: false,
  hideOnTouch: true, dotColor: '#ffffff', ringColor: '#ffffff', hoverText: true, hoverLabel: '',
  hoverSelector: 'a[href], button:not(:disabled), input[type="submit"], input[type="button"], .elementor-button, [data-bubble-cursor-hover]',
  dotSize: 8, ringSize: 40, ringBorder: 1.5, cursorOpacity: 1, smokeOpacity: 1, smokeBlend: '',
  fluid: { DENSITY_DISSIPATION: 0.98, VELOCITY_DISSIPATION: 0.98 }
};

const HOSTILE_CSS = `
  *,*::before,*::after{box-sizing:border-box}
  button{all:unset} body[data-form-style] button{all:unset;display:inline-block;padding:10px}
  img,canvas,video{max-width:100%;height:auto}
  body div{position:relative}
  #header-outer{position:fixed;top:0;left:0;right:0;height:60px;z-index:9998;background:#222}
  a:hover{opacity:.7} html{scroll-behavior:smooth}`;

function page(o) {
  const settings = Object.assign({}, DEFAULT_SETTINGS, o.settings || {});
  Object.keys(settings).forEach((k) => { if (settings[k] === null) delete settings[k]; }); // null = key absent
  const json = JSON.stringify(settings);
  const attr = json.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
  const fluid = '<script src="/js/fluid-cursor.js"></script>';
  const main = '<script id="bubble-cursor-js" ' + (o.noAttr ? '' : 'data-bc-settings="' + attr + '" ') + 'src="/js/bubble-cursor.js"></script>';
  return `<!doctype html><html${o.rtl ? ' dir="rtl"' : ''} class="no-js"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{margin:0;background:#0b0b10;color:#ddd;font:16px sans-serif}${o.hostile === false ? '' : HOSTILE_CSS}
.tall{height:3000px}.hero{height:700px}a.lnk,button.btn{position:absolute;left:300px;top:200px;width:160px;height:50px;display:block}
.card{position:absolute;left:600px;top:200px;width:200px;height:120px;background:#333}</style>
<link rel="stylesheet" href="/css/bubble-cursor.css"></head>
<body${o.bodyClass ? ' class="' + o.bodyClass + '"' : ''} data-form-style="default">
<div id="header-outer"></div>
<div class="hero"><a class="lnk" href="#x" style="background:#444">Link</a>
<button class="btn" style="background:#444">Button</button>
<div class="card" data-bubble-cursor-text="Open">Card</div>
<a id="nolabel" href="#n" data-bubble-cursor-no-text style="position:absolute;left:1000px;top:200px;width:160px;height:50px;background:#444;display:block">No label</a>
<a id="emptytxt" href="#e" data-bubble-cursor-text="" style="position:absolute;left:1000px;top:300px;width:160px;height:50px;background:#444;display:block">Empty text</a></div><div class="tall"></div>
<a id="far" href="#y" style="position:absolute;left:300px;top:1800px;width:160px;height:50px;background:#444;display:block">Far link</a>
${o.rewrite === 'load' ? '<script>window.addEventListener("load",function(){setTimeout(function(){document.documentElement.className="theme-js loaded"},50)})</script>' : ''}
${o.rewrite === 'dcl' ? '<script>document.addEventListener("DOMContentLoaded",function(){document.documentElement.className="theme-js"})</script>' : ''}
${o.pre ? '<script>' + o.pre + '</script>' : ''}
${o.noGlobal ? '' : '<script>window.BubbleCursorSettings=' + json + ';</script>'}
${fluid}${main}${o.double ? fluid + main : ''}
</body></html>`;
}

function startServer() {
  return new Promise((resolve) => {
    const srv = http.createServer((req, res) => {
      const u = new URL(req.url, 'http://x');
      if (u.pathname === '/page') {
        const o = JSON.parse(u.searchParams.get('o') || '{}');
        res.writeHead(200, { 'content-type': 'text/html' });
        return res.end(page(o));
      }
      const m = u.pathname.match(/^\/(js|css)\/([\w.-]+)$/);
      if (m) {
        const f = path.join(ASSETS, m[1], m[2]);
        if (fs.existsSync(f)) { res.writeHead(200, { 'content-type': MIME[path.extname(f)] }); return res.end(fs.readFileSync(f)); }
      }
      res.writeHead(404); res.end('nf');
    });
    srv.listen(PORT, '127.0.0.1', () => resolve(srv));
  });
}

/* ---------------- harness ---------------- */
let passed = 0, failed = 0;
const failures = [];
function check(name, cond, detail) {
  if (cond) { passed++; console.log('  ok   ' + name); }
  else { failed++; failures.push(name); console.log('  FAIL ' + name + (detail !== undefined ? '  -> ' + JSON.stringify(detail) : '')); }
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const approx = (a, b, t) => Math.abs(a - b) <= (t === undefined ? 2 : t);

async function open(browser, o, ctxOpts) {
  const ctx = await browser.newContext(ctxOpts || { viewport: { width: 1440, height: 800 } });
  const p = await ctx.newPage();
  p.errs = [];
  p.warns = [];
  p.on('pageerror', (e) => p.errs.push('pageerror: ' + e.message));
  p.on('console', (m) => {
    if (m.type() === 'error') p.errs.push('console.error: ' + m.text());
    if (m.type() === 'warning' && /\[BubbleCursor\]/.test(m.text())) p.warns.push(m.text());
  });
  await p.addInitScript(() => {
    window.__draws = 0;
    window.__raf = 0;
    (function () { const r = window.requestAnimationFrame; window.requestAnimationFrame = function (cb) { window.__raf++; return r.call(window, cb); }; })();
    ['WebGLRenderingContext', 'WebGL2RenderingContext'].forEach((n) => {
      const C = window[n];
      if (!C) return;
      const orig = C.prototype.drawElements;
      C.prototype.drawElements = function () { window.__draws++; return orig.apply(this, arguments); };
    });
  });
  await p.goto('http://127.0.0.1:' + PORT + '/page?o=' + encodeURIComponent(JSON.stringify(o || {})));
  await p.waitForLoadState('load');
  await sleep(150);
  p.__ctx = ctx;
  return p;
}
const counts = (p) => p.evaluate(() => ({
  dot: document.querySelectorAll('.bc-dot').length,
  ring: document.querySelectorAll('.bc-ring').length,
  canvas: document.querySelectorAll('.bc-fluid-canvas').length
}));
const op = (p, sel) => p.evaluate((s) => { const e = document.querySelector(s); return e ? parseFloat(getComputedStyle(e).opacity) : null; }, sel);
const box = (p, sel) => p.evaluate((s) => { const e = document.querySelector(s); if (!e) return null; const r = e.getBoundingClientRect(); return { w: r.width, h: r.height, cx: r.left + r.width / 2, cy: r.top + r.height / 2 }; }, sel);
async function moveAround(p, x, y) {
  for (let i = 0; i < 6; i++) { await p.mouse.move(x + i * 3, y + i * 2); await sleep(20); }
}
/* wait until the ring centre is within 1px of (x,y); software GL makes rAF slow */
async function ringAt(p, x, y, ms) {
  const end = Date.now() + (ms || 6000);
  let r;
  while (Date.now() < end) { r = await box(p, '.bc-ring'); if (r && approx(r.cx, x, 1) && approx(r.cy, y, 1)) return r; await sleep(100); }
  return r;
}
const info = (p) => p.evaluate(() => ({ run: window.BubbleCursorFluid.isRunning(), sleep: window.BubbleCursorFluid.isSleeping() }));

/* pixel diff between two PNG buffers using a canvas in a scratch page */
async function diffPixels(browser, a, b, skip) {
  const p = await browser.newPage();
  const r = await p.evaluate(async ([a64, b64, skip]) => {
    const load = (s) => new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.src = 'data:image/png;base64,' + s; });
    const [ia, ib] = await Promise.all([load(a64), load(b64)]);
    const w = ia.width, h = ia.height;
    const get = (im) => { const c = document.createElement('canvas'); c.width = w; c.height = h; const x = c.getContext('2d'); x.drawImage(im, 0, 0); return x.getImageData(0, 0, w, h).data; };
    const da = get(ia), db = get(ib);
    let n = 0;
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      if (skip && x > skip.x - 120 && x < skip.x + 120 && y > skip.y - 120 && y < skip.y + 120) continue;
      const k = (y * w + x) * 4;
      if (Math.abs(da[k] - db[k]) + Math.abs(da[k + 1] - db[k + 1]) + Math.abs(da[k + 2] - db[k + 2]) > 24) n++;
    }
    return n;
  }, [a.toString('base64'), b.toString('base64'), skip]);
  await p.close();
  return r;
}

/* ---------------- tests ---------------- */
async function main() {
  const srv = await startServer();
  const browser = await chromium.launch();
  const desktop = { viewport: { width: 1440, height: 800 } };

  console.log('\n[1] baseline, visibility, hover and text states (desktop 1440x800, hostile host CSS)');
  {
    const p = await open(browser, {});
    check('dot/ring/canvas created once', JSON.stringify(await counts(p)) === '{"dot":1,"ring":1,"canvas":1}', await counts(p));
    check('hidden before first move', (await op(p, '.bc-dot')) === 0 && (await op(p, '.bc-ring')) === 0);
    await moveAround(p, 300, 300); await sleep(500);
    check('dot visible after mouse move', (await op(p, '.bc-dot')) === 1, await op(p, '.bc-dot'));
    check('ring visible after mouse move', (await op(p, '.bc-ring')) === 1);
    const d = await box(p, '.bc-dot'), r = await ringAt(p, 315, 310);
    check('dot tracks pointer', approx(d.cx, 315, 3) && approx(d.cy, 310, 3), d);
    check('ring eases onto pointer', approx(r.cx, 315, 1) && approx(r.cy, 310, 1), r);
    check('ring is 40px even with host * {box-sizing:border-box}', approx(r.w, 40, 0.5) && approx(r.h, 40, 0.5), r);
    await moveAround(p, 340, 220); await sleep(500);
    const rh = await box(p, '.bc-ring');
    check('hover over link: ring grows to 1.6x (64px)', approx(rh.w, 64, 1), rh);
    check('hover over link: dot hidden', (await op(p, '.bc-dot')) === 0);
    check('hover state is on the ring element', (await p.getAttribute('.bc-ring', 'data-bc-state')) === 'hover');
    await moveAround(p, 650, 250); await sleep(500);
    const rt = await box(p, '.bc-ring');
    check('text hover: "Open" label in ring', (await p.textContent('.bc-ring__label')) === 'Open');
    check('text hover: ring 2.25x (90px)', approx(rt.w, 90, 1), rt);
    check('text hover: label visible', (await op(p, '.bc-ring__label')) === 1);
    await moveAround(p, 1000, 500); await sleep(500);
    check('leaving element clears hover', (await box(p, '.bc-ring')).w < 41 && !(await p.getAttribute('.bc-ring', 'data-bc-state')));
    check('cursor sits above host #header-outer (z-index)', await p.evaluate(() => parseInt(getComputedStyle(document.querySelector('.bc-ring')).zIndex, 10) > 9998));
    const cv = await p.evaluate(() => { const c = document.querySelector('.bc-fluid-canvas'); const r = c.getBoundingClientRect(); return { w: r.width, h: r.height, iw: innerWidth, ih: innerHeight }; });
    check('canvas covers viewport (width/height 100%, not 100vw)', cv.w === cv.iw && cv.h === cv.ih, cv);
    check('no console errors / pageerrors', p.errs.length === 0, p.errs);
    check('no fallback warning when settings present', p.warns.length === 0, p.warns);
    await p.__ctx.close();
  }

  console.log('\n[2] finding 1: html className rewrite must not hide the cursor');
  for (const mode of ['load', 'dcl']) {
    const p = await open(browser, { rewrite: mode });
    await sleep(200);
    await moveAround(p, 300, 300); await sleep(400);
    check('rewrite@' + mode + ': dot visible', (await op(p, '.bc-dot')) === 1);
    check('rewrite@' + mode + ': ring visible', (await op(p, '.bc-ring')) === 1);
    await moveAround(p, 340, 220); await sleep(500);
    check('rewrite@' + mode + ': hover still works', (await box(p, '.bc-ring')).w > 60);
    check('rewrite@' + mode + ': no errors', p.errs.length === 0, p.errs);
    await p.__ctx.close();
  }
  {
    const p = await open(browser, { settings: { hideNativeCursor: true } });
    await moveAround(p, 300, 300); await sleep(300);
    await p.evaluate(() => { document.documentElement.className = ''; });
    await sleep(100);
    check('mid-session wipe: dot still visible', (await op(p, '.bc-dot')) === 1);
    check('mid-session wipe: markers re-asserted by MutationObserver', await p.evaluate(() => ['bc-active', 'bc-visible', 'bc-hide-native'].every((c) => document.documentElement.classList.contains(c))));
    await moveAround(p, 340, 220); await sleep(300);
    await p.evaluate(() => { document.documentElement.className = 'x'; });
    await sleep(100);
    check('mid-session wipe while hovering: hover state kept', (await p.getAttribute('.bc-ring', 'data-bc-state')) === 'hover' && await p.evaluate(() => document.documentElement.classList.contains('bc-hover')));
    await p.__ctx.close();
  }

  console.log('\n[3] finding 2: settings channel');
  {
    const p = await open(browser, { noGlobal: true, settings: { dotSize: 20, ringColor: '#ff0000' } });
    await moveAround(p, 300, 300); await sleep(300);
    const dw = (await box(p, '.bc-dot')).w;
    check('inline settings stripped: data-bc-settings still applies (dot 20px)', approx(dw, 20, 0.5), dw);
    check('ring colour from data attribute', await p.evaluate(() => getComputedStyle(document.querySelector('.bc-ring')).borderTopColor) === 'rgb(255, 0, 0)');
    check('no fallback warning', p.warns.length === 0, p.warns);
    await p.__ctx.close();
    const q = await open(browser, { noGlobal: true, noAttr: true });
    await moveAround(q, 300, 300); await sleep(300);
    check('neither channel: still renders with defaults', (await op(q, '.bc-dot')) === 1 && approx((await box(q, '.bc-dot')).w, 8, 0.5));
    check('neither channel: exactly one console.warn', q.warns.length === 1, q.warns);
    check('neither channel: no errors', q.errs.length === 0, q.errs);
    await q.__ctx.close();
  }

  console.log('\n[4] finding 3: hide_native');
  {
    const p = await open(browser, { settings: { hideNativeCursor: true } });
    await moveAround(p, 300, 300); await sleep(300);
    const after = await p.evaluate(() => getComputedStyle(document.body).cursor);
    check('native cursor hidden once dot/ring visible', after === 'none', after);
    // (Chromium may synthesise a mouse move at load, so "before first move" is
    // tested by the equivalent state: pointer leaves the window, dot/ring hide.)
    await p.evaluate(() => window.dispatchEvent(new MouseEvent('mouseout', { relatedTarget: null })));
    await sleep(100);
    const gone = await p.evaluate(() => ({ c: getComputedStyle(document.body).cursor, op: getComputedStyle(document.querySelector('.bc-dot')).opacity, cls: document.documentElement.classList.contains('bc-hide-native') }));
    check('native cursor returns whenever the custom one is not shown', gone.c !== 'none' && !gone.cls, gone);
    await p.__ctx.close();
    const r = await open(browser, { settings: { hideNativeCursor: true } }, { viewport: { width: 1440, height: 800 }, reducedMotion: 'reduce' });
    await moveAround(r, 300, 300); await sleep(300);
    check('reduced motion + hide_native: native cursor stays visible', (await r.evaluate(() => getComputedStyle(document.body).cursor)) !== 'none');
    check('reduced motion: nothing drawn', JSON.stringify(await counts(r)) === '{"dot":0,"ring":0,"canvas":0}');
    check('reduced motion: no bc-hide-native marker', !(await r.evaluate(() => document.documentElement.classList.contains('bc-hide-native'))));
    await r.__ctx.close();
    const f = await open(browser, { settings: { hideNativeCursor: true, enableDot: false, enableRing: false } });
    await moveAround(f, 300, 300); await sleep(300);
    check('followers disabled + hide_native: native cursor stays visible', (await f.evaluate(() => getComputedStyle(document.body).cursor)) !== 'none');
    await f.__ctx.close();
  }

  console.log('\n[5] finding 4: bfcache pagehide/pageshow (synthetic events, persisted:true)');
  {
    const p = await open(browser, {});
    await moveAround(p, 300, 300); await sleep(300);
    check('running before pagehide', (await info(p)).run);
    await p.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })));
    await sleep(100);
    check('pagehide stops the engine and removes the canvas', !(await info(p)).run && (await counts(p)).canvas === 0);
    await p.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    await sleep(300);
    check('persisted pageshow restarts the fluid sim on a fresh canvas', (await info(p)).run && (await counts(p)).canvas === 1, await counts(p));
    const d0 = await p.evaluate(() => window.__draws);
    await moveAround(p, 800, 400); await sleep(600);
    const r = await ringAt(p, 815, 410), d = await box(p, '.bc-dot');
    check('ring follows again after restore', approx(r.cx, 815, 1) && approx(r.cy, 410, 1), r);
    check('dot follows after restore', approx(d.cx, 815, 3), d);
    check('smoke draws again after restore', (await p.evaluate(() => window.__draws)) > d0);
    check('still exactly one of each', JSON.stringify(await counts(p)) === '{"dot":1,"ring":1,"canvas":1}');
    await p.evaluate(() => { window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })); });
    await sleep(100);
    check('extra pageshow while running does not duplicate', (await counts(p)).canvas === 1);
    check('no errors', p.errs.length === 0, p.errs);
    await p.__ctx.close();
  }

  console.log('\n[6] finding 6: once-only guard');
  {
    const p = await open(browser, { double: true });
    check('scripts included twice: one canvas/dot/ring', JSON.stringify(await counts(p)) === '{"dot":1,"ring":1,"canvas":1}', await counts(p));
    await p.evaluate(() => new Promise((res) => {
      let n = 0;
      ['/js/fluid-cursor.js', '/js/bubble-cursor.js'].forEach((s) => { const e = document.createElement('script'); e.src = s; e.onload = () => { if (++n === 2) res(); }; document.body.appendChild(e); });
    }));
    await sleep(200);
    check('scripts injected again after load: still one of each', JSON.stringify(await counts(p)) === '{"dot":1,"ring":1,"canvas":1}', await counts(p));
    check('no errors', p.errs.length === 0, p.errs);
    await p.__ctx.close();
  }

  console.log('\n[7] finding 7: touch devices vs touch-screen laptops');
  {
    const p = await open(browser, {}, { viewport: { width: 1440, height: 800 }, hasTouch: true });
    const mm = await p.evaluate(() => ({ anyHover: matchMedia('(any-hover: hover)').matches, anyFine: matchMedia('(any-pointer: fine)').matches }));
    console.log('       (Chromium hasTouch emulation reports ' + JSON.stringify(mm) + ' so the media-query gate alone says "touch-only"; the dormant mouse-wake path must cover it)');
    check('desktop + hasTouch: dormant (nothing drawn) until a mouse is used', JSON.stringify(await counts(p)) === '{"dot":0,"ring":0,"canvas":0}');
    await moveAround(p, 300, 300); await sleep(500);
    check('desktop + hasTouch: cursor created after first mouse move', JSON.stringify(await counts(p)) === '{"dot":1,"ring":1,"canvas":1}', await counts(p));
    check('desktop + hasTouch: dot visible after mouse move', (await op(p, '.bc-dot')) === 1);
    check('desktop + hasTouch: no errors', p.errs.length === 0, p.errs);
    await p.__ctx.close();
    for (const name of ['iPhone 13', 'Pixel 7', 'iPad (gen 7)', 'iPad Pro 11']) {
      const dev = Object.assign({}, devices[name]);
      delete dev.deviceScaleFactor; // keep software GL cheap
      const q = await open(browser, {}, dev);
      check(name + ': no cursor elements', JSON.stringify(await counts(q)) === '{"dot":0,"ring":0,"canvas":0}', await counts(q));
      await q.touchscreen.tap(120, 160); await sleep(150); await q.touchscreen.tap(200, 300); await sleep(300);
      check(name + ': still none after taps (taps are not a mouse)', JSON.stringify(await counts(q)) === '{"dot":0,"ring":0,"canvas":0}', await counts(q));
      check(name + ': no errors', q.errs.length === 0, q.errs);
      await q.__ctx.close();
    }
    const dev = Object.assign({}, devices['iPhone 13']); delete dev.deviceScaleFactor;
    const t = await open(browser, { settings: { hideOnTouch: false } }, dev);
    check('hideOnTouch=false forces the cursor on touch devices', (await counts(t)).dot === 1);
    await t.__ctx.close();
  }

  console.log('\n[8] finding 8: idle loop');
  {
    const p = await open(browser, { settings: { fluid: { DENSITY_DISSIPATION: 4, VELOCITY_DISSIPATION: 4 } } });
    const base = await p.screenshot();
    for (let i = 0; i < 40; i++) { await p.mouse.move(500 + Math.cos(i / 4) * 220, 450 + Math.sin(i / 4) * 150); await sleep(16); }
    const pos = { x: 500 + Math.cos(39 / 4) * 220, y: 450 + Math.sin(39 / 4) * 150 };
    await sleep(100);
    const live = await p.screenshot();
    const moving = await diffPixels(browser, base, live, pos);
    check('smoke is rendered while moving (pixels differ from baseline)', moving > 500, moving);
    check('loop awake while moving', !(await info(p)).sleep);
    await sleep(5000);
    check('loop sleeps after pointer idle + decay', (await info(p)).sleep && (await info(p)).run, await info(p));
    const d0 = await p.evaluate(() => window.__draws);
    await sleep(1500);
    const d1 = await p.evaluate(() => window.__draws);
    check('idle: zero draw calls in 1.5 s', d1 === d0, d1 - d0);
    const idle = await p.screenshot();
    check('idle: smoke layer cleared (back to baseline away from pointer)', (await diffPixels(browser, base, idle, pos)) < 200);
    const writes = await p.evaluate(() => new Promise((res) => {
      const ring = document.querySelector('.bc-ring'); let n = 0;
      new MutationObserver(() => n++).observe(ring, { attributes: true, attributeFilter: ['style'] });
      setTimeout(() => res(n), 1000);
    }));
    check('idle: ring transform not rewritten (converged)', writes === 0, writes);
    await moveAround(p, 200, 200); await sleep(300);
    check('mouse move wakes the loop', !(await info(p)).sleep);
    const d2 = await p.evaluate(() => window.__draws); await sleep(300);
    check('draws resume after wake', (await p.evaluate(() => window.__draws)) > d2);
    check('no errors', p.errs.length === 0, p.errs);
    await p.__ctx.close();
  }

  console.log('\n[9] finding 9: invalid hover selector');
  {
    const p = await open(browser, { settings: { hoverSelector: 'a[href, .x' } });
    await moveAround(p, 300, 300); await moveAround(p, 340, 220); await sleep(500);
    check('no pageerror from mouseover/mouseout', p.errs.length === 0, p.errs);
    check('one console.warn about the selector', p.warns.length === 1 && /invalid hover selector/.test(p.warns[0]), p.warns);
    check('falls back to default selector: link still triggers hover', (await p.getAttribute('.bc-ring', 'data-bc-state')) === 'hover');
    await p.__ctx.close();
  }

  console.log('\n[10] finding 10: CSS');
  {
    const p = await open(browser, { hostile: false });
    await moveAround(p, 300, 300); await sleep(400);
    const r = await box(p, '.bc-ring');
    check('ring is 40px without host box-sizing rule (same as hostile)', approx(r.w, 40, 0.5), r);
    await p.__ctx.close();
    const q = await open(browser, { settings: { ringSize: 100, ringColor: '#ff0000' } });
    await moveAround(q, 300, 300); await sleep(400);
    check('ringSize=100 renders 100px', approx((await box(q, '.bc-ring')).w, 100, 0.5));
    await moveAround(q, 340, 220); await sleep(500);
    check('hover size follows ring size (160px)', approx((await box(q, '.bc-ring')).w, 160, 1), await box(q, '.bc-ring'));
    check('hover fill derived from ring colour', await q.evaluate(() => getComputedStyle(document.querySelector('.bc-ring')).backgroundColor) === 'rgba(255, 0, 0, 0.12)', await q.evaluate(() => getComputedStyle(document.querySelector('.bc-ring')).backgroundColor));
    await moveAround(q, 650, 250); await sleep(500);
    check('text hover size follows ring size (225px)', approx((await box(q, '.bc-ring')).w, 225, 1));
    check('text hover fill = ring colour, label contrasts', await q.evaluate(() => { const r = document.querySelector('.bc-ring'); return getComputedStyle(r).backgroundColor === 'rgb(255, 0, 0)' && getComputedStyle(r.firstChild).color === 'rgb(255, 255, 255)'; }));
    check('no errors', q.errs.length === 0, q.errs);
    await q.__ctx.close();
  }

  console.log('\n[11] finding 11: WebGL context restore and filtering path');
  {
    const p = await open(browser, {});
    await moveAround(p, 300, 300); await sleep(300);
    const i0 = await p.evaluate(() => window.BubbleCursorFluid.info());
    check('WebGL2 path uses linear filtering without OES_texture_float_linear', i0 && i0.webgl2 && i0.linear && i0.bloom, i0);
    const ok = await p.evaluate(() => { const gl = document.querySelector('.bc-fluid-canvas').getContext('webgl2'); const e = gl && gl.getExtension('WEBGL_lose_context'); if (!e) return false; window.__lose = e; e.loseContext(); return true; });
    check('context lost (test hook available)', ok);
    await sleep(200);
    check('engine stops cleanly on loss', !(await info(p)).run);
    await p.evaluate(() => window.__lose.restoreContext());
    await sleep(500);
    check('webglcontextrestored rebuilds the sim', (await info(p)).run, await info(p));
    const d0 = await p.evaluate(() => window.__draws);
    await moveAround(p, 600, 400); await sleep(400);
    check('smoke draws after restore', (await p.evaluate(() => window.__draws)) > d0);
    check('no errors across loss/restore', p.errs.length === 0, p.errs);
    await p.__ctx.close();
  }

  console.log('\n[12] finding 5 (JS side): builder editors');
  {
    for (const [label, o] of [['body.elementor-editor-active', { bodyClass: 'elementor-editor-active' }]]) {
      const p = await open(browser, o);
      check(label + ': nothing created', JSON.stringify(await counts(p)) === '{"dot":0,"ring":0,"canvas":0}', await counts(p));
      await p.__ctx.close();
    }
    for (const q of ['vc_editable=true', 'elementor-preview=1', 'et_fb=1']) {
      const ctx = await browser.newContext(desktop); const p = await ctx.newPage();
      p.errs = []; p.on('pageerror', (e) => p.errs.push(e.message));
      await p.goto('http://127.0.0.1:' + PORT + '/page?' + q + '&o=' + encodeURIComponent('{}'));
      await sleep(300);
      check('?' + q + ': nothing created', JSON.stringify(await counts(p)) === '{"dot":0,"ring":0,"canvas":0}', await counts(p));
      check('?' + q + ': no errors', p.errs.length === 0, p.errs);
      await ctx.close();
    }
  }

  console.log('\n[13] RTL, scrolled page, resize');
  {
    const p = await open(browser, { rtl: true });
    await moveAround(p, 500, 300); await sleep(400);
    const d = await box(p, '.bc-dot'), r = await ringAt(p, 515, 310);
    check('RTL: dot and ring at pointer', approx(d.cx, 515, 3) && approx(r.cx, 515, 1) && approx(r.cy, 310, 1), { d, r });
    check('RTL: no errors', p.errs.length === 0, p.errs);
    await p.__ctx.close();
    const s = await open(browser, {});
    await s.evaluate(() => window.scrollTo({ top: 1500, behavior: 'instant' }));
    await sleep(300);
    await moveAround(s, 340, 330); await sleep(500);
    check('scrolled: dot/ring follow viewport pointer', approx((await ringAt(s, 355, 340)).cy, 340, 1));
    const fl = await s.evaluate(() => { const r = document.getElementById('far').getBoundingClientRect(); return { x: r.left + 20, y: r.top + 20 }; });
    await moveAround(s, fl.x, fl.y); await sleep(500);
    check('scrolled: hover over far link', (await s.getAttribute('.bc-ring', 'data-bc-state')) === 'hover');
    check('scrolled: canvas still fixed to viewport', await s.evaluate(() => document.querySelector('.bc-fluid-canvas').getBoundingClientRect().top === 0));
    await s.setViewportSize({ width: 900, height: 700 });
    await sleep(700);
    const cv = await s.evaluate(() => { const c = document.querySelector('.bc-fluid-canvas'); return { cw: c.clientWidth, ch: c.clientHeight, w: c.width, h: c.height }; });
    check('resize 1440->900: canvas and framebuffer follow', cv.cw === 900 && cv.ch === 700 && cv.w === 900 && cv.h === 700, cv);
    await s.setViewportSize({ width: 1440, height: 800 });
    await sleep(700);
    const cv2 = await s.evaluate(() => { const c = document.querySelector('.bc-fluid-canvas'); return { w: c.width, h: c.height }; });
    check('resize back to 1440x800', cv2.w === 1440 && cv2.h === 800, cv2);
    await moveAround(s, 400, 400); await sleep(300);
    check('works after resize', (await op(s, '.bc-dot')) === 1 && (await info(s)).run);
    check('resize/scroll: no errors', s.errs.length === 0, s.errs);
    await s.__ctx.close();
  }

  console.log('\n[14] default hover label ("View"), per-element override, opt-outs');
  {
    const p = await open(browser, { settings: { hoverLabel: 'View' } });
    await moveAround(p, 340, 220); await sleep(500);
    check('link with no own text shows the default word', (await p.getAttribute('.bc-ring', 'data-bc-state')) === 'text' && (await p.textContent('.bc-ring__label')) === 'View', await p.textContent('.bc-ring__label'));
    check('default word: ring 2.25x (90px) and label visible', approx((await box(p, '.bc-ring')).w, 90, 1) && (await op(p, '.bc-ring__label')) === 1);
    await moveAround(p, 650, 250); await sleep(500);
    check('per-element data-bubble-cursor-text still wins ("Open")', (await p.textContent('.bc-ring__label')) === 'Open');
    await moveAround(p, 1040, 220); await sleep(500);
    check('data-bubble-cursor-no-text: plain hover, no word', (await p.getAttribute('.bc-ring', 'data-bc-state')) === 'hover' && (await p.textContent('.bc-ring__label')) === '', await p.textContent('.bc-ring__label'));
    await moveAround(p, 1040, 320); await sleep(500);
    check('data-bubble-cursor-text="" opts out: plain hover, no word', (await p.getAttribute('.bc-ring', 'data-bc-state')) === 'hover' && (await p.textContent('.bc-ring__label')) === '');
    await moveAround(p, 1000, 500); await sleep(500);
    check('leaving clears the default word', !(await p.getAttribute('.bc-ring', 'data-bc-state')) && (await p.textContent('.bc-ring__label')) === '');
    check('default label: no errors/warnings', p.errs.length === 0 && p.warns.length === 0, [p.errs, p.warns]);
    await p.__ctx.close();

    const e = await open(browser, { settings: { hoverLabel: '' } });
    await moveAround(e, 340, 220); await sleep(500);
    check('empty label: plain hover, no word', (await e.getAttribute('.bc-ring', 'data-bc-state')) === 'hover' && (await e.textContent('.bc-ring__label')) === '');
    await e.__ctx.close();

    const off = await open(browser, { settings: { hoverText: false, hoverLabel: 'View' } });
    await moveAround(off, 340, 220); await sleep(500);
    check('hoverText off: no word even with a label set', (await off.getAttribute('.bc-ring', 'data-bc-state')) === 'hover' && (await off.textContent('.bc-ring__label')) === '');
    await off.__ctx.close();

    const legacy = await open(browser, { settings: { hoverText: 'Go', hoverLabel: null } });
    await moveAround(legacy, 340, 220); await sleep(500);
    check('legacy hoverText string (older settings) is used as the label', (await legacy.textContent('.bc-ring__label')) === 'Go');
    await legacy.__ctx.close();

    const xss = await open(browser, { settings: { hoverLabel: '<img src=x onerror=alert(1)>' } });
    await moveAround(xss, 340, 220); await sleep(500);
    check('label is text, never HTML', (await xss.textContent('.bc-ring__label')) === '<img src=x onerror=alert(1)>' && (await xss.$$('.bc-ring__label img')).length === 0);
    await xss.__ctx.close();
  }

  console.log('\n[15] ring blend mode (mixBlend)');
  {
    const p = await open(browser, { settings: { mixBlend: 'difference' } });
    check('mixBlend difference reaches the ring', (await p.evaluate(() => getComputedStyle(document.querySelector('.bc-ring')).mixBlendMode)) === 'difference');
    await p.__ctx.close();
    const q = await open(browser, {});
    check('no mixBlend: ring stays normal', (await q.evaluate(() => getComputedStyle(document.querySelector('.bc-ring')).mixBlendMode)) === 'normal');
    await q.__ctx.close();
  }

  console.log('\n[16] reduced motion is live (emulateMedia toggled mid-visit)');
  {
    const p = await open(browser, { settings: { hideNativeCursor: true } });
    await moveAround(p, 300, 300); await sleep(500);
    check('before: native cursor hidden and follower drawn', (await p.evaluate(() => getComputedStyle(document.body).cursor)) === 'none' && (await op(p, '.bc-dot')) === 1);
    await p.emulateMedia({ reducedMotion: 'reduce' });
    await sleep(400);
    check('reduce on: native cursor visible again', (await p.evaluate(() => getComputedStyle(document.body).cursor)) !== 'none', await p.evaluate(() => getComputedStyle(document.body).cursor));
    check('reduce on: no bc-hide-native marker', !(await p.evaluate(() => document.documentElement.classList.contains('bc-hide-native'))));
    check('reduce on: follower and canvas gone', JSON.stringify(await counts(p)) === '{"dot":0,"ring":0,"canvas":0}', await counts(p));
    check('reduce on: smoke engine stopped', !(await info(p)).run);
    await p.evaluate(() => { window.__raf = 0; window.__draws = 0; });
    await moveAround(p, 500, 400); await sleep(600);
    const q = await p.evaluate(() => ({ raf: window.__raf, draws: window.__draws }));
    check('reduce on: zero requestAnimationFrame and zero GL draws while moving', q.raf === 0 && q.draws === 0, q);
    await p.emulateMedia({ reducedMotion: 'no-preference' });
    await sleep(500);
    check('reduce off: follower and canvas back', JSON.stringify(await counts(p)) === '{"dot":1,"ring":1,"canvas":1}', await counts(p));
    await moveAround(p, 400, 300); await sleep(500);
    check('reduce off: dot visible and native cursor hidden again', (await op(p, '.bc-dot')) === 1 && (await p.evaluate(() => getComputedStyle(document.body).cursor)) === 'none');
    check('reduce off: smoke running', (await info(p)).run);
    check('reduce toggling: no errors', p.errs.length === 0, p.errs);
    await p.__ctx.close();

    const r = await open(browser, { settings: { hideNativeCursor: true } }, { viewport: { width: 1440, height: 800 }, reducedMotion: 'reduce' });
    check('started reduced: nothing drawn', JSON.stringify(await counts(r)) === '{"dot":0,"ring":0,"canvas":0}');
    await r.emulateMedia({ reducedMotion: 'no-preference' });
    await sleep(500);
    await moveAround(r, 300, 300); await sleep(500);
    check('started reduced, then turned off: cursor starts', (await counts(r)).ring === 1 && (await op(r, '.bc-ring')) === 1);
    await r.__ctx.close();

    const c = await open(browser, { settings: { hideNativeCursor: true } }, { viewport: { width: 1440, height: 800 }, reducedMotion: 'reduce' });
    const cur = await c.evaluate(() => { document.documentElement.classList.add('bc-active', 'bc-hide-native'); return getComputedStyle(document.body).cursor; });
    check('CSS alone never hides the native cursor under reduced motion (stale marker)', cur !== 'none', cur);
    await c.__ctx.close();
  }

  console.log('\n[17] one-cursor stand-down (other cursor plugin flags)');
  {
    for (const flag of ['__fluxJellyActive', '__superCursorActive', '__sdcpJellyActive', '__sdcpDualActive']) {
      const p = await open(browser, { settings: { hideNativeCursor: true }, pre: 'window.' + flag + '=true' });
      await moveAround(p, 300, 300); await sleep(300);
      check(flag + ': nothing mounted', JSON.stringify(await counts(p)) === '{"dot":0,"ring":0,"canvas":0}', await counts(p));
      check(flag + ': native cursor untouched', (await p.evaluate(() => getComputedStyle(document.body).cursor)) !== 'none' && !(await p.evaluate(() => document.documentElement.classList.contains('bc-active'))));
      check(flag + ': one console.warn naming it', p.warns.length === 1 && p.warns[0].indexOf(flag) !== -1, p.warns);
      check(flag + ': does not claim __bubbleCursorBooted', !(await p.evaluate(() => window.__bubbleCursorBooted)));
      check(flag + ': no errors', p.errs.length === 0, p.errs);
      await p.__ctx.close();
    }
    const f = await open(browser, { pre: 'window.__fluxJellyActive=false;window.__sdcpDualActive=0' });
    check('falsy flags do not block the cursor', (await counts(f)).ring === 1 && f.warns.length === 0, f.warns);
    check('normal boot sets __bubbleCursorBooted', await f.evaluate(() => window.__bubbleCursorBooted === true));
    await f.__ctx.close();
    // Touch-only (dormant) boot with a sibling flag: nothing is mounted either.
    const t = await open(browser, { pre: 'window.__sdcpJellyActive=true' }, Object.assign({}, devices['iPhone 13']));
    check('touch device + sibling flag: nothing mounted', JSON.stringify(await counts(t)) === '{"dot":0,"ring":0,"canvas":0}');
    await t.__ctx.close();
  }

  await browser.close();
  srv.close();
  console.log('\nBrowser tests: ' + passed + ' passed, ' + failed + ' failed (Chromium only)');
  if (failed) { console.log('Failed: ' + failures.join('; ')); process.exit(1); }
}
main().catch((e) => { console.error(e); process.exit(2); });
