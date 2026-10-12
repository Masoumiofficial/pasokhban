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
- چیدمان صفحهٔ تنظیمات: تب‌ها `data-panel="general|conn|assistant|chat|look|notify|team|sec|health"` (ده تا بود؛ از ۳.۳.۰ **نه** تب)؛ هر تب چند `<div class="psb-set-card">`.
- **قانون چیدمان:** هر کارت فقط در تب موضوع خودش باشد (برندسازی ← تب ظاهر؛ صادر/وارد ← تب امنیت و داده؛ حذف داده‌ها ← کارت امنیت و کارایی).

## ۳) تاریخچهٔ نزدیک

- ۳.۲.۰ — ۳.۲.۲: رفع باگ‌های تنظیمات، نوار چسبان ذخیره، یکسان‌سازی نسخه‌ها.
- ۳.۲.۳ — ۳.۲.۴: بهبود تب‌ها؛ تلاش برای کارت «بازگردانی پیش‌فرض» (عمودی، بعد افقی).
- ۳.۲.۵: نوار افقی بازگردانی زیر منوی تنظیمات + بازچینش HTML.
- **۳.۳.۰ (نسخهٔ فعلی):** حذف کامل سبک «مغز کیهانی» و ورودی/خروجی صوتی — کاربر «کلاً حذف» گفت، پس هیچ کلید میانی برای برگرداندنش نگذاشته‌ایم.
- ۳.۲.۹: رفع قاتل فعال‌سازی در نصب تازه (`Pasokhban_Settings::defaults()` استاتیک صدا زده می‌شد) + هارنس اجرای افزونه با PHP-WASM.
- **۳.۲.۸:** «گفتگوهای اخیر» سمت بازدیدکننده (فهرست + تاریخچهٔ فقط‌خواندنی)، بستن گفتگو و «گفتگوی تازه»، نوار حالت با «بازگشت به دستیار هوشمند»، و بیرون‌کشیدن توضیح ریز «حذف داده» از برچسب سوئیچ.
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

### ۳.۳.۰ — حذف سبک «مغز کیهانی» و صوت
- **خواستهٔ کاربر:** «بخش دستیار و دانش سایت، اون مغز کیهانی — شبکهٔ عصبی متحرک + ورودی و خروجی صوتی رو کلاً حذف کن.»
- **چی رفت:** کارت انتخاب «حالت نمایش» در تب `assistant`؛ تب `brain` («ویجت مغز») با فیلدهای `greeting`/`placeholder`/`launcher_text` و سوئیچ‌های `stt`/`tts`؛ کلیدهای `widget_style` + پنج کلید صوتی/متنی از `defaults()` و `sanitize()`؛ `Pasokhban::current_style()` و شاخه‌های `if ( 'chat' === … )` در enqueue/render/shortcode؛ رندر `#pasokhban-root` (فقط `#pasokhban-live` می‌ماند)؛ endpoint `pasokhban/v1/config` + `handle_config()` + `suggested_questions()`؛ دو فایل `assets/js/pasokhban.js` و `assets/css/pasokhban.css`؛ برچسب «سبک: مغز کیهانی» و مسیر `/config` از `class-health.php`.
- **قانون:** این موارد را برنگردان، «بهبود» نده و جایگزین «حالت دوم» نساز. `window.PASOKHBAN`/`ETEHADYAR` دیگر تزریق نمی‌شوند — هر کد جدید باید از `PASOKHBAN_CHAT` / `PASOKHBAN_CHAT_SETTINGS` بخواند (برندمارک چت به همین جابرجا شد).
- **عمداً ماند:** آیکون/آواتار `brainSvg()` و کلاس‌های `.psb-brain` در `pasokhban-chat.css` — این فقط آیکون برندِ چت شیشه‌ای است، نه آن سبک. `pasokhban/v1/chat` هم برای یکپارچه‌سازی بیرونی باز است.
- **سازگاری:** ردیف `pasokhban_options` قدیمی که `widget_style => 'brain'` دارد بی‌اثر است؛ `get_options()` کلیدهای ناشناخته را نگه می‌دارد و `sanitize()` در نخستین ذخیره حذفشان می‌کند (بدون مهاجرت، بدون ریسک).
- **هارنس:** `routes: 29` (یک مسیر کمتر)، `[الف] خطای قطعی: ۰`، HTML تنظیمات با `html.parser` بدون خطا، `node --check` روی سه JS، تست jsdom ۵۳/۵۳.

