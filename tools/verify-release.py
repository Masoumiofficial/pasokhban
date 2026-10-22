#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
نگارۀ انتشار — بررسی‌های خودکار پیش از بیرون‌دادن نسخۀ پاسخ‌بان.

    python3 tools/verify-release.py <ریشۀ افزونه> [ریشۀ مخزن عمومی] [--zip فایل.zip]

سه دروازۀ امنیتی (نشتی)، سازگاری نسخۀ ۱.۰.۰، ساختار uninstall.php، فایل‌های یتیم،
 و .minها. اگر مخزن عمومی داده شد، دروازۀ نشتی روی آن هم اجرا می‌شود.
خروجی: ۰ = سبز، ۱ = قرمز.
"""
import io
import glob
import json
import os
import re
import subprocess
import sys

ROOT = None
FAIL = []
WARN = []


def ok(msg):
    print('  ✅ ' + msg)


def fail(msg):
    FAIL.append(msg)
    print('  ❌ ' + msg)


def warn(msg):
    WARN.append(msg)
    print('  ⚠️  ' + msg)


def read(p):
    return io.open(p, encoding='utf-8', errors='replace').read()


def find(root, exts):
    out = []
    for dirpath, dirs, files in os.walk(root):
        dirs[:] = [d for d in dirs if d not in ('.git', 'node_modules', 'vendor')]
        for f in files:
            if f.endswith(exts):
                out.append(os.path.join(dirpath, f))
    return sorted(out)


def strip_comments(code):
    """حذف کامنت‌های /* */ و // تا بازخوانی‌ها با توضیح‌ها قاطی نشوند."""
    code = re.sub(r'/\*.*?\*/', '', code, flags=re.S)
    code = re.sub(r'(?m)^\s*//.*$', '', code)
    return code


def main_php(root):
    """فایل اصلی افزونه را از روی سرصفحهٔ «Plugin Name» پیدا می‌کند."""
    for f in sorted(os.listdir(root)):
        if not f.endswith('.php'):
            continue
        head = read(os.path.join(root, f))[:4000]
        if re.search(r'^\s*\*?\s*Plugin Name:', head, re.M):
            return f, f[:-4]
    return None, None


def check_legacy_brand(root):
    """هیچ اثری از برند پیشین نماند، جز نگاشت مهاجرت داده در class-db.php."""
    print('\n── ۰) بهداشت برند')
    bad = []
    allowed = 0
    exts = ('.php', '.js', '.css', '.txt', '.md', '.pot', '.po', '.gs', '.json', '.html')
    for p in find(root, exts):
        for ln, line in enumerate(read(p).split('\n'), 1):
            low = line.lower()
            if 'pasokhban' not in low and '\u067e\u0627\u0633\u062e\u200c\u0628\u0627\u0646' not in line:
                continue
            # خطوط نگاشت مهاجرت: کلیدهای قدیمی که باید خوانده شوند
            if 'pasokhban_' in low:
                allowed += 1
                continue
            bad.append('%s:%d' % (os.path.relpath(p, root), ln))
    if bad:
        fail('اثر برند پیشین: %s' % ', '.join(bad[:6]))
    else:
        ok('بدون اثر برند پیشین (فقط %d خط نگاشت مهاجرت)' % allowed)


def block_of(src, brace_pos):
    """اینکس خطِ بسته‌شدنِ {ای را که در brace_pos باز شده برمی‌گرداند."""
    depth = 0
    i = brace_pos
    n = len(src)
    while i < n:
        c = src[i]
        if c == '{':
            depth += 1
        elif c == '}':
            depth -= 1
            if depth == 0:
                return i
        elif c in "'\"":
            q = c
            i += 1
            while i < n and src[i] != q:
                i += 2 if src[i] == '\\' else 1
        elif src.startswith('//', i):
            i = src.find('\n', i)
            if i < 0:
                return n
        elif src.startswith('/*', i):
            i = src.find('*/', i) + 2
        i += 1
    return n


