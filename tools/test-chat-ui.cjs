// Regression test: clicking a nested SVG in the theme button must not close chat.
// Run: NODE_PATH=/path/to/node_modules node tools/test-chat-ui.cjs [plugin-tree]
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const tree = process.argv[2] || 'src/gapineh';

(async () => {
  const dom = new JSDOM('<!doctype html><body><div id="outside">outside</div><div id="gapineh-live" data-mode="widget"></div></body>', {
    url: 'https://example.test/', runScripts: 'outside-only', pretendToBeVisual: true
  });
  const { window: w } = dom;
  await new Promise(resolve => w.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  w.GAPINEH_CHAT = { restUrl: '/wp-json/gapineh/v1', nonce: '', lang: 'fa' };
  w.GAPINEH_CHAT_SETTINGS = { prechat: true, prechatRequired: true, theme: 'auto', teaser: false };
  w.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
  w.eval(fs.readFileSync(path.join(tree, process.env.MINIFIED ? 'assets/js/gapineh-chat.min.js' : 'assets/js/gapineh-chat.js'), 'utf8'));
  const root = w.document.getElementById('gapineh-live');
  const click = el => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
  click(w.document.getElementById('psb-launcher'));
  assert(root.classList.contains('is-open'), 'launcher opens panel');
  assert.equal(w.document.getElementById('psb-theme').type, 'button');
  // Click path rather than button, reproducing the detached-target bug.
  click(w.document.querySelector('#psb-theme svg path'));
  assert(root.classList.contains('is-open'), 'panel remains open after theme icon click');
  assert(root.classList.contains('psb-dark'), 'theme changed to dark');
  click(w.document.querySelector('#psb-theme svg path'));
  assert(root.classList.contains('is-open'), 'panel remains open on second toggle');
  assert(root.classList.contains('psb-light'), 'theme changed to light');
  click(w.document.getElementById('outside'));
  assert(!root.classList.contains('is-open'), 'outside click still closes panel');
  w.close();
  const reloaded = new JSDOM('<!doctype html><body><div id="gapineh-live" data-mode="widget"></div></body>', {
    url: 'https://example.test/', runScripts: 'outside-only', pretendToBeVisual: true
  });
  const w2 = reloaded.window;
  await new Promise(resolve => w2.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  w2.localStorage.setItem('gapineh_live_theme', 'dark');
  w2.GAPINEH_CHAT_SETTINGS = { prechat: true, prechatRequired: true, teaser: false };
  w2.eval(fs.readFileSync(path.join(tree, process.env.MINIFIED ? 'assets/js/gapineh-chat.min.js' : 'assets/js/gapineh-chat.js'), 'utf8'));
  assert(w2.document.getElementById('gapineh-live').classList.contains('psb-dark'), 'saved theme restored');
  assert.equal(w2.document.getElementById('psb-theme').getAttribute('aria-pressed'), 'true', 'theme button reflects saved mode');
  w2.close();
  console.log('✅ Theme icon, persistence and outside-click regression passed');
})().catch(err => { console.error(err); process.exitCode = 1; });
