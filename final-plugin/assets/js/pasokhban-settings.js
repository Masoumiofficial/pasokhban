/* =========================================================================
   پاسخ‌بان — صفحهٔ تنظیمات
   بدون وابستگی به jQuery.

   سه چیزی که اینجا حل می‌شود:
   ۱) ۱۰۶ فیلد در ۹ تب → جست‌وجوی زنده که کارت‌ها را فیلتر و تطبیق‌ها را
      برجسته می‌کند، بدون اینکه کاربر لازم باشد بداند آن تنظیم در کدام تب است.
   ۲) کارت‌های جمع‌شونده با یادآوری وضعیت — تا یک تب هفت کارتِ باز
      نداشته باشد که اسکرولش تمام‌نشدنی باشد.
   ۳) نوار ذخیرهٔ چسبان با هشدار «تغییرات ذخیره‌نشده» — چون قبلاً دکمهٔ
      ذخیره فقط پایین صفحه بود و اگر وسط کار تب عوض می‌کردی، یادت می‌رفت.
   ========================================================================= */
(function () {
  'use strict';

  var LS_COLLAPSED = 'pasokhban_collapsed';
  var dirty = false;

  function store(k, v) {
    try {
      if (arguments.length > 1) {
        if (v === null) localStorage.removeItem(k);
        else localStorage.setItem(k, v);
        return v;
      }
      return localStorage.getItem(k);
    } catch (e) { return null; }
  }

  function collapsedMap() {
    var raw = store(LS_COLLAPSED);
    if (!raw) return {};
    try { return JSON.parse(raw) || {}; } catch (e) { return {}; }
  }

  function setCollapsed(id, on) {
    var m = collapsedMap();
    if (on) m[id] = 1; else delete m[id];
    try { store(LS_COLLAPSED, JSON.stringify(m)); } catch (e) {}
  }

  function init() {
    var tabs = document.getElementById('psb-set-tabs');
    if (!tabs) return;

    var buttons = tabs.querySelectorAll('.psb-set-tab');
    var panels = document.querySelectorAll('.psb-set-panel');

    /* ---------------------------------------------------------
       ۱) کارت‌های جمع‌شونده
    --------------------------------------------------------- */
    var cards = document.querySelectorAll('.psb-set-panel .psb-set-card');
    var saved = collapsedMap();

    for (var ci = 0; ci < cards.length; ci++) {
      (function (card) {
        var h2 = card.querySelector('h2');
        if (!h2) return;

        // شناسهٔ پایدار: id کارت، یا عنوانش
        var cid = card.id || '';
        if (!cid) {
          cid = 'c' + (h2.textContent || '').replace(/[^a-zA-Z0-9\u0600-\u06FF]+/g, '-').slice(0, 40);
          card.id = cid;
        }

        // دکمهٔ جمع/باز
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'psb-set-fold';
        btn.setAttribute('aria-label', 'جمع/باز کردن');
        btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        h2.appendChild(btn);

        function fold(on) {
          card.classList.toggle('is-folded', on);
          btn.setAttribute('aria-expanded', on ? 'false' : 'true');
          setCollapsed(cid, on);
        }

        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          fold(!card.classList.contains('is-folded'));
        });

        // کلیک روی عنوان هم جمع می‌کند — ولی نه اگر کاربر در حال
        // انتخاب متن باشد
        h2.addEventListener('click', function (e) {
          if (e.target !== h2 && !h2.contains(e.target)) return;
          if (String(window.getSelection && window.getSelection())) return;
          fold(!card.classList.contains('is-folded'));
        });

        if (saved[cid]) fold(true);
      })(cards[ci]);
    }

    /* ---------------------------------------------------------
       ۲) ناوبری تب
    --------------------------------------------------------- */
    function activate(name, pushHash) {
      var found = false;
      for (var i = 0; i < panels.length; i++) {
        var on = panels[i].getAttribute('data-panel') === name;
        panels[i].classList.toggle('is-active', on);
        if (on) found = true;
      }
      if (!found) return false;

      for (var j = 0; j < buttons.length; j++) {
        var b = buttons[j];
        var onb = b.getAttribute('data-tab') === name;
        b.classList.toggle('is-active', onb);
        b.setAttribute('aria-selected', onb ? 'true' : 'false');
      }

      if (pushHash !== false && window.history && window.history.replaceState) {
        window.history.replaceState(null, '', '#' + name);
      }
      return true;
    }

    for (var bi = 0; bi < buttons.length; bi++) {
      (function (btn) {
        btn.addEventListener('click', function () {
          activate(btn.getAttribute('data-tab'));
          if (window.innerWidth <= 960 && window.scrollTo) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
          }
        });
      })(buttons[bi]);
    }

    /* ---------------------------------------------------------
       ۳) جست‌وجوی زنده
    --------------------------------------------------------- */
    var q = document.getElementById('psb-set-q');
    var qcount = document.getElementById('psb-set-qcount');

    /** متن قابل جست‌وجوی یک کارت (برچسب‌ها + توضیح‌ها + مقدارها). */
    function cardText(card) {
      var t = card.textContent || '';
      // مقادیر فعلی فیلدها هم searchable باشند
      var inputs = card.querySelectorAll('input, select, textarea');
      for (var i = 0; i < inputs.length; i++) {
        var el = inputs[i];
        var nm = el.getAttribute('name') || '';
        t += ' ' + nm.replace('pasokhban_options[', '').replace(']', '');
        if (el.value) t += ' ' + el.value;
        var opts = el.querySelectorAll ? el.querySelectorAll('option') : [];
        for (var k = 0; k < opts.length; k++) t += ' ' + opts[k].textContent;
      }
      return t.toLowerCase().replace(/\s+/g, ' ');
    }

    var cache = [];
    for (var xi = 0; xi < cards.length; xi++) {
      cache.push({ el: cards[xi], text: cardText(cards[xi]) });
    }

    function clearHighlights() {
      var marks = document.querySelectorAll('.psb-set-panel mark.psb-q');
      for (var i = 0; i < marks.length; i++) {
        var m = marks[i];
        var parent = m.parentNode;
        if (!parent) continue;
        parent.replaceChild(document.createTextNode(m.textContent), m);
        parent.normalize();
      }
    }

    /** برجسته‌کردن تطبیق‌ها فقط در گره‌های متنی (نه داخل تگ‌ها). */
    function highlight(card, term) {
      var walker = document.createTreeWalker(card, NodeFilter.SHOW_TEXT, {
        acceptNode: function (node) {
          if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
          var p = node.parentNode;
          if (!p || p.nodeName === 'SCRIPT' || p.nodeName === 'STYLE') return NodeFilter.FILTER_REJECT;
          if (p.classList && p.classList.contains('psb-q')) return NodeFilter.FILTER_REJECT;
          return node.nodeValue.toLowerCase().indexOf(term) >= 0
            ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
        }
      });
      var nodes = [], n;
      while ((n = walker.nextNode())) nodes.push(n);

      var hits = 0;
      for (var i = 0; i < nodes.length && hits < 12; i++) {
        var node = nodes[i];
        var text = node.nodeValue;
        var low = text.toLowerCase();
        var at = low.indexOf(term);
        if (at < 0) continue;
        var before = document.createTextNode(text.slice(0, at));
        var mark = document.createElement('mark');
        mark.className = 'psb-q';
        mark.textContent = text.slice(at, at + term.length);
        var after = document.createTextNode(text.slice(at + term.length));
        var parent = node.parentNode;
        parent.insertBefore(before, node);
        parent.insertBefore(mark, node);
        parent.insertBefore(after, node);
        parent.removeChild(node);
        hits++;
      }
      return hits;
    }

    var totalHits = 0;

    function runSearch() {
      var term = (q.value || '').trim().toLowerCase();
      clearHighlights();
      totalHits = 0;

      if (!term) {
        for (var i = 0; i < cache.length; i++) {
          cache[i].el.classList.remove('is-hidden', 'is-match');
        }
        for (var p = 0; p < panels.length; p++) panels[p].classList.remove('is-empty');
        for (var b = 0; b < buttons.length; b++) buttons[b].classList.remove('has-hit');
        if (qcount) qcount.textContent = '';
        return;
      }

      var perPanel = {};

      for (var j = 0; j < cache.length; j++) {
        var c = cache[j];
        var isMatch = c.text.indexOf(term) >= 0;
        c.el.classList.toggle('is-match', isMatch);
        c.el.classList.toggle('is-hidden', !isMatch);
        if (isMatch) {
          // کارت مطابقت‌دار را باز کن تا نتیجه دیده شود
          c.el.classList.remove('is-folded');
          var hits = highlight(c.el, term);
          totalHits += hits || 1;
          var panel = c.el.closest ? c.el.closest('.psb-set-panel') : null;
          if (panel) {
            var key = panel.getAttribute('data-panel');
            perPanel[key] = (perPanel[key] || 0) + 1;
          }
        }
      }

      for (var k = 0; k < panels.length; k++) {
        var pk = panels[k].getAttribute('data-panel');
        panels[k].classList.toggle('is-empty', !perPanel[pk]);
      }
      for (var m2 = 0; m2 < buttons.length; m2++) {
        var bk = buttons[m2].getAttribute('data-tab');
        buttons[m2].classList.toggle('has-hit', !!perPanel[bk]);
      }

      if (qcount) {
        qcount.textContent = totalHits ? String(totalHits) : '۰';
        qcount.classList.toggle('is-zero', !totalHits);
      }

      // اگر تب فعال هیچ نتیجه‌ای ندارد، به اولین تبِ دارای نتیجه برو
      var activePanel = document.querySelector('.psb-set-panel.is-active');
      if (activePanel && activePanel.classList.contains('is-empty')) {
        for (var z = 0; z < buttons.length; z++) {
          if (buttons[z].classList.contains('has-hit')) {
            activate(buttons[z].getAttribute('data-tab'));
            break;
          }
        }
      }
    }

    if (q) {
      var qTimer = null;
      q.addEventListener('input', function () {
        clearTimeout(qTimer);
        qTimer = setTimeout(runSearch, 120);
      });
      q.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { q.value = ''; runSearch(); q.blur(); }
      });
      // میان‌بر: Ctrl/Cmd+K یا /
      document.addEventListener('keydown', function (e) {
        var tag = (e.target && e.target.tagName || '').toLowerCase();
        var typing = tag === 'input' || tag === 'textarea' || tag === 'select';
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
          e.preventDefault(); q.focus(); q.select();
        } else if (e.key === '/' && !typing) {
          e.preventDefault(); q.focus();
        }
      });
    }

    /* ---------------------------------------------------------
       ۴) نوار ذخیرهٔ چسبان + هشدار تغییرات ذخیره‌نشده
    --------------------------------------------------------- */
    var form = document.getElementById('psb-set-form');
    var bar = document.getElementById('psb-set-savebar');

    function markDirty() {
      if (dirty) return;
      dirty = true;
      if (bar) bar.classList.add('is-dirty');
      document.body.classList.add('psb-dirty');
    }
    function markClean() {
      dirty = false;
      if (bar) bar.classList.remove('is-dirty');
      document.body.classList.remove('psb-dirty');
    }

    if (form) {
      form.addEventListener('input', markDirty);
      form.addEventListener('change', markDirty);
      form.addEventListener('submit', markClean);
      window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; return ''; }
      });
    }

    var saveTop = document.getElementById('psb-set-save-top');
    var barBtn = document.getElementById('psb-set-save-bar');
    if (barBtn && form) {
      barBtn.addEventListener('click', function () {
        markClean();
        form.submit();
      });
    }
    if (saveTop) saveTop.addEventListener('click', markClean);

    /* ---------------------------------------------------------
       ۵) رادیوهای انتخاب حالت
    --------------------------------------------------------- */
    var picks = document.querySelectorAll('.psb-set-pick input[type=radio]');
    for (var ri = 0; ri < picks.length; ri++) {
      (function (r) {
        r.addEventListener('change', function () {
          var group = document.querySelectorAll('.psb-set-pick');
          for (var gm = 0; gm < group.length; gm++) {
            var inp = group[gm].querySelector('input[type=radio]');
            group[gm].classList.toggle('is-on', !!(inp && inp.checked));
          }
        });
      })(picks[ri]);
    }

    /* ---------------------------------------------------------
       ۶) ورود فایل برای «وارد کردن تنظیمات»
    --------------------------------------------------------- */
    var impPick = document.getElementById('psb-import-file');
    var impReal = document.getElementById('psb-import-input');
    var impForm = document.getElementById('psb-import-form');
    if (impPick && impReal && impForm) {
      impPick.addEventListener('change', function () {
        if (!impPick.files || !impPick.files[0]) return;
        if (!window.confirm('تنظیمات فعلی با محتوای این فایل جایگزین شود؟')) {
          impPick.value = '';
          return;
        }
        try {
          var dt = new DataTransfer();
          dt.items.add(impPick.files[0]);
          impReal.files = dt.files;
        } catch (e) {
          // مرورگرهای قدیمی DataTransfer ندارند — مستقیم submit می‌کنیم
          impReal.value = '';
        }
        impForm.submit();
      });
    }

    /* ---------------------------------------------------------
       ۷) تب فعال از hash
    --------------------------------------------------------- */
    function activateByHash(hash) {
      if (!hash) return false;
      // نام تب
      if (activate(hash, false)) return true;
      // id یک کارت → تب والدش را باز کن و به آن اسکرول کن
      var el = document.getElementById(hash);
      if (el) {
        var host = el.closest ? el.closest('.psb-set-panel') : null;
        if (host) {
          activate(host.getAttribute('data-panel'), false);
          el.classList.remove('is-folded');
          try { el.scrollIntoView({ block: 'start', behavior: 'smooth' }); } catch (e) {}
          el.classList.add('is-flash');
          setTimeout(function () { el.classList.remove('is-flash'); }, 1600);
          return true;
        }
      }
      return false;
    }

    var hash = (window.location.hash || '').replace('#', '');
    if (!activateByHash(hash)) {
      var first = tabs.querySelector('.psb-set-tab.is-active') || buttons[0];
      if (first) activate(first.getAttribute('data-tab'), false);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

/* =========================================================================
   پاسخ‌بان — تب «بررسی سلامت»
   ---------------------------------------------------------------------
   چرا بررسی‌ها یکی‌یکی اجرا می‌شوند و نه همه با یک درخواست:
   بعضی بررسی‌ها به سرویس بیرونی می‌روند (هوش مصنوعی، تلگرام، بله، رلهٔ
   گوگل). اگر همه در یک درخواست اجرا شوند، یک سرویس فیلترشده یا تایم‌اوت
   کل صفحه را ۳۰ ثانیه معطل می‌کند و کاربر هیچ پیشرفتی نمی‌بیند. با اجرای
   ترتیبی، نتیجهٔ هر بررسی به‌محض آماده‌شدن روی صفحه می‌نشیند.
   ========================================================================= */
(function () {
  'use strict';

  var CFG = window.PASOKHBAN_HEALTH;
  if (!CFG || !CFG.url) { return; }

  var T = CFG.i18n || {};
  var results = [];
  var running = false;

  var ICON = {
    pass: '✅', fail: '❌', warn: '⚠️', info: 'ℹ️', skip: '⏭'
  };

  function el(id) { return document.getElementById(id); }

  function itemFor(checkId) {
    return document.querySelector('.psb-hc-item[data-check="' + checkId + '"]');
  }

  function setItem(checkId, r) {
    var li = itemFor(checkId);
    if (!li) { return; }
    li.className = 'psb-hc-item is-' + r.status;
    var dot = li.querySelector('.psb-hc-dot');
    var msg = li.querySelector('.psb-hc-msg');
    var ms = li.querySelector('.psb-hc-ms');
    if (dot) { dot.textContent = ICON[r.status] || '•'; }
    if (msg) {
      msg.textContent = r.msg || '';
      // راهنمای رفع مشکل و جزئیات، زیر پیام اصلی — چون همان چیزی است که
      // کاربر واقعاً لازم دارد، نه فقط «خطا».
      var extra = '';
      if ((r.status === 'fail' || r.status === 'warn') && r.detail) {
        extra += '\n↳ ' + r.detail;
      }
      if ((r.status === 'fail' || r.status === 'warn') && r.fix) {
        extra += '\n→ ' + r.fix;
      }
      if (extra) {
        var pre = document.createElement('span');
        pre.className = 'psb-hc-extra';
        pre.textContent = extra;
        msg.appendChild(pre);
      }
    }
    if (ms) { ms.textContent = r.ms > 0 ? r.ms + ' ms' : ''; }
  }

  function markPending(checkId) {
    var li = itemFor(checkId);
    if (!li) { return; }
    li.className = 'psb-hc-item is-running';
    var msg = li.querySelector('.psb-hc-msg');
    if (msg) { msg.textContent = T.running || '…'; }
    var dot = li.querySelector('.psb-hc-dot');
    if (dot) { dot.textContent = '⏳'; }
  }

  /** یک بررسی را می‌گیرد. خطای شبکه هم به یک نتیجهٔ fail تبدیل می‌شود
      تا صف متوقف نشود. */
  function fetchOne(checkId) {
    var url = CFG.url + (CFG.url.indexOf('?') > -1 ? '&' : '?') + 'check=' + encodeURIComponent(checkId);
    return fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce || '' }
    }).then(function (res) {
      if (!res.ok) {
        throw new Error('HTTP ' + res.status);
      }
      return res.json();
    }).then(function (data) {
      if (!data || !data.ok || !data.result) {
        throw new Error((data && data.error) || 'bad response');
      }
      return data.result;
    })['catch'](function (err) {
      return {
        id: checkId, group: '', label: checkId, status: 'fail',
        msg: (T.netErr || 'خطا') + ' (' + err.message + ')',
        detail: '', fix: '', ms: 0
      };
    });
  }

  function setBusy(on) {
    running = on;
    var run = el('psb-hc-run');
    var copy = el('psb-hc-copy');
    if (run) {
      run.disabled = on;
      run.textContent = on ? (T.running || '…') : (T.runAll || 'اجرای همهٔ بررسی‌ها');
    }
    if (copy && !on) { copy.disabled = results.length === 0; }
    var groupBtns = document.querySelectorAll('.psb-hc-grun');
    for (var i = 0; i < groupBtns.length; i++) { groupBtns[i].disabled = on; }
  }

  function countDone() {
    var c = el('psb-hc-count');
    if (!c) { return; }
    var all = document.querySelectorAll('.psb-hc-item').length;
    var n = { pass: 0, fail: 0, warn: 0 };
    for (var i = 0; i < results.length; i++) {
      if (n[results[i].status] !== undefined) { n[results[i].status]++; }
    }
    c.textContent = results.length + '/' + all + ' · ' +
      (T.pass || 'سالم') + ' ' + n.pass + ' · ' +
      (T.fail || 'خطا') + ' ' + n.fail + ' · ' +
      (T.warn || 'هشدار') + ' ' + n.warn;
  }

  /** گزارش متنی — همان قالبی که سمت سرور هم می‌سازد، تا اگر کاربر
      گزارش را کپی کند پشتیبانی بتواند بخواندش. */
  function buildReport() {
    var groups = document.querySelectorAll('.psb-hc-group');
    var byId = {};
    for (var i = 0; i < results.length; i++) { byId[results[i].id] = results[i]; }

    var lines = [];
    var d = new Date();
    lines.push('پاسخ‌بان — گزارش سلامت ' + d.toLocaleString('fa-IR'));
    lines.push('──────────────────────────────');

    var sum = { pass: 0, fail: 0, warn: 0, info: 0, skip: 0, total: 0 };

    for (var g = 0; g < groups.length; g++) {
      var label = groups[g].querySelector('.psb-hc-glabel');
      var items = groups[g].querySelectorAll('.psb-hc-item');
      var block = [];
      for (var k = 0; k < items.length; k++) {
        var r = byId[items[k].getAttribute('data-check')];
        if (!r) { continue; }
        sum.total++;
        if (sum[r.status] !== undefined) { sum[r.status]++; }
        var tag = (r.status || 'info').toUpperCase();
        block.push('  [' + tag + '] ' + r.label + ' — ' + r.msg);
        if ((r.status === 'fail' || r.status === 'warn') && r.detail) {
          block.push('         ' + r.detail);
        }
        if ((r.status === 'fail' || r.status === 'warn') && r.fix) {
          block.push('         → ' + r.fix);
        }
      }
      if (block.length) {
        lines.push('');
        lines.push(label ? label.textContent : '');
        lines = lines.concat(block);
      }
    }

    lines.push('');
    lines.push('──────────────────────────────');
    lines.push('جمع: ' + sum.total + ' بررسی — ' + sum.pass + ' سالم، ' +
      sum.fail + ' خطا، ' + sum.warn + ' هشدار');
    lines.push('etehadwp.com');
    return lines.join('\n');
  }

  function renderReport() {
    var ta = el('psb-hc-report');
    if (ta && results.length) { ta.value = buildReport(); }
  }

  /** اجرای ترتیبی یک فهرست از شناسه‌ها. */
  function runList(ids, done) {
    var i = 0;
    function step() {
      if (i >= ids.length) {
        if (done) { done(); }
        return;
      }
      var id = ids[i++];
      markPending(id);
      fetchOne(id).then(function (r) {
        results.push(r);
        setItem(r.id || id, r);
        countDone();
        step();
      });
    }
    step();
  }

  function collectIds(scope) {
    var items = (scope || document).querySelectorAll('.psb-hc-item');
    var ids = [];
    for (var i = 0; i < items.length; i++) {
      ids.push(items[i].getAttribute('data-check'));
    }
    return ids;
  }

  function copyReport() {
    var ta = el('psb-hc-report');
    var btn = el('psb-hc-copy');
    if (!ta || !ta.value) { return; }
    var flash = function (txt) {
      if (!btn) { return; }
      var old = btn.textContent;
      btn.textContent = txt;
      setTimeout(function () { btn.textContent = old; }, 1800);
    };
    ta.select();
    ta.setSelectionRange(0, ta.value.length);
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(ta.value).then(function () {
        flash(T.copied || 'کپی شد');
      }, function () {
        try {
          document.execCommand('copy');
          flash(T.copied || 'کپی شد');
        } catch (e) {
          flash(T.copyFail || 'کپی نشد');
        }
      });
    } else {
      try {
        document.execCommand('copy');
        flash(T.copied || 'کپی شد');
      } catch (e) {
        flash(T.copyFail || 'کپی نشد');
      }
    }
  }

  function init() {
    if (!document.getElementById('psb-hc-groups')) { return; }

    var run = el('psb-hc-run');
    if (run) {
      run.addEventListener('click', function () {
        if (running) { return; }
        results = [];
        var ta = el('psb-hc-report');
        if (ta) { ta.value = ''; }
        setBusy(true);
        runList(collectIds(document), function () {
          setBusy(false);
          renderReport();
        });
      });
    }

    var copy = el('psb-hc-copy');
    if (copy) { copy.addEventListener('click', copyReport); }

    var groupBtns = document.querySelectorAll('.psb-hc-grun');
    for (var i = 0; i < groupBtns.length; i++) {
      groupBtns[i].addEventListener('click', function () {
        if (running) { return; }
        var gid = this.getAttribute('data-group');
        var group = document.querySelector('.psb-hc-group[data-group="' + gid + '"]');
        if (!group) { return; }
        setBusy(true);
        runList(collectIds(group), function () {
          setBusy(false);
          renderReport();
        });
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

/* راهنمای درون‌صفحه‌ای همهٔ فیلدها — بدون وابستگی به jQuery */
(function () {
  'use strict';
  function addFieldHelp() {
    var rows = document.querySelectorAll('.psb-set-row');
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      var controls = row.querySelectorAll('input, select, textarea');
      if (!controls.length) continue;
      var labels = row.querySelectorAll(':scope > label:not(.psb-help-ready), .psb-set-mini:not(.psb-help-ready)');
      for (var j = 0; j < labels.length; j++) {
        var label = labels[j];
        if (label.classList.contains('psb-set-switch') || label.classList.contains('psb-set-pick')) continue;
        label.classList.add('psb-help-ready');
        var text = (label.textContent || '').replace(/[؟?]/g, '').replace(/\s+/g, ' ').trim();
        if (!text) continue;
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'psb-field-help';
        button.setAttribute('aria-label', 'راهنمای ' + text);
        button.setAttribute('data-help', text);
        button.textContent = '?';
        label.appendChild(button);
        button.addEventListener('click', function (event) {
          event.preventDefault(); event.stopPropagation();
          var old = document.querySelector('.psb-help-popover');
          if (old) old.remove();
          var pop = document.createElement('div');
          pop.className = 'psb-help-popover';
          pop.setAttribute('role', 'dialog');
          pop.innerHTML = '<button type="button" class="psb-help-close" aria-label="بستن">×</button><strong>راهنمای این گزینه</strong><p>این گزینه برای تنظیم «' + this.getAttribute('data-help') + '» است. مقدار مناسب را انتخاب کنید و در پایان روی «ذخیرهٔ تنظیمات» بزنید.</p>';
          document.body.appendChild(pop);
          var r = this.getBoundingClientRect();
          var left = Math.max(12, Math.min(window.innerWidth - 332, r.left - 300));
          pop.style.top = Math.max(12, r.bottom + 8) + 'px'; pop.style.left = left + 'px';
          pop.querySelector('.psb-help-close').addEventListener('click', function () { pop.remove(); });
          setTimeout(function () { document.addEventListener('click', function close(e) { if (!pop.contains(e.target) && e.target !== button) { pop.remove(); document.removeEventListener('click', close); } }); }, 0);
        });
      }
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addFieldHelp); else addFieldHelp();
})();
