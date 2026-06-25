/*
 * Bubble Cursor — consolidated front-end test suite (headless Chromium).
 *
 * Prereqs:
 *   1) From the repo root:  php -S 127.0.0.1:8765
 *   2) npm i -D playwright-core   (browsers are NOT downloaded; point CHROME at one)
 *   3) node tests/playwright/run.mjs
 *
 * Env overrides:
 *   CHROME = path to a Chromium/Chrome binary
 *            (default: /opt/pw-browsers/chromium-1194/chrome-linux/chrome)
 *   BASE   = base URL of the running server (default: http://127.0.0.1:8765)
 *
 * Exits 0 if every check passes, 1 otherwise.
 */
import { chromium } from 'playwright-core';

const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BASE || 'http://127.0.0.1:8765';
const HARNESS = BASE + '/tests/fixtures/harness.html';
const DEMO = BASE + '/demo/index.html';

const results = [];
const record = (name, pass, extra) => results.push({ name, pass: !!pass, extra });

const browser = await chromium.launch({
  executablePath: CHROME,
  headless: true,
  args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', '--no-sandbox'],
});

async function open(url, cfg) {
  const page = await browser.newPage({ viewport: { width: 1000, height: 700 } });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error' && !/favicon|404/.test(m.text())) errors.push(m.text()); });
  if (cfg) await page.addInitScript((c) => { window.__BC = c; }, cfg);
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForTimeout(350);
  return { page, errors };
}

const sampleFn = () => {
  const c = document.querySelector('.bc-fluid-canvas');
  if (!c) return { n: 0 };
  const t = document.createElement('canvas'); t.width = c.width; t.height = c.height;
  t.getContext('2d').drawImage(c, 0, 0);
  const d = t.getContext('2d').getImageData(0, 0, t.width, t.height).data;
  let r = 0, g = 0, b = 0, n = 0;
  for (let i = 0; i < d.length; i += 4) { if (d[i + 3] > 10 && d[i] + d[i + 1] + d[i + 2] > 40) { r += d[i]; g += d[i + 1]; b += d[i + 2]; n++; } }
  return n ? { r: r / n, g: g / n, b: b / n, n } : { n: 0 };
};

// 1) Smoke renders on the demo with no JS/shader errors.
{
  const { page, errors } = await open(DEMO, null);
  const p = await page.evaluate(() => ({
    webgl: !!(document.createElement('canvas').getContext('webgl2') || document.createElement('canvas').getContext('webgl')),
    running: window.BubbleCursorFluid.isRunning(),
    canvas: !!document.querySelector('.bc-fluid-canvas'),
  }));
  record('smoke renders, no errors', p.running && p.canvas && errors.length === 0, JSON.stringify(p));
  await page.close();
}

// 2) "View" hover bubble on the demo cards.
{
  const { page } = await open(DEMO, null);
  await page.evaluate(() => document.getElementById('work').scrollIntoView());
  await page.waitForTimeout(300);
  const box = await (await page.$('.card[data-bubble-cursor-text="View"]')).boundingBox();
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.waitForTimeout(300);
  const st = await page.evaluate(() => ({
    hoverText: document.documentElement.classList.contains('bc-hover-text'),
    label: (document.querySelector('.bc-ring__label') || {}).textContent || '',
  }));
  record('"View" hover bubble', st.hoverText && st.label === 'View', JSON.stringify(st));
  await page.close();
}

// 3) Ring converges to the pointer (frame-rate-independent easing).
{
  const { page, errors } = await open(DEMO, null);
  await page.mouse.move(720, 250);
  await page.waitForTimeout(700);
  const pos = await page.evaluate(() => {
    const m = (document.querySelector('.bc-ring').style.transform || '').match(/translate3d\(([-\d.]+)px,\s*([-\d.]+)px/);
    return m ? { x: +m[1], y: +m[2] } : { x: null };
  });
  record('ring converges to pointer', pos.x !== null && Math.abs(pos.x - 720) < 6 && Math.abs(pos.y - 250) < 6 && errors.length === 0, JSON.stringify(pos));
  await page.close();
}

// 4) Palette colour mode paints the chosen colour.
{
  const { page } = await open(HARNESS, { enableRing: false, enableDot: false, hideOnTouch: true,
    fluid: { COLOR_MODE: 'palette', PALETTE: ['#00cc44'], COLORFUL: false, BLOOM: false, PRESERVE_DRAWING_BUFFER: true } });
  for (let i = 0; i < 70; i++) { await page.mouse.move(120 + i * 9, 350 + Math.sin(i / 3) * 40); }
  await page.waitForTimeout(250);
  const s = await page.evaluate(sampleFn);
  record('palette mode = green smoke', s.n > 500 && s.g > s.r + 8 && s.g > s.b + 8, JSON.stringify(s));
  await page.close();
}

// 5) Auto-contrast applies the difference blend + forced white.
{
  const { page } = await open(HARNESS, { enableFluid: false, autoContrast: true, hideOnTouch: true });
  await page.mouse.move(120, 120); // empty dark area (not over the button)
  await page.waitForTimeout(200);
  const st = await page.evaluate(() => {
    const r = document.querySelector('.bc-ring');
    return { cls: document.documentElement.classList.contains('bc-auto-contrast'),
             blend: getComputedStyle(r).mixBlendMode, color: r.style.getPropertyValue('--bc-ring-color') };
  });
  record('auto-contrast (difference + white)', st.cls && st.blend === 'difference' && st.color === '#ffffff', JSON.stringify(st));
  await page.close();
}

// 6) Image preview shows the item's image and clears on leave.
{
  const { page } = await open(HARNESS, { enableFluid: false, enableRing: true, enableDot: true, hideOnTouch: true,
    imagePreview: true, previewSelector: '.portfolio-item' });
  await page.mouse.move(200, 325); // over the portfolio item
  await page.waitForTimeout(250);
  const on = await page.evaluate(() => ({ on: document.documentElement.classList.contains('bc-preview-on'),
    bg: (document.querySelector('.bc-preview') || {}).style?.backgroundImage || '' }));
  await page.mouse.move(850, 600);
  await page.waitForTimeout(250);
  const off = await page.evaluate(() => document.documentElement.classList.contains('bc-preview-on'));
  record('image preview shows + clears', on.on && /data:image\/png/.test(on.bg) && off === false, JSON.stringify({ ...on, off }));
  await page.close();
}

// 7) Magnetic morph hugs the button, reverts on leave.
{
  const { page } = await open(HARNESS, { enableFluid: false, enableRing: true, enableDot: true, hideOnTouch: true, magnetic: true });
  await page.mouse.move(560, 330); // over the 200x100 button
  await page.waitForTimeout(300);
  const m = await page.evaluate(() => { const r = document.querySelector('.bc-ring'); return { w: r.style.width, h: r.style.height }; });
  await page.mouse.move(850, 600);
  await page.waitForTimeout(300);
  const rev = await page.evaluate(() => { const r = document.querySelector('.bc-ring'); return { w: r.style.width, h: r.style.height }; });
  record('magnetic morph + revert', parseInt(m.w) >= 200 && parseInt(m.w) <= 240 && rev.w === '' && rev.h === '', JSON.stringify({ m, rev }));
  await page.close();
}

await browser.close();

let failed = 0;
for (const r of results) {
  console.log(`  ${r.pass ? 'PASS' : 'FAIL'} - ${r.name}${r.pass ? '' : '  ' + (r.extra || '')}`);
  if (!r.pass) failed++;
}
console.log(failed ? `\nRESULT: ${failed} FAILED` : '\nRESULT: ALL PASSED');
process.exit(failed ? 1 : 0);