def check_plugin(root):
    check_legacy_brand(root)
    print('\n── ۱) دروازۀ نشتی و یکپارچگی فایل‌ها')
    php = find(root, ('.php',))
    if not php:
        fail('هیچ فایل PHP پیدا نشد — مسیر درست است؟')
        return
    ok('%d فایل PHP' % len(php))
    for junk in ('.git', '.DS_Store', 'Thumbs.db', 'wp-cli.local.yml', '.env'):
        hits = [p for p in find(root, ('',)) if os.path.basename(p) == junk]
        if hits:
            fail('فایل/پوشۀ نامربوط: %s' % ', '.join(hits))
    leftovers = [p for p in find(root, ('.log', '.sql', '.bak', '.orig', '.rej', '~', '.zip'))]
    if leftovers:
        fail('فایل اضافه در بستۀ افزونه: %s' % ', '.join(leftovers))
    else:
        ok('بدون فایل اضافه (log/sql/bak/zip)')
    dbg = []
    for p in php:
        for m in re.finditer(r'\b(var_dump|print_r|phpinfo|error_log)\s*\(', read(p)):
            dbg.append('%s: %s' % (os.path.relpath(p, root), m.group(1)))
    _mf, _slug = main_php(root)
    allowed = {_mf: 1}  # یک error_log عمدی برای ثبت خطای فعال‌سازی
    counts = {}
    for d in dbg:
        counts[d.split(':')[0]] = counts.get(d.split(':')[0], 0) + 1
    for f, c in counts.items():
        if c > allowed.get(f, 0):
            fail('debug باقی‌مانده در %s (%d بار)' % (f, c))
    if not FAIL:
        ok('دیباگ/لاگ اضافی: صفر')

    print('\n── ۲) سازگاری شمارهٔ نسخه')
    _mf, _slug = main_php(root)
    head = read(os.path.join(root, _mf))
    m1 = re.search(r'^ \* Version:\s+(\S+)', head, re.M)
    m2 = re.search(_slug.upper() + r"_VERSION',\s*'([^']+)'", head)
    rdme = read(os.path.join(root, 'readme.txt'))
    m3 = re.search(r'^Stable tag:\s*(\S+)', rdme, re.M)
    vers = [m1 and m1.group(1), m2 and m2.group(1), m3 and m3.group(1)]
    m4 = re.search(r'^= (\d[^ ]*) =', rdme, re.M)
    vers.append(m4 and m4.group(1))
    if len(set(v for v in vers if v)) == 1 and all(vers):
        ok('همهجا یکسان: ' + ' = '.join(['هدر', vers[0], 'ثابت', vers[1], 'Stable tag', vers[2], 'بند اول changelog', vers[3]]))
    else:
        fail('نابرابری نسخه: %s' % vers)
    if re.search(r'^(= \d+\.\d+ =)$(?:.*\n)*?\1', rdme, re.M):
        fail('بند تکراری در changelog')
    return vers[0] if vers[0] else None


def check_launch_defaults(root):
    print('\n── ۳) پیش‌فرض‌های محافظه‌کارانۀ لانچ')
    st = read(os.path.join(root, 'includes', 'class-settings.php'))
    want = {'uninstall_delete_data': 0, 'cdn_font': 0, 'live_sound': 0, 'retention_days': 365}
    for key, val in want.items():
        m = re.search(r"'" + key + r"'\s*=>\s*(\d+)", st)
        if not m:
            fail('%s در defaults پیدا نشد' % key)
        elif int(m.group(1)) != val:
            fail('%s = %s (باید %d باشد)' % (key, m.group(1), val))
        else:
            ok('%s → %d' % (key, val))
    for other in ('knowledge', 'widget_enabled', 'rate_limit'):
        m = re.search(r"'" + other + r"'\s*=>\s*(\d+)", st)
        if m and int(m.group(1)) == 0:
            fail('پیش‌فرض %s صفر شده — باید روشن/فعال بماند' % other)

    print('\n── ۴) ساختار uninstall.php (داده فقط با رضایت پاک شود)')
    un = read(os.path.join(root, 'uninstall.php'))
    if 'WP_UNINSTALL_PLUGIN' not in un:
        fail('نگهبان WP_UNINSTALL_PLUGIN نیست')
    cond = re.search(r'if\s*\(\s*\$delete_data\s*\)\s*\{', un)
    if not cond:
        fail('شرط $delete_data پیدا نشد')
    else:
        end = block_of(un, cond.end() - 1)
        inside = un[cond.end():end]
        _mf, _slug = main_php(root)
        bad_outside = []
        for pat, label in ((r'DROP TABLE', 'DROP TABLE'), (r'delete_option\(\s*[\'\"]' + _slug + r'_options', 'delete_option(' + _slug + '_options)'), (r'remove_role', 'remove_role')):
            for m in re.finditer(pat, un):
                if not (cond.end() <= m.start() <= end):
                    bad_outside.append(label)
        if bad_outside:
            fail('این عملیات خارج از شاخۀ $delete_data است: %s' % ', '.join(sorted(set(bad_outside))))
        else:
            ok('همهٔ DROP/delete_option/remove_role داخل شاخۀ $delete_data (%d کاراکتر)' % len(inside))
        for need in tuple(_slug + '_' + t for t in ('messages', 'sessions', 'feedback', 'chunks', 'bale_links')):
            if need not in inside:
                fail('جدول %s در شاخۀ حذف نیست (باقی‌ماندۀ نسخههای قبل)' % need)
        if 'uninstall_delete_data' not in un:
            fail('uninstall.php تنظیم uninstall_delete_data را نمی‌خواند')
        else:
            ok('منبع تصمیم، گزینهٔ uninstall_delete_data است')


