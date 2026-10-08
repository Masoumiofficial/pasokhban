/* =========================================================================
   پاسخ‌بان — اینباکس گفتگوها (پیشخوان وردپرس)
   بدون وابستگی به jQuery.
   ========================================================================= */
(function () {
  'use strict';

  var C = window.PASOKHBAN_INBOX || { restUrl: '/wp-json/pasokhban/v1', nonce: '', i18n: {} };
  var REST = String(C.restUrl || '').replace(/\/$/, '');
  var I = C.i18n || {};

  var state = {
    current: 0,
    session: null,
    lastId: 0,
    listTimer: null,
    msgTimer: null,
    beatTimer: null,
    typingTimer: null,
    filter: 'all',   // پیش‌فرض «همه» — تا چیزی پشت فیلتر پنهان نماند
    search: '',
    owner: 'all'     // 'me' | 'unassigned' | 'all'
  };

  function $(sel) { return document.querySelector(sel); }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function api(path, body, method) {
    var opt = {
      method: method || 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce || '' }
    };
    if (body) opt.body = JSON.stringify(body);

    return fetch(REST + path, opt).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok) {
          var e = new Error((d && d.message) || 'http ' + r.status);
          e.detail = d;
          throw e;
        }
        return d;
      });
    });
  }

  /* =========================================================
     لیست مکالمات
  ========================================================= */

  /**
   * حروف اول نام برای آواتار — دقیقاً همان الگوریتم سمت PHP، تا آیتم‌های
   * رندرشده با JS و با PHP یکی به نظر برسند.
   */
  function initials(name) {
    name = String(name || '').trim();
    if (!name) return '؟';
    var parts = name.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0].charAt(0) + parts[1].charAt(0));
    return parts[0].slice(0, 2);
  }

  /** رنگ آواتار از hash نام — همان hsl سمت PHP. */
  function nameTint(name) {
    var str = String(name || '');
    var h = 0;
    for (var i = 0; i < str.length; i++) {
      h = ((h << 5) - h + str.charCodeAt(i)) | 0;
    }
    // md5 سمت PHP و این hash یکی نیستند، ولی هر دو پایدارند و هدف فقط
    // «هر بازدیدکننده رنگ ثابت خودش» است، نه یکی‌بودن دقیق دو طرف.
    return 'hsl(' + (Math.abs(h) % 360) + ', 62%, 52%)';
  }

  function itemHtml(s) {
    var name = s.name || I.visitor || 'بازدیدکننده';
    var full = (name + ' ' + (s.family || '')).trim();
    var unread = Number(s.unreadAgent || 0);

    var mode = (s.mode === 'agent')
      ? '<span class="ei-tag agent">' + esc(I.you || 'اپراتور') + '</span>'
      : '<span class="ei-tag ai">AI</span>';
    var closed = (s.status === 'closed') ? '<span class="ei-tag closed">بسته</span>' : '';
    var assigned = s.assignedName ? '<span class="ei-tag mine">' + esc(s.assignedName) + '</span>' : '';
    var phone = s.phone ? '<span class="ei-ico" title="شمارهٔ تماس دارد">📞</span>' : '';
    var badge = unread > 0 ? '<span class="ei-badge">' + unread + '</span>' : '';
    var time = String(s.updatedAt || '').slice(11) || '';

    return '' +
      '<button type="button" class="ei-item' + (s.id === state.current ? ' active' : '') +
        (unread > 0 ? ' is-unread' : '') + '" data-id="' + s.id + '">' +
        '<span class="ei-ava" style="--ei-ava: ' + esc(nameTint(full)) + '">' + esc(initials(full)) + '</span>' +
        '<span class="ei-item-body">' +
          '<span class="ei-item-top">' +
            '<span class="ei-item-name">' + esc(full) + '</span>' +
            '<span class="ei-item-time">' + esc(time) + '</span>' +
          '</span>' +
          '<span class="ei-item-msg">' + esc(s.lastMessage || '') + '</span>' +
          '<span class="ei-item-meta">' + mode + closed + assigned + phone + badge + '</span>' +
        '</span>' +
      '</button>';
  }

  function loadList(keepActive) {
    var q = '/admin/sessions?status=' + encodeURIComponent(state.filter) +
            '&per_page=40&page=1' +
            (state.owner ? '&assigned=' + encodeURIComponent(state.owner) : '') +
            (state.search ? '&search=' + encodeURIComponent(state.search) : '');

    return api(q, null, 'GET').then(function (d) {
      var items = d.items || [];
      paintOnline(d.online);
      var box = $('#ei-items');
      if (!box) return;

      box.innerHTML = items.length
        ? items.map(itemHtml).join('')
        : '<div class="ei-empty-list">' + esc(I.noSession || '—') + '</div>';

      var shown = $('#ei-shown'), total = $('#ei-total');
      if (shown) shown.textContent = String(items.length);
      if (total) total.textContent = String(d.total || 0);

      if (!keepActive && state.current) {
        var still = items.filter(function (s) { return s.id === state.current; })[0];
        if (!still) closeThread();
      }
    }).catch(function (e) {
      var box = $('#ei-items');
      if (box) box.innerHTML = '<div class="ei-empty-list ei-err">' +
        esc(I.errLoad || 'خطا') + '<br><code>' + esc(e.message) + '</code></div>';
    });
  }

  /* =========================================================
     رشتهٔ گفتگو
  ========================================================= */

  function openThread(id) {
    state.current = id;
    state.lastId = 0;

    var items = document.querySelectorAll('.ei-item');
    for (var i = 0; i < items.length; i++) items[i].classList.remove('active');
    var act = document.querySelector('.ei-item[data-id="' + id + '"]');
    if (act) act.classList.add('active');

    $('#ei-empty').style.display = 'none';
    $('#ei-thead').hidden = false;
    $('#ei-composer').hidden = false;
    var cn = $('#ei-canned');
    if (cn) cn.hidden = false;
    // نوار دستیار اپراتور هم با کادر پاسخ می‌آید؛ و خروجی قبلی پاک می‌شود
    // تا خلاصهٔ مکالمهٔ پیشین به اشتباه جای این یکی ننشیند.
    var cp = $('#ei-copilot');
    if (cp) { cp.hidden = false; }
    hideCopilotOut();
    lastDay = '';
    $('#ei-msgs').innerHTML = '<div class="ei-empty-list">…</div>';

    api('/admin/session?id=' + id, null, 'GET').then(function (d) {
      state.session = d.session;
      state.lastId = 0;
      paintHeader(d.session);
      var box = $('#ei-msgs');
      box.innerHTML = '';
      (d.messages || []).forEach(paintMessage);
      scrollMsgs();
      startMsgPoll();
    }).catch(function (e) {
      $('#ei-msgs').innerHTML = '<div class="ei-empty-list ei-err">' +
        esc(I.errOpen || 'خطا') + '<br><code>' + esc(e.message) + '</code></div>';
    });

    api('/admin/read', { id: id }).then(function () { loadList(true); }).catch(function () {});
  }

  function closeThread() {
    state.current = 0;
    state.session = null;
    stopMsgPoll();
    $('#ei-thead').hidden = true;
    $('#ei-composer').hidden = true;
    var cn = $('#ei-canned');
    if (cn) cn.hidden = true;
    var cp0 = $('#ei-copilot');
    if (cp0) { cp0.hidden = true; }
    hideCopilotOut();
    $('#ei-msgs').innerHTML = '';
    $('#ei-empty').style.display = '';
    var act = document.querySelector('.ei-item.active');
    if (act) act.classList.remove('active');
  }

  /**
   * نوار «چه کسانی آنلاین‌اند».
   *
   * @param {Array} names
   */
  function paintOnline(names) {
    var box = $('#ei-online');
    if (!box) return;
    var list = (names || []).filter(function (n) { return !!n; });
    if (!list.length) { box.hidden = true; box.textContent = ''; return; }
    box.hidden = false;
    box.textContent = (I.onlineNow || 'آنلاین:') + ' ' + list.join('، ');
  }

  function paintHeader(s) {
    if (!s) return;
    var fullName = s.name || '';
    if (s.family) fullName = (fullName ? fullName + ' ' : '') + s.family;
    $('#ei-tname').textContent = fullName || (I.visitor || 'بازدیدکننده');
    var tc = $('#ei-tcontact');
    if (tc) {
      var bits = [];
      if (s.phone) bits.push('📞 ' + s.phone);
      if (s.email) bits.push('✉️ ' + s.email);
      tc.textContent = bits.join('  ·  ');
    }
    var where = (s.currentUrl || s.entryUrl || '').replace(/^https?:\/\//, '').slice(0, 70);
    $('#ei-tsub').textContent = where + (s.ip ? ' • ' + s.ip : '');
    $('#ei-mode').checked = (s.mode === 'agent');
    $('#ei-close').textContent = (s.status === 'closed')
      ? (I.reopen || 'بازکردن گفتگو')
      : (I.openChat || 'بستن گفتگو');

    // select واگذاری را روی وضعیت فعلی بگذار. بدون این، بعد از باز کردن
    // هر مکالمه همان انتخاب مکالمهٔ قبلی نمایش داده می‌شد.
    var asg = $('#ei-assign');
    if (asg) asg.value = String(s.assignedTo || 0);
  }

  /** روزِ آخرین پیامی که رندر شده — برای جداکنندهٔ روز. */
  var lastDay = '';

  /**
   * اگر پیام مالِ روز تازه‌ای است، یک جداکنندهٔ روز بگذار.
   *
   * کلید روز از فیلد time (ISO) گرفته می‌شود، نه از ago — چون ago نسبی
   * است و «۳ روز پیش» فردا دیگر درست نیست.
   */
  function maybeDaySep(m) {
    var t = String(m.time || '');
    var day = t.slice(0, 10);
    if (!day || day === lastDay) return;
    lastDay = day;
    var box = $('#ei-msgs');
    if (!box) return;
    var sep = document.createElement('div');
    sep.className = 'ei-daysep';
    sep.textContent = formatDay(t);
    box.appendChild(sep);
  }

  function paintMessage(m) {
    maybeDaySep(m);
    var who = m.sender === 'visitor' ? (I.visitor || 'بازدیدکننده')
      : (m.sender === 'system' ? (I.system || 'سیستم')
        : ((m.meta && m.meta.via === 'ai') ? (I.ai || 'هوش مصنوعی') : (I.you || 'شما')));

    var srcs = '';
    if (m.meta && m.meta.sources && m.meta.sources.length) {
      srcs = '<span class="srcs"><b>' + esc(I.sources || 'منابع:') + '</b>';
      m.meta.sources.forEach(function (s) {
        srcs += '<a href="' + esc(s.url) + '" target="_blank" rel="noopener">' + esc(s.title) + '</a>';
      });
      srcs += '</span>';
    }

    var el = document.createElement('div');
    el.className = 'ei-m ' + esc(m.sender);
    el.innerHTML =
      (m.sender === 'system' ? '' : '<span class="who">' + esc(who) + '</span>') +
      esc(m.content) + srcs +
      '<span class="ago">' + esc(m.ago || '') + '</span>';

    // ضمیمه‌ها: تصویرها بندانگشتی، بقیه لینک دانلود.
    // همهٔ مقدارها از سرور می‌آیند ولی باز هم esc می‌شوند.
    if (m.meta && m.meta.attachments && m.meta.attachments.length) {
      var wrap = document.createElement('div');
      wrap.className = 'ei-atts';
      m.meta.attachments.forEach(function (a) {
        if (!a || !a.url) return;
        var isImg = a.mime && String(a.mime).indexOf('image/') === 0;
        var link = document.createElement('a');
        link.className = 'ei-att' + (isImg ? ' is-img' : '');
        link.href = String(a.url);
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        if (isImg) {
          var img = document.createElement('img');
          img.src = String(a.url);
          img.alt = a.name || '';
          img.setAttribute('loading', 'lazy');
          link.appendChild(img);
        } else {
          link.innerHTML = '<b>' + esc(a.name || 'file') + '</b>' +
            '<small>' + esc(fmtBytes(a.size)) + '</small>';
        }
        wrap.appendChild(link);
      });
      if (wrap.childNodes.length) el.appendChild(wrap);
    }

    var box = $('#ei-msgs');
    var ph = box.querySelector('.ei-empty-list');
    if (ph) ph.parentNode.removeChild(ph);
    box.appendChild(el);

    if (m.id > state.lastId) state.lastId = m.id;
  }

  /**
   * قالب خوانای روز برای جداکننده.
   *
   * «امروز» و «دیروز» را تشخیص می‌دهد؛ برای بقیه، تاریخ میلادی را با
   * نام ماه نشان می‌دهد. شمسی‌سازی سمت سرور انجام می‌شود (فیلد time
   * همان ISO است که سرور داده).
   */
  function formatDay(iso) {
    var d = new Date(String(iso));
    if (isNaN(d.getTime())) return String(iso).slice(0, 10);
    var today = new Date();
    var same = function (a, b) {
      return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    };
    var y = new Date(today.getTime() - 86400000);
    if (same(d, today)) return I.today || 'امروز';
    if (same(d, y)) return I.yesterday || 'دیروز';
    return d.toLocaleDateString('fa-IR', { year: 'numeric', month: 'long', day: 'numeric' });
  }

  function fmtBytes(b) {
    var n = Number(b) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return Math.round(n / 1024) + ' KB';
    return (Math.round((n / 1048576) * 10) / 10) + ' MB';
  }

  function scrollMsgs() {
    var el = $('#ei-msgs');
    if (el) el.scrollTop = el.scrollHeight;
  }

  function startMsgPoll() {
    stopMsgPoll();
    state.msgTimer = setInterval(pollMsgs, 4000);
  }
  function stopMsgPoll() {
    if (state.msgTimer) { clearInterval(state.msgTimer); state.msgTimer = null; }
  }

  function pollMsgs() {
    if (!state.current || document.hidden) return;
    api('/admin/session?id=' + state.current, null, 'GET').then(function (d) {
      var added = false;
      (d.messages || []).forEach(function (m) {
        if (m.id > state.lastId) { paintMessage(m); added = true; }
      });
      if (d.session) { state.session = d.session; paintHeader(d.session); }
      if (added) scrollMsgs();
    }).catch(function () {});
  }

  /* =========================================================
     پاسخ
  ========================================================= */

  function sendReply() {
    var input = $('#ei-input');
    var btn = $('#ei-send');
    var text = (input.value || '').trim();
    if (!text || !state.current) return;

    btn.disabled = true;
    btn.textContent = I.sending || '…';

    api('/admin/reply', { id: state.current, content: text }).then(function (d) {
      input.value = '';
      if (d.message) paintMessage(d.message);
      scrollMsgs();
      $('#ei-mode').checked = true;
      loadList(true);
    }).catch(function () {
      window.alert(I.errSend || 'خطا در ارسال');
    }).then(function () {
      btn.disabled = false;
      btn.textContent = I.send || 'ارسال';
    });
  }

  /* =========================================================
     دستیار اپراتور (۲.۱.۰)
     پیش‌نویس فقط داخل کادر ورودی می‌نشیند؛ هرگز خودکار ارسال نمی‌شود.
  ========================================================= */
  var copilotBusy = false;

  function setCopilotBusy(on, label) {
    copilotBusy = on;
    ['ei-suggest', 'ei-summarize'].forEach(function (id) {
      var b = document.getElementById(id);
      if (!b) return;
      if (on) {
        b.dataset.orig = b.dataset.orig || b.textContent;
        b.disabled = true;
      } else {
        b.disabled = false;
        if (b.dataset.orig) b.textContent = b.dataset.orig;
      }
    });
    var btn = document.getElementById('ei-suggest');
    if (on && label && btn) btn.textContent = label;
  }

  /**
   * نمایش خروجی دستیار.
   *
   * عمداً «متن خالص» می‌گیرد نه HTML. نسخهٔ قبلی innerHTML می‌گرفت و همهٔ
   * فراخوان‌ها مجبور بودند خودشان esc کنند — الگویی شکننده که با یک
   * فراموشی در فراخوان بعدی به XSS تبدیل می‌شد. حالا esc اینجا انجام
   * می‌شود و فراخوان فقط متن می‌دهد.
   *
   * @param {string} text متن خالص (بدون HTML)
   * @param {string} cls  is-ok | is-err | is-sum
   * @param {string} mark پیشوند نمایشی مثل ✅ یا ❌
   */
  function copilotOut(text, cls, mark) {
    var box = document.getElementById('ei-copilot-out');
    if (!box) return;
    box.className = 'ei-copilot-out' + (cls ? ' ' + cls : '');
    box.textContent = (mark ? mark + ' ' : '') + String(text == null ? '' : text);
    box.hidden = false;
  }

  function hideCopilotOut() {
    var box = document.getElementById('ei-copilot-out');
    if (box) { box.hidden = true; box.innerHTML = ''; }
  }

  function runCopilot(path, extra, label) {
    if (!state.current || copilotBusy) return;

    setCopilotBusy(true, label);
    hideCopilotOut();

    api(path, Object.assign({ id: state.current }, extra || {}))
      .then(function (d) {
        var text = String((d && d.text) || '').trim();
        if (!text) {
          copilotOut(I.errCopilot || 'خطا', 'is-err', '❌');
          return;
        }

        if (path.indexOf('summarize') > -1) {
          // خلاصه برای خودِ اپراتور است، نه برای فرستادن به مشتری
          copilotOut(text, 'is-sum');
          return;
        }

        // پیش‌نویس: داخل کادر ورودی می‌نشیند تا ویرایش شود.
        // اگر اپراتور چیزی تایپ کرده، پاکش نمی‌کنیم — ته متن اضافه می‌شود.
        var inp = $('#ei-input');
        if (inp) {
          inp.value = inp.value.trim() ? (inp.value.replace(/\s+$/, '') + '\n\n' + text) : text;
          inp.dispatchEvent(new Event('input', { bubbles: true }));
          inp.focus();
        }
        copilotOut(I.inserted || 'پیش‌نویس در کادر ورودی قرار گرفت.', 'is-ok', '✅');
      })
      .catch(function (e) {
        copilotOut((e && e.message) ? e.message : (I.errCopilot || 'خطا'), 'is-err', '❌');
      })
      .then(function () { setCopilotBusy(false); });
  }

  /* =========================================================
     راه‌اندازی
  ========================================================= */

  function init() {
    if (!$('#ei-app')) return;

    // لیست از قبل سمت سرور رندر شده؛ فقط رویدادها را وصل می‌کنیم.
    $('#ei-items').addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.ei-item') : null;
      if (btn) {
        openThread(parseInt(btn.getAttribute('data-id'), 10));
        // در موبایل که فقط یک ستون جا می‌شود، به ستون مکالمه برو
        var app = $('#ei-app');
        if (app) app.classList.add('is-thread-open');
      }
    });

    // دکمهٔ «بازگشت به لیست» در موبایل
    var back = document.createElement('button');
    back.type = 'button';
    back.className = 'button ei-back';
    back.textContent = '→ ' + (I.back || 'بازگشت به لیست');
    back.style.display = 'none';
    var thead = $('#ei-thead');
    if (thead) thead.insertBefore(back, thead.firstChild);
    back.addEventListener('click', function () {
      var app = $('#ei-app');
      if (app) app.classList.remove('is-thread-open');
    });
    // فقط در عرض موبایل نشان داده شود
    function syncBack() {
      back.style.display = (window.innerWidth <= 860) ? '' : 'none';
    }
    syncBack();
    window.addEventListener('resize', syncBack);

    $('#ei-filter').addEventListener('change', function () {
      state.filter = this.value;
      loadList();
    });

    // فیلتر «مکالمات من / واگذارنشده / همه»
    var own = $('#ei-owner');
    if (own) {
      // پیش‌فرض: اگر محدودسازی روشن باشد «مکالمات من»، وگرنه «همه»
      own.value = 'all';
      state.owner = own.value;
      own.addEventListener('change', function () {
        state.owner = this.value;
        loadList();
      });
    }

    // واگذاری دستی مکالمه
    var asg = $('#ei-assign');
    if (asg) {
      asg.addEventListener('change', function () {
        if (!state.current) return;
        var to = parseInt(this.value, 10) || 0;
        asg.disabled = true;
        api('/admin/assign', { id: state.current, user_id: to })
          .then(function (d) {
            copilotOut(to ? (I.assigned || 'واگذار شد') + ': ' + (d.assignedName || '') : (I.unassignedLbl || 'لغو شد'), 'is-ok', '✅');
            loadList(true);
          })
          .catch(function (e) {
            copilotOut((e && e.message) ? e.message : (I.errAssign || 'خطا'), 'is-err', '❌');
          })
          .then(function () { asg.disabled = false; });
      });
    }

    var searchTimer = null;
    $('#ei-search').addEventListener('input', function () {
      var v = this.value;
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () { state.search = v; loadList(true); }, 350);
    });

    $('#ei-refresh').addEventListener('click', function () { loadList(true); });

    // پاسخ‌های آماده: متن را داخل کادر ورودی می‌گذارد
    var canned = $('#ei-canned');
    if (canned) {
      canned.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('button[data-text]') : null;
        if (!btn) return;
        var inp = $('#ei-input');
        inp.value = btn.getAttribute('data-text');
        inp.focus();
      });
    }

    var sug = $('#ei-suggest');
    if (sug) {
      sug.addEventListener('click', function () {
        var tone = $('#ei-tone');
        runCopilot('/admin/suggest', { tone: tone ? tone.value : 'friendly' }, I.thinking || '…');
      });
    }
    var sum = $('#ei-summarize');
    if (sum) {
      sum.addEventListener('click', function () {
        runCopilot('/admin/summarize', {}, I.summarizing || '…');
      });
    }

    var ogo = $('#ei-order-go');
    if (ogo) {
      ogo.addEventListener('click', function () {
        var v = ($('#ei-order-id').value || '').trim();
        if (!v) { $('#ei-order-id').focus(); return; }
        ogo.disabled = true;
        api('/admin/order', { order_id: v })
          .then(function (d) {
            var o = d && d.order;
            if (!o) { copilotOut(I.errCopilot || 'خطا', 'is-err', '❌'); return; }
            var rows = [
              [I.ordStatus || 'وضعیت', o.status],
              [I.ordDate || 'تاریخ', o.date],
              [I.ordTotal || 'مبلغ کل', o.total],
              [I.ordShip || 'روش ارسال', o.shipping],
              [I.ordCity || 'شهر مقصد', o.city]
            ];
            var h = '<b>' + esc(I.ordTitle || 'سفارش') + ' #' + esc(o.number) + '</b>';
            rows.forEach(function (r) { if (r[1]) h += '\n' + esc(r[0]) + ': ' + esc(r[1]); });
            if (o.items && o.items.length) {
              h += '\n' + esc(I.ordItems || 'اقلام') + ': ' +
                esc(o.items.map(function (i) { return i.name + ' ×' + i.qty; }).join('، '));
            }
            copilotOut(h, 'is-sum');
          })
          .catch(function (e) {
            copilotOut((e && e.message) ? e.message : (I.errCopilot || 'خطا'), 'is-err', '❌');
          })
          .then(function () { ogo.disabled = false; });
      });
    }

    $('#ei-send').addEventListener('click', sendReply);
    $('#ei-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendReply(); }
    });

    $('#ei-input').addEventListener('input', function () {
      if (!state.current) return;
      api('/admin/typing', { id: state.current, typing: 1 }).catch(function () {});
      clearTimeout(state.typingTimer);
      state.typingTimer = setTimeout(function () {
        api('/admin/typing', { id: state.current, typing: 0 }).catch(function () {});
      }, 3500);
    });

    $('#ei-mode').addEventListener('change', function () {
      if (!state.current) return;
      var mode = this.checked ? 'agent' : 'ai';
      api('/admin/mode', { id: state.current, mode: mode }).then(function () {
        loadList(true);
        pollMsgs();
      }).catch(function () {});
    });

    $('#ei-close').addEventListener('click', function () {
      if (!state.current || !state.session) return;
      var next = state.session.status === 'closed' ? 'open' : 'closed';
      api('/admin/status', { id: state.current, status: next }).then(function () {
        state.session.status = next;
        paintHeader(state.session);
        pollMsgs();
        loadList(true);
      }).catch(function () {});
    });

    $('#ei-delete').addEventListener('click', function () {
      if (!state.current) return;
      if (!window.confirm(I.confirm || 'حذف شود؟')) return;
      api('/admin/delete', { id: state.current }).then(function () {
        closeThread();
        loadList();
      }).catch(function () {});
    });

    // تازه‌سازی زنده + ضربان قلب «اپراتور آنلاین»
    state.listTimer = setInterval(function () { loadList(true); }, 9000);
    state.beatTimer = setInterval(function () {
      api('/admin/heartbeat').catch(function () {});
    }, 12000);
    api('/admin/heartbeat').catch(function () {});

    window.addEventListener('beforeunload', function () {
      if (navigator.sendBeacon && state.current) {
        try {
          navigator.sendBeacon(
            REST + '/admin/typing',
            new Blob([JSON.stringify({ id: state.current, typing: 0 })], { type: 'application/json' })
          );
        } catch (e) { /* نادیده */ }
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