### ۳.۲.۹ — قاتل فعال‌سازی و هارنس PHP
- **باگ:** در `Pasokhban::activate()` این خط، متد **نمونه** را استاتیک صدا می‌زد: `update_option( 'pasokhban_options', array( 'widget_style' => 'chat' ) + Pasokhban_Settings::defaults() )` → `Error: Non-static method … cannot be called statically` → پیشخوان: «به دلیل داشتن مشکلی جدی، افزونه فعال نشد». فقط در **نصب تازه** (ردیف `pasokhban_options` نیست) رخ می‌دهد؛ پس در آپدیت‌های قبلی دیده نشد. از ۳.۲.۷ وجود داشته.
- **قانون:** هیچ متد غیراستاتیکی را با `Class::method()` صدا نزن. پیش از ریلیز ممیزی `audit.php` را اجرا کن؛ `[الف] … خطای قطعی: 0` الزامی است.
- **هارنس:** `/home/user/work/php-harness` (PHP-WASM، پی‌اچ‌پی ۷٫۰ تا ۸٫۳). اجرا: `PHPWASM_NM=/tmp/phpw1/node_modules node /home/user/work/php-harness/run2.js <پوشه> <php-ver>`. باید `[5] هوک فعال‌سازی اجرا شد` بدون THROW چاپ شود. راه‌اندازی یک‌باره در README همان پوشه (`npm i @php-wasm/node@1.0.9` + لینک نمادین `nodeasyncify`).
- **نکتهٔ هارنس:** دو `php.run()` پشت سر هم در ۱.۰.۹ crash می‌کند → همه‌چیز را در یک run با base64-injection اجرا کن؛ `dbDelta`/`upgrade.php` را خودت بساز (وگرنه `Cannot redeclare`). تست رفتاری JS: `node /home/user/work/test-threads-jsdom.js`.
- اگر کاربر «فعال نشد» گزارش داد: اول هارنس را روی همان بسته اجرا کن، بعد نسخهٔ PHP و خط اول `debug.log` را بگیر.

### ۳.۲.۸ — گفتگوهای اخیر بازدیدکننده
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

- **بستهٔ نصب افزونه (نسخهٔ ۳.۳.۰):** `pasokhban.3.3.0.zip` — ۳۹ فایل (۴۶ ورودی با پوشه‌ها)، ۵۵۶٬۸۹۴ بایت، sha256 `b469b2151b62b56f1e40ec47184a18c79dd328d7c9ab13eececf11d284bbb2f6`؛ روی نصب تازه در PHP ۷.۴ و ۸.۳ با هارنس تأیید شد.
- بستهٔ ۳.۲.۹: `pasokhban.3.2.9.zip` — ۴۸ فایل، ریشهٔ داخل zip `pasokhban/` (۵۷۵٬۰۷۵ بایت)، sha256 `72e8fda32010d0ba86b7b2901d0dd774d74bfffcc150fa4eaf0d0dea81242a08`.
- بستهٔ ۳.۲.۸: `pasokhban.3.2.8.zip` (۵۷۴٬۵۱۳ بایت، sha256 `7c381b455a…`) — روی سایتِ تازه **فعال نمی‌شود**؛ فقط برای سایت‌هایی که از قبل `pasokhban_options` دارند بی‌خطر است.
- بستهٔ ۳.۲.۷: `pasokhban.3.2.7.zip` (۵۶۷٬۰۲۰ بایت، sha256 `e9b96013ba…`) — همان قاتل فعال‌سازی را دارد.
- بستهٔ ۳.۲.۷: `pasokhban.3.2.7.zip` — ۵۶۷٬۰۲۰ بایت، sha256 `e9b96013ba8951f7319d092dc63b027793acc4a8454fd75cad8af49b5f7bdff3` (هنوز روی `main` برای کسانی که آپدیت نکرده‌اند).
- ریلیز گیت‌هاب (فعلی): `v3.3.0`؛ `v3.2.9` و `v3.2.8` هم منتشرند (۳.۲.۸ با یادداشت «نصب نکنید») (asset آپلود نمی‌شود چون `uploads.github.com` در سندباکس بسته است؛ لینک `raw/main/<file>.zip` کار می‌کند).
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
