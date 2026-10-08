/* =========================================================================
   پاسخ‌بان — چت آنلاین شیشه‌ای (فرانت‌اند)
   حباب شناور در همهٔ صفحات + پنل گلس iOS + مغزِ در حال تفکر
   نسخه ۱.۱.۰
   ========================================================================= */
(function () {
  'use strict';

  var root = document.getElementById('pasokhban-live');
  if (!root) return;

  var CFG = window.PASOKHBAN_CHAT || { restUrl: '/wp-json/pasokhban/v1', nonce: '', lang: 'fa' };
  var REST = String(CFG.restUrl || '').replace(/\/$/, '');

  var LS = {
    key: 'pasokhban_live_key',
    open: 'pasokhban_live_open',
    theme: 'pasokhban_live_theme',
    auto: 'pasokhban_live_auto',
    muted: 'pasokhban_live_muted'
  };

  /* ---------- زبان ---------- */
  var I18N = {
    fa: {
      open: 'باز کردن گفتگو', close: 'بستن گفتگو',
      thinking: 'در حال تفکر', agentTyping: 'پشتیبان در حال نوشتن',
      online: 'آنلاین', ai: 'دستیار هوشمند', agent: 'پشتیبانی',
      send: 'ارسال', placeholder: 'پیامت را بنویس…',
      greeting: 'سلام! 👋 چطور می‌تونیم کمکت کنیم؟',
      sources: 'منابع:', powered: 'پاسخ‌بان',
      theme: 'حالت روشن/تاریک', human: 'گپ با پشتیبان',
      maximize: 'بزرگ‌نمایی', restore: 'اندازهٔ عادی',
      prechatTitle: 'خوش آمدی 👋', pcName: 'نام', pcFamily: 'نام خانوادگی', pcPhone: 'شمارهٔ تماس',
      pcGo: 'شروع گفتگو', pcSkip: 'بی‌خیال، فقط می‌خواهم بپرسم',
      pcNeedAll: 'لطفاً نام، نام خانوادگی و شمارهٔ تماس را وارد کن.', pcBadPhone: 'شمارهٔ تماس درست نیست.',
      csatAsk: 'مفید بود؟', csatGood: 'مفید بود', csatBad: 'مفید نبود', csatThanks: 'ممنون از بازخوردت 🙏',
      sourcesBtn: 'مشاهدهٔ منابع',
      stillWorking: 'هنوز در حال کار…',
      handoffWaiting: 'درخواستت به پشتیبان انسانی منتقل شد. لطفاً کمی صبر کن — به‌زودی پاسخ می‌دهیم.',
      streamFallback: 'پاسخ کمی طول کشید؛ منتظر بمان تا برسد.',
      errNet: 'ارتباط برقرار نشد. اینترنتت را چک کن و دوباره امتحان کن.',
      errGeneric: 'پاسخی دریافت نشد. دوباره امتحان کن.',
      teaser: 'سؤالی داری؟ همین‌جا بپرس 👋',
      newMsg: 'پیام تازه', unread: 'پیام خوانده‌نشده',
      queued: 'پیامت ثبت شد؛ به‌زودی یک اپراتور پاسخ می‌دهد.',
      closed: 'این گفتگو بسته شده است.',
      ordAsk: 'شمارهٔ سفارش %s را پیدا کردم. برای دیدن وضعیتش، ایمیل یا شمارهٔ تماسی که موقع خرید وارد کردی را بنویس.',
      ordPh: 'ایمیل یا شمارهٔ تماس سفارش',
      ordGo: 'نمایش وضعیت',
      ordChecking: 'در حال بررسی…',
      ordTitle: 'سفارش #%s',
      ordStatus: 'وضعیت', ordDate: 'تاریخ ثبت', ordTotal: 'مبلغ کل',
      ordItems: 'اقلام', ordShip: 'روش ارسال', ordCity: 'شهر مقصد',
      attach: 'ارسال تصویر یا فایل',
      upSending: 'در حال ارسال…',
      upTooBig: 'فایل بزرگ‌تر از حد مجاز است (حداکثر %s مگابایت).',
      upBadType: 'این نوع فایل مجاز نیست. پسوندهای مجاز: %s',
      upTooMany: 'حداکثر %s فایل در هر پیام.',
      upFailed: 'ارسال فایل ناموفق بود.',
      upRemove: 'حذف فایل'
    },
    en: {
      open: 'Open chat', close: 'Close chat',
      thinking: 'Thinking', agentTyping: 'Agent is typing',
      online: 'Online', ai: 'AI assistant', agent: 'Support',
      send: 'Send', placeholder: 'Type your message…',
      greeting: 'Hi! 👋 How can we help you today?',
      sources: 'Sources:', powered: 'Pasokhban',
      theme: 'Light/dark mode', human: 'Talk to a human',
      maximize: 'Expand', restore: 'Restore',
      prechatTitle: 'Welcome 👋', pcName: 'First name', pcFamily: 'Last name', pcPhone: 'Phone number',
      pcGo: 'Start chat', pcSkip: 'Skip — I just want to ask',
      pcNeedAll: 'Please enter your name, last name and phone number.', pcBadPhone: 'That phone number looks wrong.',
      csatAsk: 'Was this helpful?', csatGood: 'Helpful', csatBad: 'Not helpful', csatThanks: 'Thanks for the feedback 🙏',
      sourcesBtn: 'View sources',
      stillWorking: 'still working…',
      handoffWaiting: 'You have been transferred to a human agent. Please wait a moment.',
      streamFallback: 'The answer is taking a while; hang on.',
      errNet: 'Could not connect. Check your internet and try again.',
      errGeneric: 'No response received. Please try again.',
      teaser: 'Got a question? Ask right here 👋',
      newMsg: 'New message', unread: 'unread',
      queued: 'Got it — an agent will reply shortly.',
      closed: 'This conversation is closed.',
      ordAsk: 'I found order %s. To see its status, enter the email or phone number you used at checkout.',
      ordPh: 'Order email or phone',
      ordGo: 'Check status',
      ordChecking: 'Checking…',
      ordTitle: 'Order #%s',
      ordStatus: 'Status', ordDate: 'Date', ordTotal: 'Total',
      ordItems: 'Items', ordShip: 'Shipping', ordCity: 'City',
      attach: 'Send an image or file',
      upSending: 'Uploading…',
      upTooBig: 'That file is too large (max %s MB).',
      upBadType: 'That file type is not allowed. Allowed: %s',
      upTooMany: 'Up to %s files per message.',
      upFailed: 'Upload failed.',
      upRemove: 'Remove file'
    }
  };

  var S = {
    lang: CFG.lang === 'en' ? 'en' : 'fa',
    key: store(LS.key),
    open: false,
    busy: false,
    lastId: 0,
    mode: 'ai',
    status: 'open',
    unread: 0,
    pollTimer: null,
    thinkNode: null,
    typeNode: null,
    seenIds: {},
    ready: false,
    settings: null,
    started: false,
    atts: [],        // ضمیمه‌های آمادهٔ ارسال
    uploading: 0     // تعداد آپلود در جریان
  };

  var T = I18N[S.lang];

  function t() { T = I18N[S.lang]; }

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

  /* ============================================================
     آیکون‌ها
  ============================================================ */
  function icon(name) {
    var p = {
      chat: '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.9-.9L3 20.5l1.6-4.3A8.2 8.2 0 0 1 3 11.5a8.4 8.4 0 0 1 9-8.4 8.4 8.4 0 0 1 9 8.4z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/><path d="M8.6 11.5h6.8" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>',
      x: '<path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>',
      send: '<path d="M4 12l16-8-6 8 6 8-16-8z" fill="currentColor" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>',
      moon: '<path d="M20.5 14.3A8.5 8.5 0 1 1 9.7 3.5a6.6 6.6 0 0 0 10.8 10.8z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/>',
      sun: '<circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.9"/><path d="M12 2v2.6M12 19.4V22M2 12h2.6M19.4 12H22M4.9 4.9l1.9 1.9M17.2 17.2l1.9 1.9M19.1 4.9l-1.9 1.9M6.8 17.2l-1.9 1.9" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>',
      human: '<circle cx="9" cy="8" r="3.4" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M2.8 20c0-3.4 2.8-5.6 6.2-5.6s6.2 2.2 6.2 5.6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M16.4 5.2a3.2 3.2 0 0 1 0 6.1M17.6 14.8c2.2.6 3.6 2.5 3.6 5.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
      max: '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
      min: '<path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
      clip: '<path d="M20 11.5l-7.8 7.8a4.6 4.6 0 0 1-6.5-6.5l8-8a3.1 3.1 0 0 1 4.4 4.4l-8 8a1.6 1.6 0 0 1-2.2-2.2l7.3-7.3" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>',
      file: '<path d="M13.5 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M13.5 3v5.5H19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
    };
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' + (p[name] || '') + '</svg>';
  }

  /* 🧠 مغز در حال تفکر */
  function brainSvg() {
    return '' +
      '<svg class="psb-brain" viewBox="0 0 64 64" aria-hidden="true">' +
        '<path class="shell" d="M32 11c-5-4.6-13.6-3.6-15.6 1.6C11 12.6 7.6 17.4 8.8 22.2 5 25.3 4.8 32.2 9 35.2c-1 6 3.2 11 9.2 11.2C20.6 51.2 27.4 53.4 32 50.4 36.6 53.4 43.4 51.2 45.8 46.4c6-.2 10.2-5.2 9.2-11.2 4.2-3 4-9.9.2-13C56.4 17.4 53 12.6 47.6 12.6 45.6 7.4 37 6.4 32 11z"/>' +
        '<path class="mid" d="M32 12.4v37.2"/>' +
        '<path class="fold" d="M24.6 18.8c2.6 2.2 3.6 5.4 3 8.6M16.8 27.6c3 .6 5.4 2.8 6.2 5.7M21.2 40.2c2.4-1.4 5.2-1.2 7.4.6M39.4 18.8c-2.6 2.2-3.6 5.4-3 8.6M47.2 27.6c-3 .6-5.4 2.8-6.2 5.7M42.8 40.2c-2.4-1.4-5.2-1.2-7.4.6"/>' +
        '<circle class="node" cx="24.2" cy="22" r="2.1"/>' +
        '<circle class="node" cx="39.8" cy="22" r="2.1"/>' +
        '<circle class="node" cx="17.6" cy="33.2" r="1.7"/>' +
        '<circle class="node" cx="46.4" cy="33.2" r="1.7"/>' +
        '<circle class="node" cx="25.6" cy="43.2" r="1.9"/>' +
        '<circle class="node" cx="38.4" cy="43.2" r="1.9"/>' +
      '</svg>';
  }

  /* ============================================================
     ساخت DOM
  ============================================================ */
  function build() {
    var s = S.settings || {};
    var pos = s.position === 'end' ? 'end' : 'start';

    root.innerHTML =
      '<div class="psb-aura" data-pos="' + pos + '"></div>' +

      '<div class="psb-teaser" data-pos="' + pos + '" id="psb-teaser" role="status">' +
        '<b>' + esc(s.title || 'پاسخ‌بان') + '</b>' +
        esc(s.teaserText || T.teaser) +
      '</div>' +

      '<button class="psb-launcher' + (s.launcherLabel ? ' has-label' : '') + '" id="psb-launcher" data-pos="' + pos + '" ' +
        'aria-label="' + esc(T.open) + '" aria-expanded="false" aria-controls="psb-panel">' +
        '<span class="psb-lic-chat">' + icon('chat') + (s.launcherLabel ? '<span class="psb-lbl">' + esc(s.launcherLabel) + '</span>' : '') + '</span>' +
        '<span class="psb-lic-x">' + icon('x') + '</span>' +
        '<span class="psb-badge" id="psb-badge" aria-hidden="true">0</span>' +
      '</button>' +

      '<div class="psb-panel" id="psb-panel" data-pos="' + pos + '" role="dialog" ' +
        'aria-label="' + esc(s.title || 'چت') + '" aria-modal="false">' +

        '<div class="psb-head">' +
          '<div class="psb-avatar">' + brainSvg() +
            '<span class="psb-presence" id="psb-presence"></span>' +
          '</div>' +
          '<div class="psb-hmeta">' +
            '<div class="psb-htitle">' + esc(s.title || 'پاسخ‌بان') + '</div>' +
            '<div class="psb-hsub"><i id="psb-subdot"></i><span id="psb-sub">' + esc(T.ai) + '</span></div>' +
          '</div>' +
          '<div class="psb-hacts">' +
            (s.humanHandoff
              ? '<button class="psb-iconbtn" id="psb-human" title="' + esc(T.human) + '" aria-label="' + esc(T.human) + '">' + icon('human') + '</button>'
              : '') +
            (s.maximize
              ? '<button class="psb-iconbtn" id="psb-max" title="' + esc(T.maximize) + '" aria-label="' + esc(T.maximize) + '">' + icon('max') + '</button>'
              : '') +
            '<button class="psb-iconbtn" id="psb-theme" title="' + esc(T.theme) + '" aria-label="' + esc(T.theme) + '">' + icon('moon') + '</button>' +
            '<button class="psb-iconbtn" id="psb-close" title="' + esc(T.close) + '" aria-label="' + esc(T.close) + '">' + icon('x') + '</button>' +
          '</div>' +
        '</div>' +

        (s.prechat ?
        '<div class="psb-prechat" id="psb-prechat" hidden>' +
          '<div class="psb-pc-card">' +
            '<button class="psb-pc-close" id="psb-pc-close" title="' + esc(T.close) + '" aria-label="' + esc(T.close) + '">' + icon('x') + '</button>' +
            '<div class="psb-pc-ico">' + icon('human') + '</div>' +
            '<div class="psb-pc-title">' + esc(T.prechatTitle) + '</div>' +
            (s.prechatNote ? '<div class="psb-pc-note">' + esc(s.prechatNote) + '</div>' : '') +
            '<input class="psb-pc-input" id="psb-pc-name" type="text" autocomplete="given-name" placeholder="' + esc(T.pcName) + '" />' +
            '<input class="psb-pc-input" id="psb-pc-family" type="text" autocomplete="family-name" placeholder="' + esc(T.pcFamily) + '" />' +
            '<input class="psb-pc-input" id="psb-pc-phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="' + esc(T.pcPhone) + '" />' +
            '<div class="psb-pc-err" id="psb-pc-err" hidden></div>' +
            '<button class="psb-pc-go" id="psb-pc-go">' + esc(T.pcGo) + '</button>' +
            (s.prechatRequired ? '' : '<button class="psb-pc-skip" id="psb-pc-skip">' + esc(T.pcSkip) + '</button>') +
          '</div>' +
        '</div>' : '') +

        '<div class="psb-body" id="psb-body" aria-live="polite" aria-relevant="additions"></div>' +

        '<div class="psb-starters" id="psb-starters" hidden></div>' +

        '<div class="psb-atts" id="psb-atts" hidden></div>' +

        '<div class="psb-composer">' +
          (s.upload ?
            '<button class="psb-clip" id="psb-clip" type="button" title="' + esc(T.attach) + '" aria-label="' + esc(T.attach) + '">' + icon('clip') + '</button>' +
            '<input type="file" id="psb-file" multiple hidden />' : '') +
          '<textarea class="psb-input" id="psb-input" rows="1" ' +
            'placeholder="' + esc(s.placeholder || T.placeholder) + '" aria-label="' + esc(s.placeholder || T.placeholder) + '"></textarea>' +
          '<button class="psb-send" id="psb-send" title="' + esc(T.send) + '" aria-label="' + esc(T.send) + '">' + icon('send') + '</button>' +
        '</div>' +

      '</div>';

    q('#psb-launcher').addEventListener('click', toggle);
    q('#psb-close').addEventListener('click', function () { setOpen(false); });
    q('#psb-theme').addEventListener('click', cycleTheme);
    q('#psb-send').addEventListener('click', onSend);

    var input = q('#psb-input');
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); onSend(); }
    });
    input.addEventListener('input', autoGrow);

    /* ---- ارسال فایل ---- */
    var clip = q('#psb-clip');
    var fileInput = q('#psb-file');
    if (clip && fileInput) {
      fileInput.setAttribute('accept', acceptList());
      clip.addEventListener('click', function () { fileInput.value = ''; fileInput.click(); });
      fileInput.addEventListener('change', function () {
        pickFiles(fileInput.files);
        fileInput.value = '';
      });

      // چسباندن از کلیپ‌بورد (اسکرین‌شات با Ctrl+V)
      input.addEventListener('paste', function (e) {
        var items = (e.clipboardData || {}).files;
        if (items && items.length) { e.preventDefault(); pickFiles(items); }
      });

      // کشیدن و رها کردن روی کل پنل
      var panel = q('#psb-panel');
      if (panel) {
        ['dragenter', 'dragover'].forEach(function (ev) {
          panel.addEventListener(ev, function (e) {
            if (!e.dataTransfer) return;
            e.preventDefault();
            panel.classList.add('is-drop');
          });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
          panel.addEventListener(ev, function (e) {
            e.preventDefault();
            panel.classList.remove('is-drop');
          });
        });
        panel.addEventListener('drop', function (e) {
          if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            pickFiles(e.dataTransfer.files);
          }
        });
      }
    }

    var humanBtn = q('#psb-human');
    if (humanBtn) humanBtn.addEventListener('click', askHuman);

    var maxBtn = q('#psb-max');
    if (maxBtn) maxBtn.addEventListener('click', toggleMax);

    var pcGo = q('#psb-pc-go');
    if (pcGo) {
      pcGo.addEventListener('click', submitPrechat);
      var pcSkip = q('#psb-pc-skip');
      if (pcSkip) pcSkip.addEventListener('click', function () { closePrechat(); });

      // دکمهٔ بستن داخل فرم → کل پنل بسته می‌شود (نه فقط فرم)،
      // وگرنه کاربر فرم را می‌بندد و پشت آن چت خالی می‌بیند.
      var pcClose = q('#psb-pc-close');
      if (pcClose) pcClose.addEventListener('click', function (e) {
        e.stopPropagation();
        setOpen(false);
      });

      // کلیک بیرون کارت هم ببندد (فقط دسکتاپ)
      var pcWrap = q('#psb-prechat');
      if (pcWrap) pcWrap.addEventListener('click', function (e) {
        if (e.target === pcWrap && window.innerWidth > 480) setOpen(false);
      });
      ['psb-pc-name', 'psb-pc-family', 'psb-pc-phone'].forEach(function (id) {
        var f = q('#' + id);
        if (f) f.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submitPrechat(); } });
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      // اول پاپ‌آپ منابع باز را ببند، بعد خود پنل را
      var openPop = root.querySelector('.psb-srcpop:not([hidden])');
      if (openPop) {
        openPop.hidden = true;
        var ob = openPop.parentNode ? openPop.parentNode.querySelector('.psb-srcbtn') : null;
        if (ob) { ob.classList.remove('is-open'); ob.setAttribute('aria-expanded', 'false'); }
        return;
      }
      if (S.open) setOpen(false);
    });

    // در موبایل که پنل تمام‌صفحه است، دکمهٔ بازگشت سیستم هم باید ببندد
    window.addEventListener('popstate', function () {
      if (S.open) setOpen(false);
    });

    // کلیک بیرون در دسکتاپ
    document.addEventListener('click', function (e) {
      if (!S.open) return;
      if (root.contains(e.target)) return;
      if (window.innerWidth <= 480) return;
      setOpen(false);
    });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stopPoll();
      else startPoll(S.open);
    });
  }

  function q(sel) { return root.querySelector(sel); }

  /* ============================================================
     تم
  ============================================================ */
  function applyTheme(mode) {
    root.classList.remove('psb-auto', 'psb-dark', 'psb-light');
    root.classList.add('psb-' + mode);
    var btn = q('#psb-theme');
    if (btn) {
      btn.innerHTML = icon(mode === 'light' ? 'moon' : 'sun');
      btn.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false');
    }
  }

  function cycleTheme() {
    var cur = store(LS.theme) || (S.settings && S.settings.theme) || 'auto';
    var next = cur === 'auto' ? 'dark' : (cur === 'dark' ? 'light' : 'auto');
    store(LS.theme, next);
    applyTheme(next);
  }

  /* ---------- اعمال تنظیمات ظاهری به‌صورت متغیر CSS ---------- */
  function applySettings(s) {
    if (!s) return;
    var r = root.style;

    if (s.accent)       r.setProperty('--psb-accent', String(s.accent));
    if (s.offsetBottom != null) r.setProperty('--psb-off-bottom', parseInt(s.offsetBottom, 10) + 'px');
    if (s.offsetSide != null)   r.setProperty('--psb-off-side', parseInt(s.offsetSide, 10) + 'px');
    if (s.launcherSize != null) r.setProperty('--psb-launcher', parseInt(s.launcherSize, 10) + 'px');
    if (s.panelWidth != null)   r.setProperty('--psb-panel-w', parseInt(s.panelWidth, 10) + 'px');
    if (s.panelHeight != null)  r.setProperty('--psb-panel-h', parseInt(s.panelHeight, 10) + 'px');
    if (s.radius != null)       r.setProperty('--psb-r-lg', parseInt(s.radius, 10) + 'px');
    if (s.blur != null)         r.setProperty('--psb-blur-px', parseInt(s.blur, 10) + 'px');
    if (s.glassOpacity != null) r.setProperty('--psb-glass-a', (Math.max(5, Math.min(100, parseInt(s.glassOpacity, 10))) / 100).toFixed(2));

    // ورق تمام‌صفحه در موبایل فقط برای حالت ویجت معنا دارد.
    // در حالت inline (شورت‌کد) پنل باید داخل جریان صفحه بماند، وگرنه
    // قاعدهٔ موبایل با !important آن را به ورق ثابت تمام‌صفحه تبدیل می‌کرد.
    var isInline = root.dataset.mode !== 'widget';
    root.classList.toggle('sheet', !isInline && s.mobileSheet !== false);
    root.classList.toggle('is-inline', isInline);
  }

  /* ---------- بزرگ‌نمایی پنل ---------- */
  function toggleMax() {
    var on = !root.classList.contains('is-max');
    root.classList.toggle('is-max', on);
    var b = q('#psb-max');
    if (b) {
      b.innerHTML = icon(on ? 'min' : 'max');
      b.setAttribute('title', on ? T.restore : T.maximize);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    store('pasokhban_live_max', on ? '1' : '0');
    setTimeout(scrollDown, 60);
  }

  /* ============================================================
     فرم پیش از گفتگو
  ============================================================ */
  // ارقام فارسی/عربی → لاتین (جاوااسکریپت \d فقط ASCII را می‌شناسد)
  function toLatinDigits(s) {
    return String(s == null ? '' : s).replace(/[۰-۹٠-٩]/g, function (c) {
      return String('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'.indexOf(c) % 10);
    });
  }

  function prechatDone() {
    return store('pasokhban_live_contact') === '1';
  }

  function showPrechat() {
    var el = q('#psb-prechat');
    if (!el || prechatDone()) return;
    el.hidden = false;
    setTimeout(function () { var f = q('#psb-pc-name'); if (f) f.focus(); }, 300);
  }

  function closePrechat() {
    var el = q('#psb-prechat');
    if (el) el.hidden = true;
  }

  function submitPrechat() {
    var name   = (q('#psb-pc-name').value || '').trim();
    var family = (q('#psb-pc-family').value || '').trim();
    var phone  = (q('#psb-pc-phone').value || '').trim();
    var errEl  = q('#psb-pc-err');

    var needAll = !(S.settings && S.settings.prechatRequired === false);
    if (needAll && (!name || !family || !phone)) {
      errEl.textContent = T.pcNeedAll;
      errEl.hidden = false;
      return;
    }
    // شمارهٔ تماس: حداقل ۷ رقم.
    // \D در جاوااسکریپت فقط ارقام ASCII را می‌شناسد، پس اول ارقام فارسی/عربی
    // را به لاتین تبدیل می‌کنیم — وگرنه شمارهٔ فارسی همیشه رد می‌شد.
    var latin = toLatinDigits(phone);
    var digits = latin.replace(/\D/g, '');
    if (phone && digits.length < 7) {
      errEl.textContent = T.pcBadPhone;
      errEl.hidden = false;
      return;
    }
    errEl.hidden = true;
    phone = latin.replace(/[^\d+\-\s]/g, '');

    store('pasokhban_live_contact', '1');
    store('pasokhban_live_name', name);
    store('pasokhban_live_family', family);
    store('pasokhban_live_phone', phone);
    S.contact = { name: name, family: family, phone: phone };
    closePrechat();

    if (S.key) {
      api('/live/contact', { key: S.key, name: name, family: family, phone: phone })
        .catch(function () { /* بی‌صدا — گفتگو نباید متوقف شود */ });
    }
    if (!S.started) start();
    setTimeout(function () { var i = q('#psb-input'); if (i) i.focus(); }, 120);
  }

  /* ============================================================
     باز/بسته
  ============================================================ */
  function toggle() { setOpen(!S.open); }

  function setOpen(v) {
    S.open = v;
    root.classList.toggle('is-open', v);

    // بستن پنل = بستن فرم هم؛ وگرنه فرم باز می‌ماند و حالتش قاطی می‌شود
    if (!v) {
      var pc = q('#psb-prechat');
      if (pc) pc.hidden = true;
    }

    var launcher = q('#psb-launcher');
    var aura = q('.psb-aura');
    if (launcher) {
      launcher.setAttribute('aria-expanded', v ? 'true' : 'false');
      launcher.setAttribute('aria-label', v ? T.close : T.open);
    }
    if (aura) aura.classList.toggle('on', v);
    if (v) hideTeaser();

    store(LS.open, v ? '1' : '0');

    if (v) {
      setUnread(0);
      if (S.settings && S.settings.prechat && !prechatDone()) {
        showPrechat();
      } else if (!S.started) {
        start();
      }
      setTimeout(function () { var i = q('#psb-input'); if (i) i.focus(); }, 260);
      scrollDown();
      startPoll(true);
    } else {
      // polling قطع نمی‌شود، فقط کند می‌شود — وگرنه پیام اپراتور
      // وقتی پنل بسته است هرگز نمی‌رسید و badge هم روشن نمی‌شد.
      startPoll(false);
    }
  }

  /* ============================================================
     شروع / همگام‌سازی
  ============================================================ */
  function start() {
    // اگر یک درخواست در جریان است، بقیهٔ صدا‌زننده‌ها روی همان سوار می‌شوند
    // (وگرنه هر submit یک /live/start جدا می‌فرستاد).
    if (S.startPromise) return S.startPromise;
    S.started = true;

    S.startPromise = api('/live/start', {
      key: S.key || '',
      lang: S.lang,
      name: (S.contact && S.contact.name) || store('pasokhban_live_name') || '',
      family: (S.contact && S.contact.family) || store('pasokhban_live_family') || '',
      phone: (S.contact && S.contact.phone) || store('pasokhban_live_phone') || '',
      entry_url: store('pasokhban_live_entry') || location.href,
      current_url: location.href
    })
      .then(function (d) {
        if (!store('pasokhban_live_entry')) store('pasokhban_live_entry', location.href);

        S.key = d.key;
        store(LS.key, d.key);
        S.mode = d.mode || 'ai';
        S.status = d.status || 'open';
        S.settings = Object.assign({}, S.settings || {}, d.settings || {});
        S.ready = true;
        S.retries = 0;

        var msgs = d.messages || [];
        if (!msgs.length) {
          pushGreeting();
        } else {
          msgs.forEach(renderMessage);
        }

        updatePresence(d.agentOnline);
        renderStarters();
        scrollDown();
        startPoll(S.open);
      })
      .catch(function () {
        S.started = false;
        S.startPromise = null;   // اجازهٔ تلاش دوباره، ولی فقط با سقف retry در submit
        pushGreeting();
        addError(T.errNet);
      });

    return S.startPromise;
  }

  function pushGreeting() {
    if (S.greeted) return;   // greeting تکراری چاپ نشود
    S.greeted = true;
    var g = (S.settings && S.settings.greeting) || T.greeting;
    appendMsg('agent', g, { greet: true });
    S.seenIds.greet = 1;
  }

  /* ============================================================
     استعلام سفارش ووکامرس
     شمارهٔ سفارش از پیام خود کاربر پیدا می‌شود؛ تأیید هویت سمت سرور
     انجام می‌شود و اینجا فقط رابط کاربری است.
  ============================================================ */

  /** ارقام فارسی/عربی را لاتین می‌کند. */
  function toLatinDigits(s) {
    return String(s).replace(/[\u06F0-\u06F9\u0660-\u0669]/g, function (c) {
      return String(c.charCodeAt(0) & 0xF);
    });
  }

  /**
   * پیدا کردن شمارهٔ سفارش در متن.
   *
   * دو مقدار برمی‌گرداند: no برای فرستادن به سرور (ارقام لاتین) و
   * display برای نشان دادن به کاربر (همان قالبی که خودش تایپ کرده).
   * toLatinDigits جایگزینی یک‌به‌یک است، پس اندیس‌ها در هر دو رشته
   * یکی می‌مانند و می‌توان تکهٔ اصلی را با همان اندیس برداشت.
   *
   * @return {{no:string, display:string}|null}
   */
  function findOrderNo(text) {
    var t = String(text || '');
    var tl = toLatinDigits(t);
    var m = /(?:سفارش|سفارشم|سفارشات|order)\s*#?\s*(\d{2,10})/i.exec(tl);
    if (!m) m = /#\s*(\d{3,10})/.exec(tl);
    if (!m) return null;
    var at = m.index + m[0].length - m[1].length;
    return { no: m[1], display: t.substr(at, m[1].length) };
  }

  function renderOrderCard(order) {
    var body = q('#psb-body');
    var n = document.createElement('div');
    n.className = 'psb-order';

    var rows = [
      [T.ordStatus, order.status],
      [T.ordDate, order.date],
      [T.ordTotal, order.total],
      [T.ordShip, order.shipping],
      [T.ordCity, order.city]
    ];
    var html = '<div class="psb-order-h">' + esc(T.ordTitle.replace('%s', order.number)) + '</div>';
    rows.forEach(function (r) {
      if (r[1]) html += '<div class="psb-order-r"><span>' + esc(r[0]) + '</span><b>' + esc(r[1]) + '</b></div>';
    });
    if (order.items && order.items.length) {
      html += '<div class="psb-order-r is-items"><span>' + esc(T.ordItems) + '</span><b>' +
        esc(order.items.map(function (i) { return i.name + ' ×' + i.qty; }).join('، ')) + '</b></div>';
    }
    n.innerHTML = html;
    body.appendChild(n);
    scrollDown();
    return n;
  }

  /**
   * اگر قابلیت روشن باشد و هنوز برای این شماره کارت نشان نداده باشیم،
   * کارت تأیید هویت را نمایش بده.
   */
  function maybeOfferOrder(text) {
    var s = S.settings || {};
    if (!s.wooOrder) return;

    var found = findOrderNo(text);
    if (!found) return;
    var no = found.no;

    S.orderSeen = S.orderSeen || {};
    if (S.orderSeen[no]) return;
    S.orderSeen[no] = 1;

    var body = q('#psb-body');
    var n = document.createElement('div');
    n.className = 'psb-ordask';
    n.innerHTML =
      '<p>' + esc(T.ordAsk.replace('%s', found.display)) + '</p>' +
      '<div class="psb-ordask-row">' +
        '<input type="text" class="psb-ordask-in" placeholder="' + esc(T.ordPh) + '" />' +
        '<button type="button" class="psb-ordask-go">' + esc(T.ordGo) + '</button>' +
      '</div>' +
      '<div class="psb-ordask-err" hidden></div>';
    body.appendChild(n);
    scrollDown();

    var input = n.querySelector('.psb-ordask-in');
    var btn = n.querySelector('.psb-ordask-go');
    var err = n.querySelector('.psb-ordask-err');

    function go() {
      var v = (input.value || '').trim();
      if (!v) { input.focus(); return; }

      btn.disabled = true;
      btn.textContent = T.ordChecking;
      err.hidden = true;

      api('/live/order', { key: S.key, order_id: no, verify: v })
        .then(function (d) {
          if (d && d.order) renderOrderCard(d.order);
          if (n.parentNode) n.parentNode.removeChild(n);
        })
        .catch(function (e) {
          err.textContent = (e && e.userMessage) ? e.userMessage : T.errGeneric;
          err.hidden = false;
          btn.disabled = false;
          btn.textContent = T.ordGo;
        });
    }

    btn.addEventListener('click', go);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); go(); }
    });
  }

  /* ============================================================
     ارسال فایل
  ============================================================ */

  /** فهرست پسوندهای مجاز برای صفت accept (راهنمای مرورگر، نه محافظت). */
  function acceptList() {
    var s = S.settings || {};
    var exts = s.uploadTypes && s.uploadTypes.length ? s.uploadTypes : [];
    return exts.map(function (e) { return '.' + e; }).join(',');
  }

  function fmtSize(bytes) {
    var b = Number(bytes) || 0;
    if (b < 1024) return b + ' B';
    if (b < 1048576) return Math.round(b / 1024) + ' KB';
    return (Math.round((b / 1048576) * 10) / 10) + ' MB';
  }

  function extOf(name) {
    var m = /\.([a-z0-9]+)$/i.exec(String(name || ''));
    return m ? m[1].toLowerCase() : '';
  }

  /**
   * انتخاب فایل‌ها — بررسی سمت کاربر فقط برای تجربهٔ بهتر است.
   * محافظت واقعی سمت سرور انجام می‌شود.
   */
  function pickFiles(list) {
    var s = S.settings || {};
    if (!s.upload) return;

    var max = (Number(s.uploadMax) || 5) * 1048576;
    var per = Number(s.uploadMaxPer) || 4;
    var exts = (s.uploadTypes && s.uploadTypes.length) ? s.uploadTypes : [];
    var arr = Array.prototype.slice.call(list || []);

    for (var i = 0; i < arr.length; i++) {
      var f = arr[i];

      if (S.atts.length + S.uploading >= per) {
        addError(T.upTooMany.replace('%s', String(per)));
        break;
      }
      if (exts.length && exts.indexOf(extOf(f.name)) === -1) {
        addError(T.upBadType.replace('%s', exts.join(', ')));
        continue;
      }
      if (f.size > max) {
        addError(T.upTooBig.replace('%s', String(s.uploadMax || 5)));
        continue;
      }
      uploadOne(f);
    }
  }

  /**
   * آپلود یک فایل با XHR (نه fetch) چون نوار پیشرفت لازم داریم.
   */
  function uploadOne(file) {
    var chip = addChip(file);
    S.uploading++;
    updateSend();

    var fd = new FormData();
    fd.append('key', S.key || '');
    fd.append('file', file);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', REST + '/live/upload', true);
    if (CFG.nonce) xhr.setRequestHeader('X-WP-Nonce', CFG.nonce);
    xhr.withCredentials = true;

    xhr.upload.onprogress = function (e) {
      if (e.lengthComputable) setChipProgress(chip, e.loaded / e.total);
    };

    xhr.onload = function () {
      S.uploading--;
      var d = null;
      try { d = JSON.parse(xhr.responseText || '{}'); } catch (e) { d = null; }

      if (xhr.status >= 200 && xhr.status < 300 && d && d.token) {
        S.atts.push(d.attachment);
        setChipDone(chip, d.attachment);
      } else {
        removeChip(chip);
        addError((d && d.message) ? d.message : T.upFailed);
      }
      updateSend();
    };

    xhr.onerror = function () {
      S.uploading--;
      removeChip(chip);
      addError(T.upFailed);
      updateSend();
    };

    xhr.send(fd);
  }

  /* ---- چیپ‌های ضمیمهٔ در حال آماده‌سازی ---- */
  function addChip(file) {
    var box = q('#psb-atts');
    if (!box) return null;
    box.hidden = false;

    var n = document.createElement('div');
    n.className = 'psb-chip is-up';
    n.innerHTML =
      '<span class="psb-chip-ico">' + icon('file') + '</span>' +
      '<span class="psb-chip-name">' + esc(file.name || 'file') + '</span>' +
      '<span class="psb-chip-size">' + esc(fmtSize(file.size)) + '</span>' +
      '<span class="psb-chip-bar"><i style="width:0%"></i></span>';
    box.appendChild(n);
    return n;
  }

  function setChipProgress(chip, ratio) {
    if (!chip) return;
    var bar = chip.querySelector('.psb-chip-bar > i');
    if (bar) bar.style.width = Math.max(2, Math.round(ratio * 100)) + '%';
  }

  function setChipDone(chip, att) {
    if (!chip) return;
    chip.classList.remove('is-up');
    chip.classList.add('is-done');
    var bar = chip.querySelector('.psb-chip-bar');
    if (bar) bar.parentNode.removeChild(bar);

    var isImg = att && att.mime && String(att.mime).indexOf('image/') === 0;
    var ico = chip.querySelector('.psb-chip-ico');
    if (isImg && ico && att.url) {
      ico.innerHTML = '<img src="' + esc(att.url) + '" alt="" />';
      chip.classList.add('has-thumb');
    }

    var rm = document.createElement('button');
    rm.type = 'button';
    rm.className = 'psb-chip-x';
    rm.setAttribute('aria-label', T.upRemove);
    rm.innerHTML = icon('x');
    rm.addEventListener('click', function () {
      var tok = att && att.token;
      S.atts = S.atts.filter(function (a) { return a.token !== tok; });
      removeChip(chip);
      updateSend();
    });
    chip.appendChild(rm);
  }

  function removeChip(chip) {
    if (!chip || !chip.parentNode) return;
    chip.parentNode.removeChild(chip);
    var box = q('#psb-atts');
    if (box && !box.children.length) box.hidden = true;
  }

  function clearChips() {
    var box = q('#psb-atts');
    if (box) { box.innerHTML = ''; box.hidden = true; }
    S.atts = [];
  }

  /* ============================================================
     ارسال
  ============================================================ */
  function onSend() {
    var input = q('#psb-input');
    var text = (input.value || '').trim();
    // با ضمیمه، متن خالی هم مجاز است (کاربر فقط یک اسکرین‌شات می‌فرستد)
    if ((!text && !S.atts.length) || S.busy || S.uploading) return;

    input.value = '';
    autoGrow();
    submit(text);
  }

  function submit(text) {
    if (!S.ready) {
      // نشست هنوز آماده نیست. بدون سقف، اینجا حلقهٔ بی‌نهایت می‌شد:
      // هر بار start() صدا زده می‌شد و یک حباب خطا هم اضافه می‌شد.
      S.retries = (S.retries || 0) + 1;
      if (S.retries > 3) {
        S.retries = 0;
        addError(T.errNet);
        return;
      }
      start();
      setTimeout(function () { submit(text); }, 700);
      return;
    }
    S.retries = 0;
    if (S.status === 'closed') { addError(T.closed); return; }

    // جلوگیری از ارسال دوباره: دکمه، Enter، چیپ‌ها و askHuman همه اینجا می‌رسند
    if (S.busy) return;

    hideStarters();

    // ضمیمه‌ها را همین‌جا جدا کن: تا رسیدن پاسخ سرور، کاربر ممکن است
    // فایل تازه‌ای اضافه کند و آن‌ها نباید به این پیام بچسبند.
    var atts = S.atts.slice();
    var tokens = atts.map(function (a) { return a.token; });

    var node = appendMsg('visitor', text, { attachments: atts });
    S.pending = { text: text, node: node, atts: atts.length };   // تا poll همان را دوباره نسازد
    clearChips();

    // اگر در پیام شمارهٔ سفارش بود، کارت استعلام را پیشنهاد بده
    maybeOfferOrder(text);

    S.busy = true;
    updateSend();

    // حالت انسانی: به‌جای مغز، «در حال نوشتن» نشان بده
    if (S.mode === 'agent') showTyping();
    else showThinking();

    if (S.settings && S.settings.streaming && S.settings.streamUrl && !streamDisabled()) {
      sendStream(text, node, tokens);
      return;
    }

    sendNormal(text, node, tokens);
  }

  /**
   * مسیر معمولی (بدون جریان).
   */
  function sendNormal(text, node, tokens) {
    api('/live/send', { key: S.key, content: text, lang: S.lang, current_url: location.href, attachments: tokens || [] })
      .then(function (d) { finishNormal(d, node); })
      .catch(function (e) { finishError(e); });
  }

  /**
   * پایان موفق — مشترک بین مسیر معمولی و fallback جریان.
   */
  function finishNormal(d, node) {
    hideThinking();
    hideTyping();
    S.busy = false;
    updateSend();

    if (d && d.message) {
      markSeen(d.message.id);
      if (node && node.parentNode) node.setAttribute('data-mid', d.message.id);
    }

    S.mode = (d && d.mode) || S.mode;
    updatePresence(d ? d.agentOnline : false);
    updateSubtitle();

    if (d && d.error) {
      addError(d.error);
      return;
    }

    if (d && d.queued) {
      addSystem(T.queued);
      startPoll(S.open);
      return;
    }

    if (d && d.reply) {
      // ترتیب مهم است: renderMessage خودش پیام را seen علامت می‌زند،
      // پس اگر اول markSeen صدا زده شود، renderMessage همان اول
      // early return می‌کند و پاسخ هرگز نمایش داده نمی‌شود.
      renderMessage(d.reply);
      markSeen(d.reply.id);
      // پاسخ AI به سؤال خودِ کاربر «نوتیفیکیشن» نیست — او منتظرش بود.
      if (d.reply.meta && d.reply.meta.via === 'human') ping();
    }
  }

  /**
   * پایان با خطا.
   */
  function finishError(e) {
    hideThinking();
    hideTyping();
    S.busy = false;
    updateSend();
    addError((e && e.userMessage) || T.errNet);
  }

  /**
   * انتقال به پشتیبان انسانی.
   *
   * قبلاً این دکمه فقط یک پیام متنی به AI می‌فرستاد، پس هوش مصنوعی
   * هرچه دلش خواست جواب می‌داد (مثلاً «برو صفحهٔ تماس با ما») و هیچ
   * اپراتوری هم خبردار نمی‌شد. حالا یک اندپوینت اختصاصی صدا زده می‌شود
   * که حالت نشست را به agent تغییر می‌دهد و به مدیر اعلان می‌دهد.
   */
  function askHuman() {
    var btn = q('#psb-human');
    if (btn) btn.setAttribute('aria-pressed', 'true');

    // قفل: اگر درخواست در جریان است، دوباره نفرست
    if (S.handoffBusy) return;

    if (!S.ready) {
      start();
      setTimeout(askHuman, 700);
      return;
    }

    // اگر قبلاً منتقل شده، فقط یادآوری کن
    if (S.mode === 'agent') {
      addSystem(T.handoffWaiting);
      return;
    }

    S.handoffBusy = true;
    showThinking();

    api('/live/handoff', { key: S.key, lang: S.lang })
      .then(function (d) {
        S.handoffBusy = false;
        hideThinking();

        S.mode = (d && d.mode) || 'agent';
        updatePresence(d ? d.agentOnline : false);
        updateSubtitle();

        addSystem((d && d.note) || T.handoffWaiting);

        // سرور همین پیام را در دیتابیس هم ذخیره کرده و شناسه‌اش را
        // برگردانده. اگر markSeen نکنیم، poll همان را دوباره رندر
        // می‌کند و کاربر پیام را دوبار می‌بیند.
        if (d && d.message && d.message.id) markSeen(d.message.id);

        // از این پس پیام‌ها به اپراتور می‌روند نه به AI
        startPoll(S.open);
      })
      .catch(function (e) {
        S.handoffBusy = false;
        hideThinking();
        addError((e && e.userMessage) || T.errNet);
      });
  }

  /* ============================================================
     🧠 تفکر / نوشتن
  ============================================================ */
  function showThinking() {
    hideThinking();
    var n = document.createElement('div');
    n.className = 'psb-think';
    n.setAttribute('role', 'status');
    n.innerHTML = brainSvg() +
      '<span class="psb-think-txt">' + esc(T.thinking) + '…</span>' +
      '<span class="psb-think-t" aria-hidden="true"></span>';
    q('#psb-body').appendChild(n);
    S.thinkNode = n;

    // تایمر سپری‌شده: بدون آن کاربر فقط سکوت می‌بیند و فکر می‌کند خراب شده.
    var started = Date.now();
    var tick = function () {
      if (!S.thinkNode || S.thinkNode !== n) return;
      var secs = Math.floor((Date.now() - started) / 1000);
      var el = n.querySelector('.psb-think-t');
      if (el) el.textContent = secs + 's';
      // بعد از ۱۲ ثانیه به کاربر بگو هنوز کار می‌کند
      if (secs === 12 && el) el.textContent = '12s · ' + T.stillWorking;
      S.thinkTimer = setTimeout(tick, 1000);
    };
    S.thinkTimer = setTimeout(tick, 1000);

    scrollDown();
  }

  function hideThinking() {
    if (S.thinkTimer) { clearTimeout(S.thinkTimer); S.thinkTimer = null; }
    var n = S.thinkNode;
    if (!n) return;
    S.thinkNode = null;
    n.classList.add('leaving');
    // «جواب که خواست بده، بره» — بعد از انیمیشن خروج حذف می‌شود
    var done = false;
    var kill = function () {
      if (done) return;
      done = true;
      if (n.parentNode) n.parentNode.removeChild(n);
    };
    n.addEventListener('animationend', kill);
    setTimeout(kill, 420);
  }

  function showTyping() {
    hideTyping();
    var n = document.createElement('div');
    n.className = 'psb-think';
    n.setAttribute('role', 'status');
    n.innerHTML = '<span class="psb-dots"><i></i><i></i><i></i></span>' +
      '<span class="psb-think-txt">' + esc(T.agentTyping) + '…</span>';
    q('#psb-body').appendChild(n);
    S.typeNode = n;
    scrollDown();
  }

  function hideTyping() {
    var n = S.typeNode;
    if (!n) return;
    S.typeNode = null;
    n.classList.add('leaving');
    setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, 420);
  }

  /* ============================================================
     Polling
  ============================================================ */
  // وقتی پنل باز است سریع‌تر بررسی می‌کنیم، وقتی بسته است کندتر —
  // تا هم پیام اپراتور برسد و هم درخواست اضافی به سرور نزنیم.
  function startPoll(fast) {
    stopPoll();
    if (!S.key) return;
    S.pollTimer = setInterval(poll, fast ? 4000 : 8000);
    poll();
  }

  function stopPoll() {
    if (S.pollTimer) { clearInterval(S.pollTimer); S.pollTimer = null; }
  }

  function poll() {
    if (!S.key || document.hidden) return;
    // هنگام جریان، پاسخ از راه خود جریان می‌رسد؛ poll فقط مزاحم می‌شود
    if (S.streaming) return;

    api('/live/poll?key=' + encodeURIComponent(S.key) + '&after=' + S.lastId, null, 'GET')
      .then(function (d) {
        if (d.expired) { store(LS.key, null); S.key = ''; S.started = false; return; }

        var msgs = d.messages || [];
        var gotNew = false;
        var gotHuman = false;

        msgs.forEach(function (m) {
          // فقط پیامی «تازه» است که قبلاً ندیده باشیم. قبلاً این بررسی
          // نبود، پس پیام‌های تکراری هم صدای نوتیفیکیشن پخش می‌کردند.
          var alreadySeen = !!(m && m.id && S.seenIds['r' + m.id]);

          if (!alreadySeen && m.sender !== 'visitor') {
            gotNew = true;
            if (m.meta && m.meta.via === 'human') gotHuman = true;
          }

          // ترتیب مهم است: renderMessage خودش seen علامت می‌زند، پس اگر
          // اول markSeen صدا زده شود، renderMessage early return می‌کند.
          renderMessage(m);
          markSeen(m.id);
        });

        if (d.mode && d.mode !== S.mode) { S.mode = d.mode; updateSubtitle(); }
        if (d.status) S.status = d.status;
        updatePresence(d.agentOnline);

        if (d.typing && S.mode === 'agent') showTyping();
        else if (!d.typing) hideTyping();

        if (gotNew) {
          // پاسخ AI به سؤال خودِ کاربر «نوتیفیکیشن» نیست — او منتظرش بود.
          // فقط وقتی صدا بده که پنل بسته است یا اپراتور انسانی پیام داده.
          if (!S.open || gotHuman) ping();
          if (!S.open) setUnread(S.unread + 1);
        }
      })
      .catch(function () { /* سکوت — دفعهٔ بعد دوباره */ });
  }

  function markSeen(id) {
    id = parseInt(id, 10) || 0;
    if (!id) return;
    // کلید باید دقیقاً همان قالبی باشد که renderMessage بررسی می‌کند ('r'+id)،
    // وگرنه dedupe هرگز کار نمی‌کرد و پیام‌ها دوبار نمایش داده می‌شدند.
    S.seenIds['r' + id] = 1;
    if (id > S.lastId) S.lastId = id;
  }

  /* ============================================================
     رندر پیام
  ============================================================ */
  function renderMessage(m) {
    if (!m || S.seenIds['r' + m.id]) return;
    S.seenIds['r' + m.id] = 1;

    if (m.sender === 'system') { addSystem(m.content); return; }

    // پیام خودِ کاربر: submit() قبلاً یک حباب خوش‌بینانه (بدون id) ساخته.
    // اگر همان را از سرور برگرداند، به‌جای ساخت حباب دوم، id را به حباب
    // موجود می‌چسبانیم. بدون این کار هر پیام کاربر دوبار نمایش داده می‌شد.
    var atts = (m.meta && m.meta.attachments) ? m.meta.attachments : [];

    // تعداد ضمیمه هم در تطبیق لحاظ می‌شود: وگرنه دو پیام متنی خالی پشت
    // سر هم (یکی با عکس، یکی بدون) یکی‌شان گم می‌شد.
    if ('visitor' === m.sender && S.pending &&
        String(S.pending.text) === String(m.content) &&
        (S.pending.atts || 0) === atts.length) {
      var node = S.pending.node;
      S.pending = null;
      if (node) node.setAttribute('data-mid', m.id);
      return;
    }

    var viaAi = m.meta && m.meta.via === 'ai';
    appendMsg(m.sender === 'visitor' ? 'visitor' : 'agent', m.content, {
      time: m.time,
      sources: m.meta && m.meta.sources ? m.meta.sources : null,
      attachments: atts,
      viaAi: viaAi
    });
  }

  function appendMsg(who, text, o) {
    o = o || {};
    var body = q('#psb-body');
    var n = document.createElement('div');
    n.className = 'psb-msg ' + who;

    var hasText = String(text == null ? '' : text) !== '';

    // span همیشه ساخته می‌شود، حتی وقتی متن خالی است: مسیر جریان‌دار
    // حباب را با متن خالی می‌سازد و بعد همین المان را پیدا می‌کند و
    // تکه‌تکه پر می‌کند. اگر اینجا ساخته نشود، stream هیچ‌جا نمی‌نویسد.
    var html = '<span class="psb-msg-text">' + (hasText ? linkify(esc(String(text))) : '') + '</span>';

    if (o.streaming) n.classList.add('is-streaming');
    if (!hasText) n.classList.add('is-filesonly');

    if (o.time) html += '<span class="psb-time">' + esc(clock(o.time)) + '</span>';

    n.innerHTML = html;

    // ضمیمه‌ها: تصویرها بندانگشتی، بقیه کارت فایل با لینک دانلود.
    // url از سرور می‌آید (کلاینت هرگز URL نمی‌سازد) ولی باز هم با esc
    // داخل صفت می‌رود.
    if (o.attachments && o.attachments.length) {
      n.appendChild(buildAttachments(o.attachments));
    }

    // منابع بعد از innerHTML اضافه می‌شوند چون buildSources یک المان زنده
    // با هندلر کلیک می‌سازد (نه رشتهٔ HTML).
    if (o.sources && o.sources.length) {
      n.appendChild(buildSources(o.sources));
    }
    body.appendChild(n);
    scrollDown();
    return n;
  }

  /**
   * ساخت المان ضمیمه‌ها.
   *
   * فقط URLهایی که با آدرس سایت شروع می‌شوند پذیرفته می‌شوند؛ این یک
   * لایهٔ دفاعی اضافه در برابر دادهٔ خراب در دیتابیس است.
   */
  function buildAttachments(list) {
    var wrap = document.createElement('div');
    wrap.className = 'psb-attlist';

    (list || []).forEach(function (a) {
      if (!a || !a.url) return;
      if (!isSameOrigin(String(a.url))) return;

      var isImg = a.mime && String(a.mime).indexOf('image/') === 0;
      var el = document.createElement('a');
      el.className = 'psb-att' + (isImg ? ' is-img' : '');
      el.href = String(a.url);
      el.target = '_blank';
      el.rel = 'noopener noreferrer';
      el.setAttribute('download', a.name || '');

      if (isImg) {
        var img = document.createElement('img');
        img.src = String(a.url);
        img.alt = a.name || '';
        // setAttribute نه img.loading: این دو در مرورگر یکی‌اند، ولی صفت
        // صریح در هر ابزار تست DOM هم قابل بررسی است.
        img.setAttribute('loading', 'lazy');
        el.appendChild(img);
      } else {
        el.innerHTML =
          '<span class="psb-att-ico">' + icon('file') + '</span>' +
          '<span class="psb-att-meta">' +
            '<b>' + esc(a.name || 'file') + '</b>' +
            '<small>' + esc(fmtSize(a.size)) + '</small>' +
          '</span>';
      }
      wrap.appendChild(el);
    });

    return wrap;
  }

  /** آیا آدرس به همین سایت تعلق دارد؟ */
  function isSameOrigin(u) {
    if (u.indexOf('/') === 0 && u.indexOf('//') !== 0) return true;  // نسبی
    try {
      var a = document.createElement('a');
      a.href = u;
      return a.protocol === location.protocol && a.host === location.host;
    } catch (e) { return false; }
  }

  function addSystem(text) {
    // اگر همین متن را همین حالا نشان داده‌ایم، دوباره نشان نده.
    // بدون این، وقتی هم پاسخ handoff و هم poll همان پیام را می‌آوردند،
    // کاربر پیام را دوبار می‌دید.
    var body0 = q('#psb-body');
    if (body0) {
      var allSys = body0.querySelectorAll('.psb-sys');
      if (allSys.length && allSys[allSys.length - 1].textContent === String(text)) return;
    }

    var body = q('#psb-body');
    var n = document.createElement('div');
    n.className = 'psb-sys';
    n.textContent = String(text || '');
    body.appendChild(n);
    scrollDown();
  }

  function addError(text) {
    var body = q('#psb-body');
    var label = String(text || '');

    // خطای تکراری تلنبار نشود؛ همان حباب قبلی تازه می‌شود.
    var existing = body.querySelector('.psb-err');
    if (existing && existing.textContent === label) {
      clearTimeout(existing.__timer);
      existing.__timer = setTimeout(function () {
        if (existing.parentNode) existing.parentNode.removeChild(existing);
      }, 9000);
      scrollDown();
      return;
    }

    var n = document.createElement('div');
    n.className = 'psb-err';
    n.setAttribute('role', 'alert');
    n.textContent = label;
    body.appendChild(n);
    scrollDown();
    n.__timer = setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, 9000);
  }

  function clock(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    var h = d.getHours(), m = d.getMinutes();
    return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function linkify(safe) {
    return safe.replace(/(https?:\/\/[^\s<]+)/g, function (u) {
      var clean = u.replace(/[),.;!?]+$/, '');
      return '<a href="' + clean + '" target="_blank" rel="noopener nofollow">' + clean + '</a>';
    });
  }

  /* ============================================================
     دکمه‌های شروع / حضور / badge
  ============================================================ */
  function renderStarters() {
    var box = q('#psb-starters');
    var list = (S.settings && S.settings.starter) || [];
    if (!box || !list.length) { if (box) box.hidden = true; return; }

    box.innerHTML = '';
    list.forEach(function (label) {
      var b = document.createElement('button');
      b.className = 'psb-chip';
      b.type = 'button';
      b.textContent = String(label);
      b.addEventListener('click', function () { submit(String(label)); });
      box.appendChild(b);
    });
    box.hidden = false;
  }

  function hideStarters() {
    var box = q('#psb-starters');
    if (box) box.hidden = true;
  }

  function updatePresence(online) {
    S.agentOnline = !!online;
    var p = q('#psb-presence');
    var d = q('#psb-subdot');
    if (p) p.classList.toggle('online', S.agentOnline);
    if (d) d.classList.toggle('online', S.agentOnline);
    updateSubtitle();
  }

  function updateSubtitle() {
    var el = q('#psb-sub');
    if (!el) return;
    if (S.mode === 'agent') {
      el.textContent = S.agentOnline
        ? ((S.settings && S.settings.agentName) || T.agent) + ' • ' + T.online
        : ((S.settings && S.settings.agentName) || T.agent);
    } else {
      el.textContent = T.ai;
    }
  }

  function setUnread(n) {
    S.unread = Math.max(0, n | 0);
    var b = q('#psb-badge');
    if (!b) return;
    b.textContent = S.unread > 9 ? '9+' : String(S.unread);
    b.classList.toggle('on', S.unread > 0);
    if (S.unread > 0 && document.title.indexOf('(') < 0) {
      document.title = '(' + S.unread + ') ' + document.title;
    } else if (S.unread === 0) {
      document.title = document.title.replace(/^\(\d+\)\s*/, '');
    }
  }

  function updateSend() {
    var b = q('#psb-send');
    if (!b) return;
    // موقع آپلود فعال می‌ماند ولی کلیک بی‌اثر است (onSend بررسی می‌کند)
    // تا کاربر فکر نکند دکمهٔ ارسال شکسته است.
    b.disabled = !!S.busy;
    b.classList.toggle('is-wait', S.uploading > 0);
  }

  function autoGrow() {
    var i = q('#psb-input');
    if (!i) return;
    i.style.height = 'auto';
    i.style.height = Math.min(i.scrollHeight, 108) + 'px';
  }

  function scrollDown() {
    var b = q('#psb-body');
    if (b) b.scrollTop = b.scrollHeight;
  }

  /* ---------- صدای ملایم پیام تازه (بدون فایل خارجی) ---------- */
  var audioCtx = null;
  function ping() {
    if (store(LS.muted) === '1') return;
    if (!S.settings || S.settings.sound === false) return;
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      if (!audioCtx) audioCtx = new AC();
      var o = audioCtx.createOscillator();
      var g = audioCtx.createGain();
      o.type = 'sine';
      o.frequency.setValueAtTime(880, audioCtx.currentTime);
      o.frequency.exponentialRampToValueAtTime(1320, audioCtx.currentTime + 0.09);
      g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.07, audioCtx.currentTime + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.26);
      o.connect(g); g.connect(audioCtx.destination);
      o.start();
      o.stop(audioCtx.currentTime + 0.28);
    } catch (e) { /* بی‌صدا بگذر */ }
  }

  /* ============================================================
     وضعیت جریان (SSE)
     اگر روی هاست کاربر جریان چند بار پشت‌سرهم شکست بخورد، دیگر برای هر
     پیام یک درخواست اضافهٔ بی‌فایده نمی‌زنیم و مستقیم مسیر معمولی می‌رویم.
  ============================================================ */
  function streamFails() {
    return parseInt(store('pasokhban_stream_fails') || '0', 10) || 0;
  }

  function streamDisabled() {
    // بعد از یک شکست کافی است: هر تلاش ناموفق یعنی یک درخواست اضافه و
    // یک فراخوانی کامل AI در مسیر بازیابی، یعنی دو برابر زمان.
    return streamFails() >= 1;
  }

  function noteStreamFail() {
    store('pasokhban_stream_fails', String(streamFails() + 1));
  }

  function noteStreamOk() {
    if (store('pasokhban_stream_fails')) store('pasokhban_stream_fails', '0');
  }

  /* ============================================================
     پاسخ جریان‌دار (SSE)
  ============================================================ */
  function sendStream(text, userNode, tokens) {
    var bubble = null;
    var full = '';
    var gotAny = false;
    var gotVisitor = false;
    var bailed = false;

    // تا وقتی جریان در جریان است، poll نباید چیزی رندر کند؛
    // وگرنه پاسخ AI هم از راه جریان و هم از راه poll نشان داده می‌شود.
    S.streaming = true;

    // اگر جریان به هر دلیلی نشد، بی‌صدا به مسیر معمولی برمی‌گردیم
    var fallback = function () {
      if (bailed) return;
      bailed = true;
      S.streaming = false;
      noteStreamFail();
      if (bubble && bubble.parentNode) bubble.parentNode.removeChild(bubble);
      hideThinking();

      if (gotVisitor) {
        // پیام کاربر قبلاً روی سرور ذخیره شده؛ POST دوباره یعنی رکورد تکراری.
        // به‌جایش از /live/answer می‌خواهیم به همان پیامِ ذخیره‌شده پاسخ بدهد.
        recoverAnswer(userNode);
        return;
      }

      sendNormal(text, userNode, tokens);
    };

    fetch(S.settings.streamUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key: S.key, content: text, lang: S.lang, current_url: location.href, attachments: tokens || [] })
    }).then(function (res) {
      var type = (res.headers && res.headers.get) ? (res.headers.get('content-type') || '') : '';

      if (!res.ok || !res.body || type.indexOf('text/event-stream') < 0) {
        fallback();
        return;
      }

      var reader = res.body.getReader();
      var decoder = new TextDecoder('utf-8');
      var buf = '';

      var pump = function () {
        return reader.read().then(function (r) {
          if (bailed) return;

          if (r.done) {
            if (!gotAny) fallback();
            return;
          }

          buf += decoder.decode(r.value, { stream: true });

          var idx;
          while ((idx = buf.indexOf('\n')) >= 0) {
            var line = buf.slice(0, idx).replace(/\r$/, '');
            buf = buf.slice(idx + 1);

            if (line.indexOf('data:') !== 0) continue;

            var payload;
            try { payload = JSON.parse(line.slice(5).trim()); } catch (err) { continue; }

            // سرور پیام کاربر را ذخیره کرده و شناسه‌اش را می‌فرستد.
            // بدون این، S.lastId عقب می‌ماند و poll همان پیام را دوباره می‌آورد.
            if (payload.visitor) {
              gotVisitor = true;
              markSeen(payload.visitor);
              if (userNode) userNode.setAttribute('data-mid', payload.visitor);
              continue;
            }

            if (payload.error) { fallback(); return; }

            if (payload.queued) {
              bailed = true;
              S.streaming = false;
              hideThinking();
              addSystem(T.queued);
              S.mode = 'agent';
              updateSubtitle();
              S.busy = false;
              updateSend();
              startPoll(S.open);
              return;
            }

            if (typeof payload.t === 'string' && payload.t !== '') {
              gotAny = true;
              if (!bubble) {
                hideThinking();
                bubble = appendMsg('agent', '', { streaming: true });
              }
              full += payload.t;
              var txt = bubble.querySelector('.psb-msg-text');
              if (txt) txt.textContent = full;
              scrollDown();
            }

            if (payload.done) {
              bailed = true;
              S.streaming = false;
              noteStreamOk();
              S.busy = false;
              updateSend();
              if (bubble) {
                bubble.classList.remove('is-streaming');
                if (payload.id) {
                  bubble.setAttribute('data-mid', payload.id);
                  S.seenIds['r' + payload.id] = 1;
                  if (payload.id > S.lastId) S.lastId = payload.id;
                  addCsat(bubble, payload.id);
                }
                if (payload.sources && payload.sources.length) {
                  bubble.appendChild(buildSources(payload.sources));
                }
              }
              scrollDown();
              // پاسخ جریان‌دار، جواب سؤال خودِ کاربر است — صدا نمی‌دهیم.
              startPoll(S.open);
              return;
            }
          }

          return pump();
        });
      };

      pump();
    }).catch(function () {
      fallback();
    });
  }

  /**
   * بازیابی: جریان شکست خورد ولی پیام کاربر ذخیره شده.
   * به‌جای POST دوباره (که رکورد تکراری می‌سازد)، از سرور می‌خواهیم
   * به همان پیامِ بی‌پاسخ پاسخ بدهد.
   */
  function recoverAnswer(userNode) {
    showThinking();

    api('/live/answer', { key: S.key })
      .then(function (d) {
        hideThinking();
        S.busy = false;
        updateSend();

        if (d && d.queued) {
          addSystem(T.queued);
          S.mode = 'agent';
          updateSubtitle();
          startPoll(S.open);
          return;
        }

        if (d && d.content) {
          var bubble = appendMsg('agent', d.content, { sources: d.sources || [] });
          if (d.id) {
            bubble.setAttribute('data-mid', d.id);
            markSeen(d.id);
            addCsat(bubble, d.id);
          }
          // پاسخ بازیابی‌شده هم جواب سؤال خودِ کاربر است — صدا نمی‌دهیم.
        }

        startPoll(S.open);
      })
      .catch(function (e) {
        hideThinking();
        S.busy = false;
        updateSend();
        addError((e && e.userMessage) || T.errNet);
        startPoll(S.open);
      });
  }

  /* ============================================================
     امتیاز رضایت (CSAT)
  ============================================================ */
  function addCsat(bubble, messageId) {
    if (!S.settings || !S.settings.csat) return;
    if (bubble.querySelector('.psb-csat')) return;

    var wrap = document.createElement('div');
    wrap.className = 'psb-csat';
    wrap.innerHTML =
      '<span class="psb-csat-q">' + esc(T.csatAsk) + '</span>' +
      '<button class="psb-csat-b" data-r="1" aria-label="' + esc(T.csatGood) + '">👍</button>' +
      '<button class="psb-csat-b" data-r="-1" aria-label="' + esc(T.csatBad) + '">👎</button>';

    wrap.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.psb-csat-b') : null;
      if (!btn || wrap.classList.contains('done')) return;
      var rating = parseInt(btn.getAttribute('data-r'), 10);
      wrap.classList.add('done');
      wrap.classList.add(rating === 1 ? 'voted-up' : 'voted-down');
      var lbl = wrap.querySelector('.psb-csat-q');
      if (lbl) lbl.textContent = T.csatThanks;
      api('/live/feedback', { key: S.key, message_id: messageId, rating: rating })
        .catch(function () { /* بی‌صدا — تجربهٔ کاربر قطع نشود */ });
    });

    bubble.appendChild(wrap);
  }

  /**
   * منابع را پشت یک دکمه جمع می‌کند و با کلیک، پاپ‌آپ در همان‌جا باز می‌شود.
   * قبلاً همهٔ منابع زیر هر پاسخ فهرست می‌شدند و فضای زیادی می‌گرفتند.
   */
  function buildSources(sources) {
    var box = document.createElement('div');
    box.className = 'psb-srcwrap';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'psb-srcbtn';
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span class="psb-srcbtn-ico">' + icon('doc') + '</span>' +
      '<span>' + esc(T.sourcesBtn) + '</span>' +
      '<span class="psb-srcbtn-n">' + sources.length + '</span>';

    var pop = document.createElement('div');
    pop.className = 'psb-srcpop';
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-label', T.sources);
    pop.hidden = true;

    var head = document.createElement('div');
    head.className = 'psb-srcpop-head';
    head.innerHTML = '<b>' + esc(T.sources) + '</b>';

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'psb-srcpop-x';
    closeBtn.setAttribute('aria-label', T.close);
    closeBtn.innerHTML = icon('x');
    head.appendChild(closeBtn);
    pop.appendChild(head);

    var list = document.createElement('div');
    list.className = 'psb-srcpop-list';
    sources.forEach(function (sc, i) {
      var a = document.createElement('a');
      a.href = sc.url;
      a.target = '_blank';
      a.rel = 'noopener nofollow';
      a.innerHTML = '<span class="psb-srcpop-i">' + (i + 1) + '</span>' +
        '<span class="psb-srcpop-t">' + esc(sc.title) + '</span>' +
        '<span class="psb-srcpop-u">' + esc(hostOf(sc.url)) + '</span>';
      list.appendChild(a);
    });
    pop.appendChild(list);

    function toggle(force) {
      var open = (typeof force === 'boolean') ? force : pop.hidden;
      pop.hidden = !open;
      btn.classList.toggle('is-open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      // بقیهٔ پاپ‌آپ‌های باز بسته شوند
      if (open) {
        var others = root.querySelectorAll('.psb-srcpop:not([hidden])');
        for (var i = 0; i < others.length; i++) {
          if (others[i] !== pop) {
            others[i].hidden = true;
            var ob = others[i].parentNode ? others[i].parentNode.querySelector('.psb-srcbtn') : null;
            if (ob) { ob.classList.remove('is-open'); ob.setAttribute('aria-expanded', 'false'); }
          }
        }
      }
    }

    btn.addEventListener('click', function (e) { e.stopPropagation(); toggle(); });
    closeBtn.addEventListener('click', function (e) { e.stopPropagation(); toggle(false); });

    box.appendChild(btn);
    box.appendChild(pop);
    return box;
  }

  /** فقط نام دامنه، برای نمایش کوتاه زیر عنوان منبع. */
  function hostOf(url) {
    try {
      var u = new URL(url, window.location.href);
      return u.hostname.replace(/^www\./, '');
    } catch (e) {
      return String(url || '').replace(/^https?:\/\//, '').split('/')[0];
    }
  }

  /* ============================================================
     API
  ============================================================ */
  function api(path, body, method) {
    var opt = {
      method: method || 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin'
    };
    if (CFG.nonce) opt.headers['X-WP-Nonce'] = CFG.nonce;
    if (body) opt.body = JSON.stringify(body);

    return fetch(REST + path, opt)
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) {
          if (!r.ok) {
            var err = new Error((d && d.message) || T.errGeneric);
            err.userMessage = (d && d.message) ? d.message : (r.status >= 500 ? T.errGeneric : T.errNet);
            throw err;
          }
          return d;
        });
      })
      .catch(function (e) {
        if (e && e.userMessage) throw e;
        var err = new Error(T.errNet);
        err.userMessage = T.errNet;
        throw err;
      });
  }

  /* ============================================================
     راه‌اندازی
  ============================================================ */
  function boot() {
    var s = window.PASOKHBAN_CHAT_SETTINGS || null;
    if (s) S.settings = s;

    applyTheme(store(LS.theme) || (s && s.theme) || 'auto');
    applySettings(s);
    build();

    // حالت بزرگ‌نمایی از دفعهٔ قبل
    if (store('pasokhban_live_max') === '1') {
      root.classList.add('is-max');
      var mb = q('#psb-max');
      if (mb) { mb.innerHTML = icon('min'); mb.setAttribute('aria-pressed', 'true'); }
    }

    var inline = root.dataset.mode !== 'widget';

    if (inline) {
      setOpen(true);
      root.classList.add('is-open');
      start();
      return;
    }

    // حالت ویجت
    if (store(LS.open) === '1') {
      setOpen(true);
      return;
    }

    if (store(LS.key)) {
      // بازدیدکنندهٔ بازگشتی که مکالمهٔ قبلی دارد: بی‌صدا ادامه می‌دهیم
      // تا پیام تازهٔ اپراتور و badge کار کند.
      start();
      startPoll(false);
      maybeTeaser();
      return;
    }

    // بازدیدکنندهٔ تازه: تا وقتی خودش پنل را باز نکند، هیچ نشستی در دیتابیس
    // ساخته نمی‌شود و هیچ درخواستی به سرور نمی‌رود
    // (وگرنه هر بازدید صفحه = یک ردیف جدول + polling دائمی).
    maybeTeaser();
    maybeAutoOpen();
  }

  function maybeTeaser() {
    if (S.settings && S.settings.teaser === false) return;
    if (store('pasokhban_live_teaser') === '1') return;
    if (window.innerWidth <= 480) return;
    setTimeout(function () {
      var el = q('#psb-teaser');
      if (!el || S.open) return;
      el.classList.add('on');
      store('pasokhban_live_teaser', '1');
      setTimeout(hideTeaser, 9000);
    }, 5000);
  }

  function hideTeaser() {
    var el = q('#psb-teaser');
    if (el) el.classList.remove('on');
  }

  function maybeAutoOpen() {
    if (!S.settings || !S.settings.autoOpen) return;
    if (store(LS.auto) === '1') return;
    var delay = Math.max(0, parseInt(S.settings.autoDelay, 10) || 0) * 1000;
    setTimeout(function () {
      if (store(LS.auto) === '1' || S.open) return;
      store(LS.auto, '1');
      setOpen(true);
    }, delay);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
