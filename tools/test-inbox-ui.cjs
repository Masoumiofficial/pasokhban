// Inbox interaction test using a simulated DOM and stubbed REST responses.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const tree = process.argv[2] || 'src/gapineh';
(async () => {
  const html = `<!doctype html><body><div class="gapineh-inbox-wrap"><div id="ei-app" class="ei-app">
    <aside class="ei-list"><input id="ei-search"><select id="ei-filter"><option value="all">all</option></select>
      <select id="ei-owner"><option value="all">all</option></select><button id="ei-refresh"></button>
      <div id="ei-items"><button class="ei-item" data-id="7">chat</button></div><span id="ei-shown"></span><span id="ei-total"></span>
    </aside><section class="ei-thread"><div id="ei-empty">empty</div><header id="ei-thead" hidden>
      <strong id="ei-tname"></strong><span id="ei-tsub"></span><span id="ei-tcontact"></span>
      <input type="checkbox" id="ei-mode"><button id="ei-close"></button><button id="ei-delete"></button></header>
      <div id="ei-msgs" hidden></div><footer id="ei-composer" hidden><textarea id="ei-input"></textarea><button id="ei-send">send</button></footer>
    </section></div></div></body>`;
  const dom = new JSDOM(html, { url: 'https://example.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const { window: w } = dom;
  await new Promise(resolve => w.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  Object.defineProperty(w, 'innerWidth', { value: 600, configurable: true });
  w.GAPINEH_INBOX = { restUrl: '/api', nonce: '', i18n: {} };
  const calls = [];
  w.fetch = (url, opts) => {
    calls.push([String(url), opts]);
    let data = {};
    if (String(url).includes('/admin/session?')) data = { session: { name:'مشتری', mode:'ai', status:'open' }, messages:[{id:1,sender:'visitor',content:'سلام'}] };
    if (String(url).includes('/admin/sessions?')) data = { items:[{id:7,name:'مشتری',mode:'ai',status:'open'}], total:1 };
    if (String(url).includes('/admin/reply')) data = { message: {id:2,sender:'agent',content:'پاسخ'} };
    return Promise.resolve({ok:true, json:() => Promise.resolve(data)});
  };
  w.eval(fs.readFileSync(path.join(tree, process.env.MINIFIED ? 'assets/js/gapineh-inbox.min.js' : 'assets/js/gapineh-inbox.js'), 'utf8'));
  const click = el => el.dispatchEvent(new w.MouseEvent('click',{bubbles:true}));
  assert(w.document.getElementById('ei-msgs').hidden, 'message pane hidden before selecting a thread');
  click(w.document.querySelector('.ei-item'));
  await new Promise(resolve => setTimeout(resolve, 0));
  assert(w.document.getElementById('ei-app').classList.contains('is-thread-open'));
  assert(!w.document.getElementById('ei-msgs').hidden);
  assert.match(w.document.getElementById('ei-msgs').textContent, /سلام/);
  assert.equal(w.document.getElementById('ei-tname').textContent, 'مشتری');
  w.document.getElementById('ei-input').value = 'پاسخ';
  click(w.document.getElementById('ei-send'));
  await new Promise(resolve => setTimeout(resolve, 0));
  assert(calls.some(([url]) => url.includes('/admin/reply')), 'reply endpoint called');
  click(w.document.querySelector('.ei-back'));
  assert(!w.document.getElementById('ei-app').classList.contains('is-thread-open'), 'mobile back returns to list');
  w.close();
  console.log('✅ Inbox open, reply and mobile back passed');
})().catch(err => { console.error(err); process.exitCode = 1; });
