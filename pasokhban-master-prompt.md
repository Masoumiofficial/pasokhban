# پرامپت master — پروژهٔ «پاسخ‌بان» (اتحاد وردپرس)

این فایل را در ابتدای هر گفت‌وگوی جدید برای ادامهٔ کار به مدل بده. همه‌چیز به فارسی؛ خروجی‌ها هم فارسی باشند.

---

## ۱) هویت پروژه

- **محصول:** افزونهٔ وردپرس «پاسخ‌بان» — چت هوشمند، چت زنده با اپراتور انسانی، RAG روی محتوای سایت، ووکامرس، بله/تلگرام/پیامک، تقویم شمسی، تیم چنداپراتوره.
- **مالک/برند:** اتحاد وردپرس — `etehadwp.com` — صفحهٔ محصول: `https://etehadwp.com/pasokhban`
- **ماهیت:** محصول **پولی**. کد افزونه هرگز نباید عمومی شود.
- **GitHub تجاری اصلی:** `github.com/etehadwp/pasokhban` → فقط ویترین و مستندات.
- **مخزن خصوصی (منبع حقیقت):** `github.com/Masoumiofficial/pasokhban` → سورس، ZIPها، `docs/` کامل.

## ۲) ساختار فنی (همین وضعیت)

- وردپرس ≥ ۵.۸، PHP ≥ ۷.۴، SPA vanilla JS با history pushState در فرانت‌اند.
- فایل‌های کلیدی:
  - `pasokhban.php` — هدر افزونه، ثابت `PASOKHBAN_VERSION`، لودرها
  - `includes/class-settings.php` — صفحهٔ تنظیمات (رندر تک‌فانکشنی با تب‌ها/کارت‌ها) + ابزارهای صادر/وارد
  - `includes/class-providers.php` — ارائه‌دهنده‌های AI و مسیرهای REST مخصوص خودشان
  - `assets/js/pasokhban-settings.js` — منطق تب‌ها، جست‌وجوی تنظیمات، پیش‌نمایش برند
  - `assets/css/pasokhban-admin.css` — کل استایل پیشخوان
  - `uninstall.php` — حذف داده فقط اگر `uninstall_delete_data` روشن باشد
  - `google-relay.gs` — رلهٔ Apps Script برای Gemini
  - `GUIDE-fa.md` / `readme.txt` — راهنما و چنجلاگ
- چیدمان صفحهٔ تنظیمات: تب‌ها `data-panel="general|conn|assistant|chat|look|notify|team|sec|brain|health"`؛ هر تب چند `<div class="psb-set-card">`.
- **قانون چیدمان:** هر کارت فقط در تب موضوع خودش باشد (برندسازی ← تب ظاهر؛ صادر/وارد ← تب امنیت و داده؛ حذف داده‌ها ← کارت امنیت و کارایی).

## ۳) تاریخچهٔ نزدیک

- ۳.۲.۰ — ۳.۲.۲: رفع باگ‌های تنظیمات، نوار چسبان ذخیره، یکسان‌سازی نسخه‌ها.
- ۳.۲.۳ — ۳.۲.۴: بهبود تب‌ها؛ تلاش برای کارت «بازگردانی پیش‌فرض» (عمودی، بعد افقی).
- ۳.۲.۵: نوار افقی بازگردانی زیر منوی تنظیمات + بازچینش HTML.
- **۳.۲.۸ (نسخهٔ فعلی):** «گفتگوهای اخیر» سمت بازدیدکننده (فهرست + تاریخچهٔ فقط‌خواندنی)، بستن گفتگو و «گفتگوی تازه»، نوار حالت با «بازگشت به دستیار هوشمند»، و بیرون‌کشیدن توضیح ریز «حذف داده» از برچسب سوئیچ.
- ۳.۲.۷: ترمیم دکمه‌های ابزار صفحهٔ تنظیمات (webhook بله، آزمایش‌ها، ایندکس، صادرات) + مسیر بازگشت درست + نمایش نتیجهٔ import.
- **۳.۲.۶:** کاربر کل قابلیت «بازگردانی پیش‌فرض» را رد کرد → **حذف کامل** (UI، CSS، JS، فرم مخفی، هوک `admin_post_pasokhban_reset_settings`، مسیر REST `pasokhban/v1/admin/reset-section`). هم‌زمان کارت‌های جاافتاده به تب درست منتقل شدند و غلط املایی «پابرج/پabarگ» → «پابرگ» اصلاح شد.
  > نکتهٔ مهم: این سومین تلاش برای همان قابلیت بود. دیگر آن را «بهبود» نکن؛ حذف شده است.

## ۴) کارهای باز (به ترتیب اولویت)

1. **امنیت سورس:** مخزن `Masoumiofficial/pasokhban` هنوز **Public** است؛ ZIPهای ۳.۲.۳ تا ۳.۲.۵ بیرون رفته‌اند و فقط `pasokhban.3.2.7.zip` و `pasokhban.3.2.8.zip` روی `main`‌اند (بدون سورس باز؛ قابل نصب ولی نه خواندنی).
   - ابتدا مخزن را **Private** کن (Settings → General → Danger Zone).
   - سپس تاریخچه را پاک‌سازی کن تا ZIPها از کلون هم بروند:
     ```bash
     git filter-repo --invert-paths --path-glob 'pasokhban.*.zip' --invert-paths-include-commits --force
     git push --force --all origin && git push --force --tags origin
     ```
     و در GitHub هم `git gc`/support request برای پاک‌سازی کش لازم است.