def check_assets(root, ver):
    print('\n── ۵) استاتیک: فایل یتیم، .min، defer')
    src_all = ''.join(read(p) for p in find(root, ('.php', '.js', '.css', '.txt')))
    orphans = []
    for p in find(root, ('.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg')):
        base = os.path.basename(p)
        if base not in src_all:
            orphans.append(base)
    if orphans:
        fail('استاتیک بدون ارجاع (یتیم): %s' % ', '.join(orphans))
    else:
        ok('هیچ تصویر یتیومی در بستۀ افزونه نیست')

    js = [p for p in find(root, ('.js',)) if not p.endswith('.min.js')]
    css = [p for p in find(root, ('.css',)) if not p.endswith('.min.css')]
    missing = []
    for p in js + css:
        mn = re.sub(r'\.(js|css)$', r'.min.\1', p)
        if not os.path.exists(mn):
            missing.append(os.path.relpath(mn, root))
    if missing:
        fail('فایل فشردهٔ ساخته‌نشده: %s (bash tools/build-assets.sh اجرا شود)' % ', '.join(missing))
    else:
        ok('%d فایل JS + %d فایل CSS → همه .min دارند' % (len(js), len(css)))
    for p in js + css:
        mn = re.sub(r'\.(js|css)$', r'.min.\1', p)
        if os.path.getsize(mn) >= os.path.getsize(p):
            warn('%s کوچکتر از منبع نیست' % os.path.basename(mn))
    for p in find(root, ('.min.js',)):
        r = subprocess.run(['node', '--check', p], capture_output=True, text=True)
        if r.returncode:
            fail('نارسایی سینتکس در %s: %s' % (os.path.basename(p), r.stderr.strip().split('\n')[0][:120]))
    _mf, _slug = main_php(root)
    pp = read(os.path.join(root, _mf))
    if "strategy' => 'defer'" not in pp:
        fail('استراتژی defer روی اسکریپت فرانت‌اند نیست')
    else:
        ok('اسکریپت فرانت‌اند با defer')
    if 'function asset_url' not in pp:
        fail('متد asset_url وجود ندارد')
    else:
        n = len(re.findall(r'asset_url\(', pp)) + sum(
            len(re.findall(r'Pasokhban::asset_url\(', read(p)))
            for p in find(root, ('.php',))
        )
        hard = sum(
            len(re.findall(r"PASOKHBAN_URL \. 'assets/(?:css|js)/[^']*\.(?:js|css)'", read(p)))
            for p in find(root, ('.php',))
        )
        if hard:
            fail('%d enqueue هنوز از PASOKHBAN_URL مستقیم استفاده می‌کند (نسخۀ فشرده بارگذاری نمی‌شود)' % hard)
        else:
            ok('همۀ enqueueها از asset_url می‌روند (%d فراخوانی)' % (n - 1))

    print('\n── ۶) بازگشت فراخوانی استاتیک (crash هنگام فعال‌سازی)')
    decl = {}   # (class, method) -> True/False  آیا static است
    for p in find(root, ('.php',)):
        code = strip_comments(read(p))
        for m in re.finditer(r'(?:final\s+)?(?:abstract\s+)?(public|private|protected)?\s*(static\s+)?function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(', code):
            if m.group(2):
                decl[m.group(3)] = True
            elif m.group(3) not in decl:
                decl[m.group(3)] = False
    for p in find(root, ('.php',)):
        code = strip_comments(read(p))
        for m in re.finditer(r'\b(?:Pasokhban|[A-Z][A-Za-z]*_(?:Settings|DB|API|Inbox|Livechat|Team|Health|Contacts|Rag| woo|Woo))::([a-z_][a-z0-9_]*)\s*\(', code):
            meth = m.group(1)
            if meth in ('__construct', 'instance'):
                continue
            if decl.get(meth) is False:
                fail('فراخوانی استاتیک ::%s() در %s — این متد static تعریف نشده' % (meth, os.path.relpath(p, root)))
    ok('بررسی static-call انجام شد (کامنت‌ها حذف، %d تعریف متد دیده شد)' % len(decl))

    print('\n── ۷) برچسب‌های فارسی‌سازی و text-domain')
    dom = 0
    miss = 0
    for p in find(root, ('.php',)):
        code = read(p)
        for m in re.finditer(r"__\(|\_e\(|\_x\(", code):
            seg = code[m.start():m.start() + 400]
            dom += 1
            if ("'" + _slug + "'") not in seg and ('"' + _slug + '"') not in seg:
                miss += 1
    if miss:
        warn('%d فراخوانی i18n بدون text-domain «%s» در خط اول (بسیاری چندخطی‌اند)' % (miss, _slug))
    else:
        ok('%d رشتهٔ ترجمه‌پذیر، همه با دامنه' % dom)


