/* =========================================================================
   پاسخ‌بان — دستیار هوشمند مغزی (فرانت‌اند)
   مغز ماورایی: کرهٔ عصبی سه‌بعدی با واکنش‌های زنده به گفتگو
   (حالت‌های idle/listening/thinking/speaking + امواج ضربه‌ای)
   چندزبانه + چند گوینده با لحن/جنسیت متفاوت (فارسی و انگلیسی)
   ========================================================================= */
(function () {
  'use strict';

  var BRAND = { cyan: '#20c2db', navy: '#0f3350', indigo: '#4d8df7' };

  /* ---------- زبان‌ها ---------- */
  var I18N = {
    fa: {
      live: 'سیستم آنلاین',
      thinking: 'در حال تفکر…',
      listening: 'شنیدن…',
      speaking: 'در حال گفتن…',
      launcher: 'پاسخ‌بان',
      placeholder: 'سؤالت رو بنویس یا روی میکروفون بزن…',
      hint: 'پاسخ‌ها توسط هوش مصنوعی تولید می‌شوند و ممکن است خطا داشته باشند.',
      noSpeech: 'مرورگر شما از گفتار پشتیبانی نمی‌کند (مثلاً Chrome را امتحان کنید).',
      greet: ['سلام! من پاسخ‌بان، دستیار هوشمند این سایتم 🌟', 'هر سؤالی داری بپرس؛ متن یا صدا.'],
      source: 'منابع سایت:',
      cats: { contact: 'تماس', price: 'هزینه و قیمت', consult: 'مشاوره', content: 'محتوای سایت', other: 'سایر' },
      voiceTitle: 'انتخاب گوینده',
      voiceSub: 'لحن و جنسیت گویندهٔ فارسی را انتخاب کن',
      autoVoice: 'پیش‌فرض مرورگر',
      voiceNote: 'صداهای موجود به مرورگر/سیستم شما بستگی دارد.'
    },
    en: {
      live: 'System Online',
      thinking: 'Thinking…',
      listening: 'Listening…',
      speaking: 'Speaking…',
      launcher: 'Pasokhban',
      placeholder: 'Type your question or tap the mic…',
      hint: 'Answers are AI-generated and may contain errors.',
      noSpeech: 'Your browser does not support speech input (try Chrome).',
      greet: ['Hi! I\'m Pasokhban, this site\'s smart assistant 🌟', 'Ask me anything — text or voice.'],
      source: 'Sources:',
      cats: { contact: 'Contact', price: 'Pricing', consult: 'Consulting', content: 'Site content', other: 'Other' },
      voiceTitle: 'Choose a voice',
      voiceSub: 'Pick the English speaker tone and gender',
      autoVoice: 'Browser default',
      voiceNote: 'Available voices depend on your browser/system.'
    }
  };

  /* ---------- گوینده‌ها (Personas) ---------- */
  var PERSONAS = {
    fa: [
      { id: 'fa-nima', gender: 'm', tone: 'energetic', name: 'نیما', desc: 'سرزنده و خودمونی', rate: 1.08, pitch: 1.05, kw: ['nima', 'persian', 'zohreh', 'behdad', 'masoud'] },
      { id: 'fa-mina', gender: 'f', tone: 'warm', name: 'مینا', desc: 'گرم و صمیمی', rate: 1.0, pitch: 1.15, kw: ['mina', 'persian', 'azadeh', 'sara', 'sadaf'] },
      { id: 'fa-sam', gender: 'm', tone: 'calm', name: 'سامان', desc: 'آرام و جدی', rate: 0.94, pitch: 0.9, kw: ['sam', 'behnam', 'kamran', 'hamid'] }
    ],
    en: [
      { id: 'en-ethan', gender: 'm', tone: 'energetic', name: 'Ethan', desc: 'Energetic & casual', rate: 1.08, pitch: 1.05, kw: ['ethan', 'daniel', 'alex', 'david'] },
      { id: 'en-ava', gender: 'f', tone: 'warm', name: 'Ava', desc: 'Warm & friendly', rate: 1.0, pitch: 1.12, kw: ['ava', 'samantha', 'emma', 'victoria', 'karen'] },
      { id: 'en-liam', gender: 'm', tone: 'calm', name: 'Liam', desc: 'Calm & deep', rate: 0.94, pitch: 0.85, kw: ['liam', 'james', 'thomas', 'john'] }
    ]
  };

  // نام جدید اول، نام قدیمی به‌عنوان پشتیبان — تا اگر هاست نسخهٔ کهنهٔ
  // این فایل را کش کرده باشد ولی PHP تازه باشد، ویجت از کار نیفتد.
  var CFG = window.PASOKHBAN || window.ETEHADYAR || {
    ajaxUrl: '/wp-json/pasokhban/v1/chat',
    configUrl: '/wp-json/pasokhban/v1/config',
    lang: 'fa',
    ttsEnabled: true,
    sttEnabled: true,
    siteName: '',
    logoNeonUrl: '',
    suggested: [],
    mock: false
  };
  var root = document.getElementById('pasokhban-root');
  if (!root) return;

  // رنگ اصلی از تنظیمات پلاگین — باید روی خودِ root گذاشته شود،
  // چون CSS همان متغیر را روی #pasokhban-root تعریف کرده و مقدار
  // documentElement را override می‌کرد (باگ نسخهٔ ۱.۰.۰).
  if (CFG.accent) root.style.setProperty('--eh-accent', CFG.accent);

  var isWidget = root.dataset.mode === 'widget';

  var state = {
    messages: [],
    open: isWidget ? false : true,
    listening: false,
    speaking: false,
    busy: false,
    lang: CFG.lang || 'fa',
    suggested: CFG.suggested || [],
    light: false,
    paused: false,
    activity: 'idle', // idle | listening | thinking | speaking
    personas: { fa: 'fa-nima', en: 'en-ava' },
    voiceOpen: false,
    waves: [] // امواج ضربه‌ای بصری
  };

  var T = I18N[state.lang] || I18N.fa;
  function t() { T = I18N[state.lang] || I18N.fa; }
  function isLight() { return root.classList.contains('eh-light'); }

  /* ============================================================
     آیکون‌ها
  ============================================================ */
  function svgIcon(name) {
    var p = {
      brain: '<path d="M12 4a4 4 0 0 0-3.5 2.2A4 4 0 0 0 4 8.5 4 4 0 0 0 3 15.5a4 4 0 0 0 1 7 4 4 0 0 0 7 .8V22h2v.3a4 4 0 0 0 7-.8 4 4 0 0 0 1-7A4 4 0 0 0 20 8.5a4 4 0 0 0-4.5-2.3A4 4 0 0 0 12 4z"/><path d="M12 9v6M9 10v2M15 10v2" stroke="currentColor" stroke-width="1.4" fill="none" stroke-linecap="round"/>',
      send: '<path d="M3 11l18-7-7 18-2.5-8.5L3 11z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>',
      mic: '<rect x="9" y="3" width="6" height="11" rx="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3M9 21h6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
      globe: '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9S14.5 18.5 12 21c-2.5-2.5-3.5-5.5-3.5-9S9.5 5.5 12 3z" fill="none" stroke="currentColor" stroke-width="1.5"/>',
      sun: '<circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4 4l2 2M18 18l2 2M20 4l-2 2M6 18l-2 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
      moon: '<path d="M20 14A8 8 0 1 1 10 4a6 6 0 0 0 10 10z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>',
      voice: '<rect x="9" y="2" width="6" height="12" rx="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5 10a7 7 0 0 0 14 0M12 17v4M8 21h8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M17.5 7a1 1 0 0 0 1.5.9A5 5 0 0 0 21 4M6.5 7a1 1 0 0 1-1.5.9A5 5 0 0 1 3 4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
      pause: '<path d="M9 5v14M15 5v14" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>',
      play: '<path d="M8 5v14l11-7z" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>',
      phone: '<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 12l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
      tag: '<path d="M20 12l-8 8-8-8V4h8z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="8" cy="8" r="1.4" fill="currentColor"/>',
      help: '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 2-3 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="17" r="1.2" fill="currentColor"/>',
      doc: '<path d="M6 2h9l4 4v16H6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M15 2v4h4M9 12h6M9 16h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" fill="none"/>',
      spark: '<path d="M12 2l1.5 6.5L20 10l-6.5 1.5L12 18l-1.5-6.5L4 10l6.5-1.5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
    };
    return '<svg viewBox="0 0 24 24">' + (p[name] || '') + '</svg>';
  }

  /* ============================================================
     ساختار DOM
  ============================================================ */
  function build() {
    root.innerHTML =
      (isWidget
        ? '<button class="pasokhban-launcher" id="eh-launcher">' +
            '<span class="eh-logo">' + svgIcon('brain') + '</span>' +
            '<span>' + (CFG.launcherText || 'پاسخ‌بان') + '</span>' +
          '</button>' +
          '<div class="pasokhban-panel" id="eh-panel">'
        : '<div class="pasokhban-panel eh-inline" id="eh-panel">') +
        '<div class="pasokhban-head">' +
          '<canvas class="pasokhban-canvas" id="eh-brain"></canvas>' +
          '<div class="pasokhban-live-status"><span class="dot"></span><span id="eh-status-lbl"></span></div>' +
          (CFG.ttsEnabled ? '<button class="pasokhban-headbtn pasokhban-voicebtn" id="eh-voice" title="Voice">' + svgIcon('voice') + '</button>' : '') +
          '<button class="pasokhban-headbtn pasokhban-play" id="eh-play" title="Play/Pause">' + svgIcon('pause') + '</button>' +
          '<button class="pasokhban-headbtn pasokhban-theme" id="eh-theme" title="Theme">' + svgIcon('moon') + '</button>' +
          '<button class="pasokhban-headbtn pasokhban-lang" id="eh-lang" title="Language">' + svgIcon('globe') + '<span id="eh-lang-lbl"></span></button>' +
          '<div class="pasokhban-voicepanel" id="eh-voicepanel"></div>' +
          '<div class="pasokhban-head-overlay">' +
            '<div class="pasokhban-brand">' +
              '<span class="pasokhban-brandmark">' + svgIcon('brain') + '</span>' +
              '<div>' +
                '<div class="pasokhban-brand-name">پاسخ‌بان</div>' +
                '<div class="pasokhban-brand-sub">' + (CFG.siteName || 'اتحاد وردپرس') + '</div>' +
              '</div>' +
            '</div>' +
            (isWidget ? '<button class="pasokhban-close" id="eh-close">✕</button>' : '') +
          '</div>' +
        '</div>' +
        '<div class="pasokhban-body" id="eh-body"></div>' +
        '<div class="pasokhban-suggest" id="eh-suggest"></div>' +
        '<div class="pasokhban-inputbar">' +
          (CFG.sttEnabled ? '<button class="pasokhban-btn mic" id="eh-mic" title="Voice input">' + svgIcon('mic') + '</button>' : '') +
          '<textarea class="pasokhban-input" id="eh-input" rows="1"></textarea>' +
          '<button class="pasokhban-btn" id="eh-send" title="Send">' + svgIcon('send') + '</button>' +
        '</div>' +
        '<div class="pasokhban-hint" id="eh-hint"></div>' +
        '' +
+
      '</div>';

    if (isWidget) {
      document.getElementById('eh-launcher').addEventListener('click', function () {
        state.open = !state.open;
        document.getElementById('eh-panel').classList.toggle('eh-open', state.open);
        if (state.open) { initBrain(); loadConfig(); maybeGreet(); }
        setTimeout(function () { document.getElementById('eh-input').focus(); }, 120);
      });
      document.getElementById('eh-close').addEventListener('click', function () {
        state.open = false;
        document.getElementById('eh-panel').classList.remove('eh-open');
      });
    } else {
      initBrain();
      loadConfig();
      maybeGreet();
    }

    document.getElementById('eh-lang').addEventListener('click', function () {
      state.lang = state.lang === 'fa' ? 'en' : 'fa';
      t();
      loadConfig();
      applyLang();
      closeVoice();
    });
    document.getElementById('eh-theme').addEventListener('click', function () {
      state.light = !state.light;
      root.classList.toggle('eh-light', state.light);
      document.getElementById('eh-theme').innerHTML = svgIcon(state.light ? 'sun' : 'moon');
    });
    document.getElementById('eh-play').addEventListener('click', function () {
      state.paused = !state.paused;
      document.getElementById('eh-play').innerHTML = svgIcon(state.paused ? 'play' : 'pause');
      if (!state.paused) startLoop();
    });
    if (CFG.ttsEnabled) {
      document.getElementById('eh-voice').addEventListener('click', function (e) {
        e.stopPropagation();
        state.voiceOpen = !state.voiceOpen;
        renderVoicePanel();
        var vp = document.getElementById('eh-voicepanel');
        vp.classList.toggle('open', state.voiceOpen);
      });
    }
    // بستن پنل گوینده با کلیک بیرون
    root.addEventListener('click', function (e) {
      if (state.voiceOpen && !e.target.closest('.pasokhban-voicepanel') && !e.target.closest('#eh-voice')) {
        state.voiceOpen = false;
        document.getElementById('eh-voicepanel').classList.remove('open');
      }
    });
  }

  /* ---------- پنل گوینده ---------- */
  function renderVoicePanel() {
    var vp = document.getElementById('eh-voicepanel');
    if (!vp) return;
    t();
    var cur = state.personas[state.lang];
    var html = '<div class="eh-vp-title">' + T.voiceTitle + '</div>';
    html += '<div class="eh-vp-sub">' + T.voiceSub + '</div>';
    PERSONAS[state.lang].forEach(function (pers) {
      var act = pers.id === cur ? ' selected' : '';
      html += '<button class="eh-persona' + act + '" data-id="' + pers.id + '">' +
        '<span class="eh-p-ico">' + (pers.gender === 'f' ? '👩' : '👨') + '</span>' +
        '<span class="eh-p-meta"><span class="eh-p-name">' + pers.name + '</span>' +
        '<span class="eh-p-desc">' + pers.desc + '</span></span>' +
        '<span class="eh-p-tone">' + (pers.tone === 'energetic' ? '⚡' : pers.tone === 'warm' ? '💛' : '🌊') + '</span>' +
      '</button>';
    });
    html += '<button class="eh-persona eh-auto" data-id="auto">' +
      '<span class="eh-p-ico">🎙️</span><span class="eh-p-meta"><span class="eh-p-name">' + T.autoVoice + '</span>' +
      '<span class="eh-p-desc">' + (state.personas[state.lang] === 'auto' ? '—' : '') + '</span></span></button>';
    html += '<div class="eh-vp-note">' + T.voiceNote + '</div>';
    vp.innerHTML = html;

    var btns = vp.querySelectorAll('.eh-persona');
    Array.prototype.forEach.call(btns, function (b) {
      b.addEventListener('click', function () {
        state.personas[state.lang] = b.getAttribute('data-id');
        renderVoicePanel();
        state.voiceOpen = true;
      });
    });
  }

  function closeVoice() {
    state.voiceOpen = false;
    var vp = document.getElementById('eh-voicepanel');
    if (vp) vp.classList.remove('open');
  }

  /* ---------- صدای گوینده ---------- */
  var synth = window.speechSynthesis;
  var allVoices = [];
  function loadVoices() {
    if (synth && synth.getVoices) {
      allVoices = synth.getVoices() || [];
    }
  }
  if (synth && synth.onvoiceschanged) {
    synth.onvoiceschanged = loadVoices;
  }
  loadVoices();

  function findVoiceFor(persona, lang) {
    if (!persona || persona === 'auto') return null;
    var base = lang.split('-')[0];
    var list = allVoices.filter(function (v) {
      return v.lang && v.lang.toLowerCase().indexOf(base.toLowerCase()) === 0;
    });
    if (!list.length) return null;
    // تطبیق بر اساس نام گوینده
    var byName = list.filter(function (v) { return persona.kw.some(function (k) { return v.name.toLowerCase().indexOf(k) >= 0; }); });
    if (byName.length) return byName[0];
    // تطبیق بر اساس جنسیت (به کمک کلمه‌های نام)
    var genderMatch = list.filter(function (v) {
      var n = v.name.toLowerCase();
      if (persona.gender === 'f') return /(female|samantha|zira|hazel|alice|ava|karen|moira|tessa|susan|victoria)/.test(n);
      return /(male|david|mark|daniel|alex|fred|george|oliver|james|thomas|tom|ria|amir|nima)/.test(n);
    });
    return genderMatch.length ? genderMatch[0] : list[0];
  }

  function speak(text) {
    if (!synth || !CFG.ttsEnabled) return;
    synth.cancel();
    var lang = state.lang === 'en' ? 'en-US' : 'fa-IR';
    var u = new SpeechSynthesisUtterance(text);
    u.lang = lang;
    var persId = state.personas[state.lang];
    var persona = null;
    PERSONAS[state.lang].forEach(function (p) { if (p.id === persId) persona = p; });
    var v = findVoiceFor(persona, lang);
    if (v) u.voice = v;
    if (persona) { u.rate = persona.rate; u.pitch = persona.pitch; }
    u.onstart = function () { setActivity('speaking'); addWave('speak'); };
    u.onend = function () { setActivity(state.busy ? 'thinking' : 'idle'); };
    u.onerror = function () { setActivity(state.busy ? 'thinking' : 'idle'); };
    state.speaking = true;
    synth.speak(u);
  }

  function stopSpeaking() {
    if (synth) { try { synth.cancel(); } catch (e) {} }
    state.speaking = false;
    setActivity(state.busy ? 'thinking' : 'idle');
  }

  /* ============================================================
     وضعیت فعالیت و امواج ضربه‌ای
  ============================================================ */
  function setActivity(act) {
    state.activity = act;
    root.classList.remove('eh-a-idle', 'eh-a-listening', 'eh-a-thinking', 'eh-a-speaking');
    root.classList.add('eh-a-' + act);
    var lb = document.getElementById('eh-status-lbl');
    if (lb) {
      t();
      var map = { idle: T.live, listening: T.listening, thinking: T.thinking, speaking: T.speaking };
      lb.textContent = map[act] || T.live;
    }
  }

  function addWave(type) {
    var c = { x: 0, y: 0, r: 0, type: type, t: 0 };
    state.waves.push(c);
    if (state.waves.length > 8) state.waves.shift();
  }

  function emitQuestionWave() {
    addWave('question');
    setActivity(state.busy ? 'thinking' : 'idle');
  }

  /* ============================================================
     مغز ماورایی — کرهٔ عصبی سه‌بعدی با واکنش‌ها
  ============================================================ */
  var brainCtx, brainW = 0, brainH = 0, brainDpr = 1;
  var orb = [], dust = [], aura = [], logoImg = null;
  var rotY = 0, rotX = 0;
  var animating = false;
  var ORB_N = 260;
  var DUST_N = 46;

  function initBrain() {
    var c = document.getElementById('eh-brain');
    if (!c || brainCtx) return;
    brainCtx = c.getContext('2d');
    if (CFG.logoNeonUrl) {
      logoImg = new Image();
      logoImg.src = CFG.logoNeonUrl;
    }
    var ro = new ResizeObserver(function () {
      brainDpr = Math.min(2, window.devicePixelRatio || 1);
      brainW = c.clientWidth || 0;
      brainH = c.clientHeight || 0;
      c.width = Math.max(1, brainW * brainDpr);
      c.height = Math.max(1, brainH * brainDpr);
      brainCtx.setTransform(brainDpr, 0, 0, brainDpr, 0, 0);
      buildNexus();
      startLoop();
    });
    try { ro.observe(c); } catch (e) {}
    if (!window.ResizeObserver) {
      brainW = c.clientWidth || 320;
      brainH = c.clientHeight || 200;
      buildNexus();
      startLoop();
    }
  }

  function startLoop() {
    if (animating || !brainCtx || state.paused) return;
    animating = true;
    animateBrain();
  }

  function animateBrain() {
    // وقتی پنل بسته است هم حلقه متوقف می‌شود تا CPU/باتری هدر نرود
    // (باگ نسخهٔ ۱.۰.۰: انیمیشن بعد از بستن پنل تا ابد ادامه داشت).
    if (!brainCtx || state.paused || !brainW || !brainH || (isWidget && !state.open)) {
      animating = false;
      return;
    }
    drawNexus(performance.now());
    requestAnimationFrame(animateBrain);
  }

  function rng(seed) { var x = Math.sin(seed * 999) * 10000; return x - Math.floor(x); }

  function buildNexus() {
    orb = []; dust = []; aura = [];
    var rad = Math.max(70, Math.min(brainH * 0.30, brainW * 0.30));
    var i;
    for (i = 0; i < ORB_N; i++) {
      var phi = Math.acos(1 - 2 * (i + 0.5) / ORB_N);
      var theta = i * 2.399963;
      orb.push({ x: rad * Math.sin(phi) * Math.cos(theta), y: rad * Math.sin(phi) * Math.sin(theta), z: rad * Math.cos(phi), p: i });
    }
    for (i = 0; i < DUST_N; i++) {
      dust.push({ x: rng(i * 3 + 1) * brainW, y: rng(i * 7 + 5) * brainH, r: 0.4 + 1.2 * rng(i * 13 + 9), tw: rng(i * 17 + 11), vy: 0.03 + 0.14 * rng(i * 19 + 7) });
    }
    for (i = 0; i < 70; i++) {
      var a = rng(i * 23 + 1) * Math.PI * 2;
      var rr = rad * (0.9 + 0.5 * rng(i * 29 + 7));
      aura.push({ a: a, r: rr, sp: 0.0002 + 0.0004 * rng(i * 31 + 3), o: 0.2 + 0.5 * rng(i * 37 + 11) });
    }
  }

  function proj(px, py, pz, ay, ax) {
    var cy = Math.cos(ay), sy = Math.sin(ay);
    var cx = Math.cos(ax), sx = Math.sin(ax);
    var x = px * cy + pz * sy;
    var z = -px * sy + pz * cy;
    var y = py * cx - z * sx;
    var zz = py * sx + z * cx;
    return { x: x, y: y, z: zz };
  }

  // رنگ بر اساس حالت فعالیت
  function activityColors(light) {
    var a = state.activity;
    if (a === 'listening') return { main: '#34d399', glow: 'rgba(52,211,153,' };
    if (a === 'thinking') return { main: '#fbbf24', glow: 'rgba(251,191,36,' };
    if (a === 'speaking') return { main: '#a78bfa', glow: 'rgba(167,139,250,' };
    return { main: BRAND.cyan, glow: 'rgba(32,194,219,' };
  }

  function drawNexus(time) {
    var ctx = brainCtx;
    if (!ctx || !brainW || !brainH) return;
    var light = isLight();
    var cx = brainW / 2, cy = brainH / 2;
    var cols = activityColors(light);
    var accent = cols.main;
    var rad = Math.max(70, Math.min(brainH * 0.30, brainW * 0.30));
    var thinking = state.activity === 'thinking';
    var speaking = state.activity === 'speaking';
    var listening = state.activity === 'listening';
    var thinkBoost = thinking ? 1.7 : (speaking ? 1.4 : (listening ? 1.2 : 1));

    ctx.clearRect(0, 0, brainW, brainH);

    // پس‌زمینهٔ عمیق
    var bg = ctx.createRadialGradient(cx, cy, 10, cx, cy, Math.max(brainW, brainH) * 0.75);
    if (light) { bg.addColorStop(0, 'rgba(226,240,250,1)'); bg.addColorStop(1, 'rgba(200,224,240,1)'); }
    else { bg.addColorStop(0, 'rgba(9,14,26,1)'); bg.addColorStop(1, 'rgba(4,7,14,1)'); }
    ctx.fillStyle = bg; ctx.fillRect(0, 0, brainW, brainH);

    // غبار شناور
    var dustColor = light ? 'rgba(15,51,80,' : 'rgba(210,235,255,';
    for (var d = 0; d < dust.length; d++) {
      var du = dust[d];
      du.y -= du.vy * 0.5; if (du.y < 0) du.y = brainH;
      var tw = 0.3 + 0.7 * Math.abs(Math.sin(time * 0.0015 + du.tw * 6.28));
      ctx.beginPath(); ctx.arc(du.x, du.y, du.r, 0, 6.283);
      ctx.fillStyle = dustColor + (0.16 * tw).toFixed(3) + ')';
      ctx.fill();
    }

    // هالهٔ نور دور کره
    for (var au = 0; au < aura.length; au++) {
      var ap = aura[au];
      ap.a += ap.sp * 60 * (thinking ? 2.2 : 1);
      var px2 = cx + Math.cos(ap.a) * ap.r;
      var py2 = cy + Math.sin(ap.a) * ap.r * 0.92;
      var apw = 0.5 + 0.5 * Math.sin(time * 0.002 + au * 0.7);
      ctx.beginPath(); ctx.arc(px2, py2, 1.1 + apw * 1.4, 0, 6.283);
      ctx.fillStyle = accent;
      ctx.globalAlpha = ap.o * (light ? 0.35 : 0.7) * apw * thinkBoost;
      ctx.fill();
    }
    ctx.globalAlpha = 1;

    // --- امواج ضربه‌ای (واکنش به سؤال/پاسخ/صحبت) ---
    var waves = state.waves;
    for (var w = 0; w < waves.length; w++) {
      var wave = waves[w];
      wave.t += 0.02 * (thinking ? 1.6 : 1);
      var maxR = Math.max(brainW, brainH) * 0.7;
      var rr = wave.r + wave.t * maxR * 0.12;
      wave.r = rr;
      var wAlpha = (1 - wave.t) * (wave.type === 'speak' ? 0.5 : 0.35);
      if (wAlpha <= 0) { wave.t = 99; continue; }
      var wcol = wave.type === 'question' ? '#38bdf8' : (wave.type === 'speak' ? '#a78bfa' : '#34d399');
      ctx.beginPath(); ctx.arc(cx, cy, rr, 0, 6.283);
      ctx.strokeStyle = wcol;
      ctx.globalAlpha = wAlpha;
      ctx.lineWidth = 2;
      ctx.stroke();
    }
    state.waves = state.waves.filter(function (x) { return x.t < 1; });
    ctx.globalAlpha = 1;

    // --- چرخش کره ---
    var speed = thinking ? 0.009 : (speaking ? 0.006 : 0.0036);
    rotY += speed; rotX += speed * 0.25;
    var pts = new Array(orb.length);
    for (var i = 0; i < orb.length; i++) pts[i] = proj(orb[i].x, orb[i].y, orb[i].z, rotY, rotX);

    var scale = Math.min(brainW, brainH) * 0.0009;
    var persp = Math.max(brainW, brainH) * 1.6;
    function toScreen(p) { var f = persp / (persp - p.z); return { sx: cx + p.x * scale * f, sy: cy + p.y * scale * f, z: p.z, f: f }; }
    var sc = pts.map(toScreen);

    // خطوط اتصال
    var thr = rad * 0.34;
    var ec = light ? 'rgba(15,51,80,' : cols.glow;
    ctx.lineWidth = 0.6;
    for (var a = 0; a < orb.length; a += 6) {
      for (var b = a + 6; b < orb.length; b += 6) {
        var dx = orb[a].x - orb[b].x, dy = orb[a].y - orb[b].y, dz = orb[a].z - orb[b].z;
        if (Math.sqrt(dx * dx + dy * dy + dz * dz) < thr) {
          var za = sc[a], zb = sc[b];
          var df = Math.max(0, Math.min(1, (za.z * 0.5 + 0.5)));
          ctx.strokeStyle = ec + (0.10 + 0.22 * (1 - df)).toFixed(3) + ')';
          ctx.beginPath(); ctx.moveTo(za.sx, za.sy); ctx.lineTo(zb.sx, zb.sy); ctx.stroke();
        }
      }
    }

    // نقاط کره با هالهٔ نور
    for (var i2 = 0; i2 < sc.length; i2++) {
      var pt = sc[i2];
      var depth = (pt.z * 0.5 + 0.5);
      var bright = 1 - depth;
      var pu = 0.5 + 0.5 * Math.sin(time * 0.002 + orb[i2].p * 0.5);
      var r = (0.7 + 1.6 * pu) * pt.f;
      var grad = ctx.createRadialGradient(pt.sx, pt.sy, 0, pt.sx, pt.sy, r * 4);
      grad.addColorStop(0, accent);
      grad.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.globalAlpha = 0.55 * bright * (light ? 0.35 : 0.8) * (0.4 + pu) * thinkBoost;
      ctx.fillStyle = grad;
      ctx.beginPath(); ctx.arc(pt.sx, pt.sy, r * 4, 0, 6.283); ctx.fill();
      ctx.globalAlpha = 1;
      ctx.beginPath(); ctx.arc(pt.sx, pt.sy, r, 0, 6.283);
      ctx.fillStyle = 'rgba(' + (light ? '220,240,255,' : '210,240,255,') + (0.5 + 0.5 * bright).toFixed(3) + ')';
      ctx.fill();
    }
    ctx.globalAlpha = 1;

    // هستهٔ درخشان مرکز
    var coreR = brainH * 0.16 * thinkBoost;
    var coreG = ctx.createRadialGradient(cx, cy, 0, cx, cy, coreR);
    coreG.addColorStop(0, cols.glow + (light ? 0.10 : 0.28) * thinkBoost + ')');
    coreG.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = coreG;
    ctx.beginPath(); ctx.arc(cx, cy, coreR, 0, 6.283); ctx.fill();

    // حلقهٔ «شنیدن» (listening) — حلقهٔ رادار چرخان
    if (listening) {
      var scanAngle = time * 0.002;
      ctx.save();
      ctx.translate(cx, cy);
      ctx.rotate(scanAngle);
      var sgrad = ctx.createConicGradient ? null : null;
      ctx.beginPath();
      ctx.arc(0, 0, rad * 1.25, 0, Math.PI * 2);
      ctx.strokeStyle = 'rgba(52,211,153,0.25)';
      ctx.lineWidth = 2;
      ctx.stroke();
      ctx.beginPath();
      ctx.moveTo(0, 0);
      ctx.arc(0, 0, rad * 1.25, 0, Math.PI * 0.5);
      ctx.closePath();
      ctx.fillStyle = 'rgba(52,211,153,0.10)';
      ctx.fill();
      ctx.restore();
    }

    // لوگو در مرکز
    if (logoImg && logoImg.complete && logoImg.naturalWidth) {
      var lw = Math.min(0.30 * brainW, 120);
      var lh = lw * (logoImg.naturalHeight / logoImg.naturalWidth);
      var pulse = 0.6 + 0.4 * Math.sin(time * 0.0016);
      var breathe = (listening || speaking) ? 1 + 0.05 * Math.sin(time * 0.01) : 1;
      ctx.save();
      ctx.shadowColor = accent;
      ctx.shadowBlur = (light ? 14 : 30) * pulse * thinkBoost;
      ctx.globalAlpha = light ? 0.92 : 0.96;
      ctx.drawImage(logoImg, cx - (lw * breathe) / 2, cy - (lh * breathe) / 2, lw * breathe, lh * breathe);
      ctx.restore();
    } else {
      ctx.fillStyle = accent;
      ctx.font = '13px Vazirmatn, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('NEXUS', cx, cy);
    }

    // امواج گفتار (speaking) — حلقه‌های ریتمیک
    if (speaking) {
      var beat = (time * 0.004) % 1;
      for (var ri = 1; ri <= 3; ri++) {
        var br = rad * (0.6 + (beat + ri * 0.33) % 1) * 1.2;
        var balpha = (1 - ((beat + ri * 0.33) % 1)) * 0.3;
        ctx.beginPath(); ctx.arc(cx, cy, br, 0, 6.283);
        ctx.strokeStyle = '#a78bfa';
        ctx.globalAlpha = balpha;
        ctx.lineWidth = 1.6;
        ctx.stroke();
      }
      ctx.globalAlpha = 1;
    }

    // لایهٔ نور نرم نهایی
    var vg = ctx.createLinearGradient(0, 0, brainW, brainH);
    vg.addColorStop(0, cols.glow + (light ? 0.05 : 0.06) + ')');
    vg.addColorStop(0.5, 'rgba(77,141,247,0.04)');
    vg.addColorStop(1, cols.glow + (light ? 0.05 : 0.06) + ')');
    ctx.fillStyle = vg; ctx.fillRect(0, 0, brainW, brainH);
  }

  /* ============================================================
     پیام‌ها و چت
  ============================================================ */
  function el(id) { return document.getElementById(id); }

  function addMessage(role, html, opts) {
    var body = el('eh-body');
    var m = document.createElement('div');
    m.className = 'pasokhban-msg ' + role;
    m.innerHTML = html;
    if (opts && opts.sources) m.appendChild(opts.sources);
    body.appendChild(m);
    body.scrollTop = body.scrollHeight;
    return m;
  }

  function maybeGreet() {
    if (state.messages.length) return;
    t();
    // متن خوش‌آمدِ تنظیم‌شده در پنل ادمین ارجحیت دارد (باگ نسخهٔ ۱.۰.۰: نادیده گرفته می‌شد)
    var greet = (CFG.greeting && String(CFG.greeting).trim()) || T.greet.join(' ');
    state.messages.push({ role: 'bot', content: greet });
    addMessage('bot', escapeHtml(greet));
    if (CFG.ttsEnabled && state.open) setTimeout(function () { speak(greet); }, 600);
  }

  function escapeHtml(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  function addSources(node, sources) {
    if (!sources || !sources.length) return;
    t();
    var wrap = document.createElement('div');
    wrap.className = 'pasokhban-sources';
    wrap.innerHTML = '<b>' + T.source + '</b>';
    sources.forEach(function (s) {
      var a = document.createElement('a');
      a.href = s.url; a.target = '_blank'; a.rel = 'noopener';
      a.textContent = s.title;
      wrap.appendChild(a);
    });
    node.appendChild(wrap);
  }

  function send() {
    var inp = el('eh-input');
    var text = inp.value.trim();
    if (!text || state.busy) return;
    inp.value = ''; autoGrow();
    submit(text);
  }

  function setBusy(v) {
    state.busy = v;
    if (v) setActivity('thinking'); else setActivity(state.listening ? 'listening' : 'idle');
  }

  function submit(text) {
    state.messages.push({ role: 'user', content: text });
    addMessage('user', escapeHtml(text));
    emitQuestionWave();
    var tp = addMessage('bot typing', '<div class="pasokhban-typing-dot"></div><div class="pasokhban-typing-dot"></div><div class="pasokhban-typing-dot"></div>');
    setBusy(true);
    stopSpeaking();

    callApi(text).then(function (res) {
      tp.remove();
      state.messages.push({ role: 'assistant', content: res.content });
      var m = addMessage('bot', escapeHtml(res.content));
      if (res.sources) addSources(m, res.sources);
      setBusy(false);
      addWave('answer');
      if (CFG.ttsEnabled) speak(res.content);
    }).catch(function (err) {
      tp.remove();
      var msg = (err && err.userMessage) ? err.userMessage
        : (state.lang === 'en' ? 'Something went wrong. Please try again.' : 'مشکلی پیش آمد. دوباره امتحان کن.');
      addMessage('bot', escapeHtml(msg));
      setBusy(false);
    });
  }

  function callApi(text) {
    if (CFG.mock) return mockReply(text);
    return fetch(CFG.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce || '' },
      body: JSON.stringify({ messages: state.messages, lang: state.lang })
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (!r.ok) {
          var e = new Error('http');
          e.userMessage = (data && data.message) ? data.message : (state.lang === 'en'
            ? 'The assistant could not answer (code ' + r.status + ').'
            : 'دستیار نتوانست پاسخ بدهد (کد ' + r.status + ').');
          throw e;
        }
        return data;
      });
    }).then(function (data) {
      return { content: data.content, sources: data.sources || [] };
    })
    // باگ نسخهٔ ۱.۰.۰: هر خطایی به mockReply می‌افتاد و کاربر «پاسخ نمایشی»
    // می‌دید و فکر می‌کرد همه‌چیز سالم است. حالا خطای واقعی نشان داده می‌شود.
    .catch(function (e) {
      if (e && e.userMessage) throw e;
      var err = new Error('net');
      err.userMessage = state.lang === 'en'
        ? 'Could not reach the assistant. Check your connection and try again.'
        : 'ارتباط با دستیار برقرار نشد. اتصال اینترنت را چک کن و دوباره امتحان کن.';
      throw err;
    });
  }

  function mockReply(text) {
    var base = state.lang === 'en'
      ? 'This is a demo reply — in the real version the smart answer comes from the GAPGPT API.\n\n'
      : 'این یک پاسخ نمایشی است — در نسخهٔ واقعی، پاسخ هوشمند از API گپ‌جی‌پی‌تی برمی‌گردد.\n\n';
    var content = base + (state.lang === 'en'
      ? 'In the demo the assistant responds to any question. Install the plugin and set your API key for live answers.'
      : 'در نسخهٔ نمایشی دستیار به هر سؤالی پاسخ می‌دهد. پلاگین را نصب و کلید API را تنظیم کنید تا پاسخ واقعی بگیرید.');
    return new Promise(function (resolve) {
      setTimeout(function () { resolve({ content: content, sources: [] }); }, 1100);
    });
  }

  /* ============================================================
     ورودی صوتی
  ============================================================ */
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  var recognition = null;

  function startMic() {
    if (!CFG.sttEnabled || !SR) { t(); addMessage('bot', T.noSpeech); return; }
    if (state.listening) { stopMic(); return; }
    if (!recognition) {
      recognition = new SR();
      recognition.lang = state.lang === 'en' ? 'en-US' : 'fa-IR';
      recognition.interimResults = true;
      recognition.maxAlternatives = 1;
      recognition.onresult = function (ev) {
        var interim = '', final = '';
        for (var i = 0; i < ev.results.length; i++) {
          if (ev.results[i].isFinal) final += ev.results[i][0].transcript;
          else interim += ev.results[i][0].transcript;
        }
        el('eh-input').value = final || interim; autoGrow();
      };
      recognition.onend = function () {
        state.listening = false;
        el('eh-mic').classList.remove('recording');
        setActivity(state.busy ? 'thinking' : 'idle');
        var v = el('eh-input').value.trim();
        if (v) send();
      };
      recognition.onerror = function () {
        state.listening = false;
        el('eh-mic').classList.remove('recording');
        setActivity(state.busy ? 'thinking' : 'idle');
      };
    } else {
      recognition.lang = state.lang === 'en' ? 'en-US' : 'fa-IR';
    }
    try { recognition.start(); } catch (e) {}
    state.listening = true;
    el('eh-mic').classList.add('recording');
    setActivity('listening');
  }

  function stopMic() { if (recognition) { try { recognition.stop(); } catch (e) {} } }

  /* ============================================================
     رویدادها
  ============================================================ */
  function autoGrow() {
    var inp = el('eh-input');
    inp.style.height = 'auto';
    inp.style.height = Math.min(inp.scrollHeight, 120) + 'px';
  }

  function bind() {
    el('eh-send').addEventListener('click', send);
    el('eh-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    el('eh-input').addEventListener('input', autoGrow);
    if (CFG.sttEnabled) el('eh-mic').addEventListener('click', startMic);
    applyLang();
  }

  /* ---------- کاربرد زبان ---------- */
  function applyLang() {
    t();
    el('eh-lang-lbl').textContent = state.lang === 'fa' ? 'EN' : 'فا';
    setActivity(state.activity);
    el('eh-input').placeholder = (CFG.placeholder && String(CFG.placeholder).trim()) || T.placeholder;
    el('eh-hint').textContent = T.hint;
    if (!state.messages.length) {
      document.getElementById('eh-body').innerHTML = '';
      maybeGreet();
      renderSuggest();
    }
  }

  function loadConfig() {
    if (CFG.mock) { state.suggested = CFG.suggested || []; renderSuggest(); return; }
    fetch(CFG.configUrl + '?lang=' + state.lang)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        state.suggested = d.suggested || [];
        if (d.accent) root.style.setProperty('--eh-accent', d.accent);
        CFG.siteName = d.siteName || CFG.siteName;
        renderSuggest();
      })
      .catch(function () { renderSuggest(); });
  }

  var CATEGORIES = [
    { id: 'contact', fa: 'تماس', en: 'Contact', icon: 'phone', kw: ['contact', 'تماس', 'call', 'شماره', 'email', 'ایمیل'] },
    { id: 'price', fa: 'هزینه و قیمت', en: 'Pricing', icon: 'tag', kw: ['price', 'قیمت', 'هزینه', 'cost', 'پرداخت', 'فروش', 'خرید'] },
    { id: 'consult', fa: 'مشاوره', en: 'Consulting', icon: 'help', kw: ['consult', 'مشاوره', 'مشاور', 'راهنمایی', 'پشتیبانی', 'support'] },
    { id: 'content', fa: 'محتوای سایت', en: 'Site content', icon: 'doc', kw: ['about', 'درباره', 'آموزش', 'teach', 'وردپرس', 'wordpress', 'محصول', 'خدمات', 'راهنما'] }
  ];
  function categorize(q) {
    t();
    var lower = q.toLowerCase();
    for (var i = 0; i < CATEGORIES.length; i++) {
      var c = CATEGORIES[i];
      for (var k = 0; k < c.kw.length; k++) {
        if (lower.indexOf(c.kw[k].toLowerCase()) >= 0) return c;
      }
    }
    return { id: 'other', fa: 'سایر', en: 'Other', icon: 'spark', kw: [] };
  }

  function renderSuggest() {
    var box = el('eh-suggest');
    if (!box) return;
    if (!state.suggested.length) { box.innerHTML = ''; return; }
    t();
    var groups = {};
    state.suggested.forEach(function (q) {
      var cat = categorize(q);
      if (!groups[cat.id]) groups[cat.id] = { cat: cat, items: [] };
      groups[cat.id].items.push(q);
    });
    var html = '';
    Object.keys(groups).forEach(function (id) {
      var g = groups[id];
      html += '<div class="pasokhban-suggest-group">' +
        '<span class="pasokhban-suggest-cat">' + svgIcon(g.cat.icon) + ' ' + T.cats[g.cat.id] + '</span>';
      g.items.forEach(function (q) {
        html += '<button class="pasokhban-chip" data-q="' + q.replace(/"/g, '&quot;') + '">' + q + '</button>';
      });
      html += '</div>';
    });
    box.innerHTML = html;
    var chips = box.querySelectorAll('.pasokhban-chip');
    Array.prototype.forEach.call(chips, function (c) {
      c.addEventListener('click', function () {
        submit(c.getAttribute('data-q'));
        box.innerHTML = '';
      });
    });
  }

  build();
  bind();
})();