2. **ساخت مخزن عمومی:** در `etehadwp` یک **Repository** بساز (نه Project board): `github.com/new` → Owner `etehadwp`، نام `pasokhban`، Visibility **Public**، هیچ تیکی (README/.gitignore/license) نزن → Create.
   بعد بستهٔ آماده را بریز داخلش:
   ```bash
   unzip pasokhban-github-repo.zip -d pasokhban-site && cd pasokhban-site
   git init -b main && git add -A && git commit -m "ویترین عمومی پاسخ‌بان — معارفه و مستندات"
   git remote add origin https://github.com/etehadwp/pasokhban.git && git push -u origin main
   ```
   سپس: Description، Website `https://etehadwp.com/pasokhban`، Topics (`wordpress`,`chatbot`,`persian`,`support`,`rag`)، Issues روشن، Social preview = `docs/img/social-preview.png`.
3. **اسکرین‌شات واقعی:** داخل `docs/img/screenshots/` فعلاً فقط README هست. ۵ اسکرین‌شات واقعی ۱۲۸۰×۸۰۰ از محیط افزونه جایگزین/اضافه شود (تنظیمات، چت زنده، اینباکس، دانش، سلامت).
4. **workflow مزاحم:** `.github/workflows/jekyll-docker.yml` روی `main` منبع حقیقت یک فایل بیخرد自动生成‌شدهٔ GitHub است؛ باید حذف شود (در بستهٔ عمومی معادل سالمش `leak-guard.yml` گذاشته شده).
5. **فروش:** لینک دریافت ZIP برای هر خریدار + `license.txt` با واترمارک دامنه/نام خریدار. یونایونک/obfuscator رد شده (شکست‌پذیر و ضدGPL‌گونه). محافظت واقعی = عمومی‌نکردن سورس + آپدیت پولی + پشتیبانی.
6. **GPL:** تا وقتی افزونه در wordpress.org نیست، الزام GPL منتشرشدن سورس وجود ندارد؛ در مخزن عمومی هم «فقط مستندات» بماند.

## ۵) قوانین کار

- همهٔ تغییرات **همین‌جا در ورک‌اسپیس** اعمال شود، بعد باندل/ZIP بازسازی و در مخزن خصوصی کامیت شود.
- نسخه‌گذاری: هم‌زمان `Version:` هدر، `PASOKHBAN_VERSION`، و `Stable tag` در `readme.txt` + ورودی چنجلاگ فارسی + `CHANGELOG.md` عمومی.
- **هرگز منتشر نکن:** `pasokhban.php`، `includes/`، `assets/js|css`، `google-relay.gs`، `uninstall.php`، `readme.txt` وردپرسی، هر `*.zip`، لاگ/`wp-config` واقعی، پوشهٔ `NOTES/`.
- **مجاز برای مخزن عمومی (۲۱ فایل):** `README.md`, `CHANGELOG.md`, `LICENSE.md`, `SECURITY.md`, `SUPPORT.md`, `CONTRIBUTING.md`, `.gitignore`, `.github/workflows/leak-guard.yml`, `.github/PULL_REQUEST_TEMPLATE.md`, `.github/ISSUE_TEMPLATE/{config,bug_report,feature_request,docs_review}.yml`, `docs/{guide-fa.md,faq-fa.md,pasokhban-guide-fa.pdf}`, `docs/img/{brand-mark.svg,hero.png,etehad-logo.png,social-preview.png,screenshots/README.md}`.

### ۳.۲.۸ (نسخهٔ فعلی) — گفتگوهای اخیر بازدیدکننده
- **هیچ مهاجرت دیتابیسی ندارد.** فهرست کلیدهای کهنه در `localStorage` کلید `pasokhban_live_threads` (حداکثر ۱۰) می‌ماند؛ کلید نشست خودش مدرک مالکیت است و در هیچ جای دیگری فهرست نمی‌شود.
- endpoint واحد: `POST pasokhban/v1/live/threads` با `action ∈ {list, read, new, close, ai}`. بدنه JSON است → در PHP `get_json_params()` و بعد `get_params()`.
- `list` و `read` عمداً **بدون rate-limit**‌اند (کاربر نباید از تاریخچهٔ خودش بیرون بماند)؛ فقط `new/close/ai` پشت `is_rate_limited('live')`.
- `threadKeys()` کلید جاری را فیلتر می‌کند، اما `rememberThread()` **نباید** فیلتر کند — باگ همین دو خط باعث شد تاریخچه هیچ‌وقت رشد نکند.
- نمای تاریخچه باید `S.seenIds` و `S.lastId` را دست نزند (پیوست poll زنده خراب می‌شود) و هر پیامی که سرور در همان پاسخ فرستاد با `markSeen(d.message.id)` علامت بخورد.
- `close` → `startPoll(false)` (متوقف نکن؛ اگر اپراتور باز کرد، زود دِریافت شود). `new` → سرور نشست قبلی را می‌بندد، پس کلاینت دوباره `close` نزند.
- `updateModeBar()` تنها نقطهٔ به‌روزرسانی نوار حالت **و قفل/بازکردن `#psb-input`** است؛ `#psb-human` تنها وقتی رندر می‌شود که `humanHandoff` روشن باشد.
- تست رفتاری: `node /home/user/work/test-threads-jsdom.js` (۵۳ assertion، jsdom در `/tmp/jsdomtest`).