def check_license(root):
    """یکپارچگی قطعه‌کد لایسنس راست‌چین و نبودِ فایل لایسنس خریدار در بسته."""
    print('\n── ۱۰) لایسنس راست‌چین')
    check = os.path.join(root, 'gapineh-license.php')
    if not os.path.exists(check):
        fail('gapineh-license.php در ریشه نیست')
        return
    src = read(check)
    if 'Start RTL License' not in src:
        # فایل با رمزنگار راست‌چین جایگزین شده است؛ محتوا قابل بازخوانی نیست.
        warn('gapineh-license.php رمزنگاری‌شده است؛ بررسی متنی قطعه‌کد انجام نشد')
        if not src.lstrip().startswith('<?php'):
            fail('gapineh-license.php با <?php شروع نمی‌شود')
        _mf0, _slug0 = main_php(root)
        if 'gapineh-license.php' not in read(os.path.join(root, _mf0)):
            fail('فایل اصلی لایسنس را بارگذاری نمی‌کند')
        buyer = sorted(glob.glob(os.path.join(root, 'RTL_License_*.php')))
        if buyer:
            fail('فایل لایسنس خریدار داخل بسته است: %s' % ', '.join(os.path.basename(p) for p in buyer))
        else:
            ok('بدون فایل لایسنس خریدار در بسته')
        return
    must = [
        ("'RTL_License_b0e384722fc261db'", 'نام کلاس لایسنس'),
        ("'5210705eb2491e92815cd01b5877475e4a69fcba'", 'هش فایل لایسنس'),
        ('isActive', 'فراخوانی isActive'),
        ('__DIR__', 'مسیر نسبی __DIR__'),
        ('file_exists', 'نگهبان وجود فایل'),
        ("define( 'GAPINEH_PRO', true )", 'تعریف ثابت GAPINEH_PRO'),
    ]
    missing = [label for pat, label in must if pat not in src]
    if missing:
        fail('قطعه‌کد لایسنس ناقص است: %s' % ', '.join(missing))
    else:
        ok('قطعه‌کد لایسنس کامل (کلاس، هش، isActive، GAPINEH_PRO)')

    # GAPINEH_PRO فقط در همین فایل تعریف شود
    stray = []
    for p in find(root, ('.php',)):
        if os.path.abspath(p) == os.path.abspath(check):
            continue
        if re.search(r"define\(\s*'GAPINEH_PRO'", strip_comments(read(p))):
            stray.append(os.path.relpath(p, root))
    if stray:
        fail('GAPINEH_PRO بیرون از فایل لایسنس دست‌کاری شده: %s' % ', '.join(stray[:4]))
    else:
        ok('GAPINEH_PRO فقط در فایل لایسنس تعریف می‌شود')

    _mf, _slug = main_php(root)
    main = strip_comments(read(os.path.join(root, _mf)))
    m = re.search(r"if\s*\(\s*file_exists\([^)]*gapineh-license\.php[^)]*\)\s*\)\s*\{", main)
    if not m:
        fail('فایل اصلی، لایسنس را داخل نگهبان file_exists بارگذاری نمی‌کند')
    else:
        ok('بارگذاری لایسنس با نگهبان file_exists در فایل اصلی')

    api = os.path.join(root, 'includes', 'class-license-status.php')
    if not os.path.exists(api):
        fail('includes/class-license-status.php نیست')
    else:
        a = read(api)
        if 'function gapineh_is_pro' in a and 'apply_filters' in a:
            ok('دروازهٔ gapineh_is_pro با فیلتر')
        else:
            fail('gapineh_is_pro در کلاس وضعیت لایسنس نیست')

    buyer = sorted(glob.glob(os.path.join(root, 'RTL_License_*.php')))
    if buyer:
        fail('فایل لایسنس خریدار داخل بسته است: %s' % ', '.join(os.path.basename(p) for p in buyer))
    else:
        ok('بدون فایل لایسنس خریدار در بسته')


