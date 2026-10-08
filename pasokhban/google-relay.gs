/**
 * رلهٔ تلگرام پاسخ‌بان — Google Apps Script
 *
 * چرا لازم است:
 *   سرورهای ایران به api.telegram.org دسترسی ندارند، ولی گوگل در دسترس است
 *   و سرور گوگل می‌تواند به تلگرام برسد. پلاگین پیام را به این اسکریپت
 *   می‌فرستد و این اسکریپت آن را به تلگرام می‌رساند.
 *
 * ─── راهنمای نصب ───
 * ۱) برو به https://script.google.com و یک پروژهٔ جدید بساز.
 * ۲) کل محتوای این فایل را جای کد پیش‌فرض بگذار و ذخیره کن.
 * ۳) Deploy ← New deployment ← نوع: Web app
 *      - Execute as:  Me
 *      - Who has access:  Anyone
 * ۴) دکمهٔ Authorize access را بزن و اجازه بده.
 * ۵) آدرسی که می‌دهد (…/exec) را در پیشخوان وردپرس بگذار:
 *      پاسخ‌بان ← تنظیمات ← تب «اعلان‌ها» ← «رلهٔ گوگل»
 *
 * ⚠ اگر بعداً این کد را تغییر دادی، باید Deploy ← Manage deployments ←
 *   Edit ← Version: New version را بزنی وگرنه تغییرات اعمال نمی‌شوند.
 *
 * ─── امنیت ───
 * این Web App برای همه باز است (چون وردپرس نمی‌تواند لاگین گوگل داشته باشد).
 * برای اینکه غریبه‌ها نتوانند از آن سوءاستفاده کنند، یک «کلید مشترک» تعریف
 * شده: SECRET را پایین عوض کن و همان را در وردپرس هم وارد کن.
 */

// ── این را عوض کن (یک رشتهٔ تصادفی بلند) ──
var SECRET = 'پاسخ‌بان-کلید-خودت-را-اینجا-بگذار';

function doPost(e) {
  var p = readParams(e);

  if (p.secret !== SECRET) {
    return json({ ok: false, error: 'unauthorized' });
  }

  if (!p.token || !p.chat_id || !p.text) {
    return json({ ok: false, error: 'missing token / chat_id / text' });
  }

  try {
    var url = 'https://api.telegram.org/bot' + encodeURIComponent(p.token) + '/sendMessage';

    var res = UrlFetchApp.fetch(url, {
      method: 'post',
      muteHttpExceptions: true,
      payload: {
        chat_id: p.chat_id,
        text: p.text,
        parse_mode: p.parse_mode || '',
        disable_web_page_preview: true
      }
    });

    var code = res.getResponseCode();
    var body = res.getContentText();

    if (code === 200) {
      return json({ ok: true });
    }

    // خطای تلگرام را برگردان تا در وردپرس قابل دیباگ باشد
    var parsed;
    try { parsed = JSON.parse(body); } catch (err) { parsed = null; }

    return json({
      ok: false,
      error: (parsed && parsed.description) ? parsed.description : ('telegram http ' + code + ': ' + body.slice(0, 200))
    });

  } catch (err) {
    return json({ ok: false, error: String(err) });
  }
}

/**
 * پشتیبانی از هر دو قالب: فرم (e.parameter) و JSON خام (e.postData.contents).
 */
function readParams(e) {
  var out = { token: '', chat_id: '', text: '', parse_mode: '', secret: '' };

  if (e && e.parameter && e.parameter.chat_id) {
    out.token = e.parameter.token || '';
    out.chat_id = e.parameter.chat_id || '';
    out.text = e.parameter.text || '';
    out.parse_mode = e.parameter.parse_mode || '';
    out.secret = e.parameter.secret || '';
    return out;
  }

  if (e && e.postData && e.postData.contents) {
    var raw = e.postData.contents;
    var data = null;
    try { data = JSON.parse(raw); } catch (err) { data = null; }

    if (data) {
      out.token = data.token || '';
      out.chat_id = data.chat_id || '';
      out.text = data.text || '';
      out.parse_mode = data.parse_mode || '';
      out.secret = data.secret || '';
      return out;
    }

    // فرم urlencoded خام
    var pairs = raw.split('&');
    for (var i = 0; i < pairs.length; i++) {
      var kv = pairs[i].split('=');
      if (kv.length < 2) continue;
      var k = decodeURIComponent(kv[0]);
      var v = decodeURIComponent(kv.slice(1).join('=').replace(/\+/g, ' '));
      if (k === 'token') out.token = v;
      if (k === 'chat_id') out.chat_id = v;
      if (k === 'text') out.text = v;
      if (k === 'parse_mode') out.parse_mode = v;
      if (k === 'secret') out.secret = v;
    }
  }

  return out;
}

/** برای تست دستی در مرورگر. */
function doGet(e) {
  return json({ ok: true, message: 'Pasokhban Telegram relay is running.' });
}

function json(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