### ۳.۲.۷ — کلاس باگ «دکمهٔ ابزار»
- **قانون طلایی:** هیچ `<button>` با ویژگی `form="..."` نباید داخل `<form id="psb-set-form">` باشد؛ HTML فرم تودرتو را قبول نمی‌کند، پس `form=` نادیده گرفته می‌شود و کلیک، فرم تنظیمات را submit می‌کند. الگوی صحیح: `type="button" data-psb-form="psb-…-form"` (+ `data-psb-confirm` برای تایید) و هندلر عمومی بخش «۶.۱» در `assets/js/pasokhban-settings.js`.
- **قانون دوم:** اسلاگ واقعی صفحهٔ تنظیمات `pasokhban` است (`add_menu_page` + submenu با همان اسلاگ). هر redirect به `page=pasokhban-settings` پیام «دسترسی ندارید» می‌دهد. تب‌ها با هَش باز می‌شوند: general/conn/assistant/chat/look/notify/team/sec/brain/health.
- ابزارها گزینه‌های **ذخیره‌شده** را می‌خوانند؛ اگر `dirty` باشد JS اجرا نمی‌کند و `.psb-set-savebar.is-hint` هشدار می‌دهد.
- نتیجهٔ کارهای admin-post با transient رد و بدل می‌شود و باید همان‌جا خوانده شود (`pasokhban_bale_notice`، `pasokhban_notify_test`، `pasokhban_sms_test`، `pasokhban_provider_test`، `pasokhban_rag_notice`، `pasokhban_tools_notice`). هیچ transient ست‌شده‌ای نباید بی‌خواننده بماند.

## ۶) دستاوردهای موجود روی دیسک

- بستهٔ نصب افزونه (نسخهٔ ۳.۲.۸): `pasokhban.3.2.8.zip` — ۴۸ فایل، ریشهٔ داخل zip `pasokhban/` (۵۷۴٬۴۶۴ بایت)، sha256 `b8b0cc3f55952839d637590fd7af9dc0d961d0efbdbeb498fd0a7adbdaa968ad`.
- بستهٔ ۳.۲.۷: `pasokhban.3.2.7.zip` — ۵۶۷٬۰۲۰ بایت، sha256 `e9b96013ba8951f7319d092dc63b027793acc4a8454fd75cad8af49b5f7bdff3` (هنوز روی `main` برای کسانی که آپدیت نکرده‌اند).
- ریلیز گیت‌هاب: `v3.2.8` (asset آپلود نمی‌شود چون `uploads.github.com` در سندباکس بسته است؛ لینک `raw/main/<file>.zip` کار می‌کند).
- ZIPهای ۳.۲.۳ تا ۳.۲.۵ از مخزن بیرون رفته‌اند: `/home/user/work/legacy-zips/`.
- بستهٔ ویترین عمومی (۲۱ فایل + PDF): `pasokhban-github-repo.zip` — فایل‌ها در **ریشهٔ آرشیو**.

## ۷) دستور build افزونه (دقیقاً همین)

```bash
rm -rf /tmp/pb-build && mkdir -p /tmp/pb-build
cp -r <پوشهٔ سورس> /tmp/pb-build/ && rm -rf /tmp/pb-build/pasokhban.bak
(cd /tmp/pb-build && zip -qrX /home/user/pasokhban.X.Y.Z.zip pasokhban)
```
اگر ریشهٔ zip `pasokhban` نباشد، وردپرس «افزونه پیدا نشد» می‌دهد.

## ۸) بازبینی (بدون php در سندباکس)

- گریپ کامل برای باقی‌ماندهٔ قابلیت حذف‌شده: `grep -rn "psb-reset\|reset_settings\|reset-section" .` باید خالی باشد.
- توازن HTML فانکشن رندر با `html.parser` (پایتون) — صفر خطا.
- `node --check assets/js/pasokhban-settings.js`.
- توازن `{}` در PHP و مقایسهٔ دلتای `()`, `<?php`/`?>` با نسخهٔ قبلی (phply توان `?:` را ندارد؛ خطای هر دو نسخه باید یکسان باشد).
- PDF قابل rastersize نیست (no pdftoppm/ImageMagick blocked) → اعتبارسنجی با اندازه/تعداد صفحه/`pypdf.extract_text`.

## ۹) لحن پاسخ‌ها

فارسی روان، کوتاه، بدون اغراق. وقتی کاربر چیزی را دو بار رد کرد، دیگر نسخهٔ دومش را نساز — حذفش کن.