def check_public(root, ver, slug):
    print('\n── ۸) مخزن عمومی: دروازۀ نشتی')
    php = find(root, ('.php', '.inc'))
    if php:
        fail('فایل PHP در مخزن عمومی وجود دارد: %s' % ', '.join(os.path.relpath(p, root) for p in php[:5]))
    else:
        ok('صفر فایل PHP')
    code = find(root, ('.js', '.css', '.pot', '.po', '.mo'))
    if code:
        fail('کد/زبان افزونه در مخزن عمومی: %s' % ', '.join(os.path.basename(p) for p in code[:5]))
    else:
        ok('بدون JS/CSS/زبان')
    zips = find(root, ('.zip', '.tar.gz'))
    if zips:
        fail('بستۀ نصب داخل مخزن عمومی: %s' % ', '.join(os.path.basename(p) for p in zips))
    else:
        ok('بدون فایل ZIP')
    _slug = slug
    allsrc = ''.join(read(p) for p in find(root, ('.md', '.txt')))
    secrets = re.findall(r'(sk-[A-Za-z0-9]{12,}|ghp_[A-Za-z0-9]{20,}|xox[bap]-[A-Za-z0-9-]{10,}|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----)', allsrc)
    if secrets:
        fail('کلید/توکن مشکوک در متون عمومی: %d مورد' % len(secrets))
    else:
        ok('بدون کلید و توکن')
    legacy = set(re.findall(r'(?i)pasokhban', allsrc)) | set(re.findall('پاسخ‌بان|پاسخبان', allsrc))
    if legacy:
        fail('نام برند قبلی در مستندات عمومی مانده: %d مورد' % len(legacy))
    else:
        ok('بدون نام برند قبلی')
    for pat, label in (
        (r'wp_' + _slug + r'_\w+', 'نام جدول'),
        (r'' + _slug.capitalize() + r'_\w+::', 'نام کلاس/متد'),
        (r'' + _slug + r'/v1/[a-z-]+', 'نشانی endpoint'),
    ):
        hits = set(re.findall(pat, allsrc))
        if hits:
            fail('%s در مستندات عمومی لو رفته: %s' % (label, ', '.join(sorted(hits))[:220]))
        else:
            ok('بدون «%s»' % label)
    rd = read(os.path.join(root, 'README.md'))
    if 'guide.html' in rd:
        fail('پیوند شکستۀ guide.html هنوز در README عمومی است')
    for badge in re.findall(r'!?\[[^\]]*\]\(https://img\.shields\.io/badge/([^)/?]+)', rd):
        if 'version' in badge and ver and ver not in badge:
            warn('بج نسخۀ عمومی (%s) با %s هم‌خوان نیست' % (badge, ver))
    ch = read(os.path.join(root, 'CHANGELOG.md'))
    m = re.search(r'^#+\s*([0-9۰-۹.]+)', ch, re.M)
    if m:
        top = m.group(1).translate(str.maketrans('۰۱۲۳۴۵۶۷۸۹', '0123456789'))
        if ver and top != ver:
            fail('بند اول CHANGELOG عمومی %s است، نه %s' % (top, ver))
        else:
            ok('CHANGELOG عمومی از %s شروع می‌شود' % top)
    vers_in_docs = set(re.findall(r'3\.(?:[23])\.\d+|۳\.[۲۳]\.[۰-۹]', allsrc))
    if vers_in_docs:
        warn('ارجاع به شمارۀ نسخۀ داخلی در متون عمومی: %s' % ', '.join(sorted(vers_in_docs))[:200])
    else:
        ok('بدون ارجاع به شمارۀ نسخۀ داخلی')


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    global ROOT
    root = sys.argv[1]
    pub = sys.argv[2] if len(sys.argv) > 2 and not sys.argv[2].startswith('--') else None
    print('🔎 نگارۀ انتشار — ' + os.path.abspath(root))
    ROOT = root
    ver = check_plugin(root)
    check_launch_defaults(root)
    check_assets(root, ver)
    check_license(root)
    if pub:
        _pm, _pslug = main_php(os.path.abspath(root))
        check_public(os.path.abspath(pub), ver, _pslug)
    print('\n' + '─' * 54)
    if FAIL:
        print('❌ %d ایراد — انتشار ممنوع' % len(FAIL))
        return 1
    if WARN:
        print('🟡 سبز با %d هشدار' % len(WARN))
        return 0
    print('🟢 همه دروازه‌ها سبز')
    return 0


sys.exit(main())
