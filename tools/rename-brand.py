#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
rename-brand.py — تغییرنام کامل برند «پاسخ‌بان» در یک درخت کد یا مستندات.

  python3 tools/rename-brand.py --tree <dir> --slug gapineh --label 'گپینه' --camel Gapineh [--apply]

قواعد (به همین ترتیب اعمال می‌شوند تا هم‌پوشانی نداشته باشند):
  Pasokhban  → --camel     (نام کلاس‌ها، فایل‌ها، نام‌های تیتر)
  PASOKHBAN  → UPPER       (ثابت‌ها: PASOKHBAN_PATH, PASOKHBAN_VERSION, …)
  pasokhban  → --slug      (اسلاگ، text-domain، کلید گزینه‌ها، جداول، REST، هوک‌ها، نام فایل‌ها)
  پاسخ‌بان / پاسخبان / پاسخ بان → --label

بدون --apply فقط گزارش می‌دهد (dry-run). پیشوند کلاس‌های CSS یعنی `psb-` عمداً دست‌نخورده
می‌ماند: به برند اشاره نمی‌کند و تغییرش ۳۳۵۸ مورد churn بی‌فایده است.

فایل‌های .mo باینری‌اند و msgstr‌شان قابل بازنویسی نیست؛ اسکریپت آن‌ها را رد می‌کند و
هشدار می‌دهد (بعد از تغییرنام باید با msgfmt یا حذف شوند).
"""
import argparse
import os
import sys

TEXT_EXT = {
    '.php', '.js', '.css', '.txt', '.md', '.json', '.pot', '.po', '.xml',
    '.yml', '.yaml', '.sh', '.html', '.csv', '.py',
}
SKIP_DIRS = {'.git', 'node_modules', '__pycache__', '.idea', '.vscode'}
SKIP_EXT = {'.mo', '.zip', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico',
            '.pdf', '.woff', '.woff2', '.ttf', '.eot'}

# پاسخ‌بان با سه واریانت نوشتاری؛ به شکل escape نوشته شده تا ZWNJ در فایل سالم بماند.
OLD_LABELS = [
    '\u067e\u0627\u0633\u062e\u200c\u0628\u0627\u0646',   # پاسخ‌بان (ZWNJ)
    '\u067e\u0627\u0633\u062e\u0628\u0627\u0646',          # پاسخبان (بدون فاصله)
    '\u067e\u0627\u0633\u062e \u0628\u0627\u0646',         # پاسخ بان (فاصله)
]


def build_rules(slug, label, camel):
    rules = [
        ('Pasokhban', camel),
        ('PASOKHBAN', slug.upper()),
        ('pasokhban', slug),
    ]
    for old in OLD_LABELS:
        rules.append((old, label))
    return rules


def walk(root):
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
        for fn in filenames:
            yield os.path.join(dirpath, fn)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--tree', required=True)
    ap.add_argument('--slug', required=True, help='اسلاگ لاتین، مثلاً gapineh')
    ap.add_argument('--label', required=True, help='نام فارسی، مثلاً گپینه')
    ap.add_argument('--camel', required=True, help='شکل CamelCase، مثلاً Gapineh')
    ap.add_argument('--apply', action='store_true')
    args = ap.parse_args()

    root = os.path.abspath(args.tree)
    if not os.path.isdir(root):
        sys.exit('مسیر نیست: %s' % root)
    slug = args.slug.strip().lower()
    if not slug.isalnum():
        sys.exit('اسلاگ فقط حروف/ارقام لاتین باشد')
    rules = build_rules(slug, args.label.strip(), args.camel.strip())

    counts = {old: 0 for old, _ in rules}
    touched, renamed, skipped_bin = [], [], []
    total_bytes = 0

    for path in walk(root):
        rel = os.path.relpath(path, root)
        ext = os.path.splitext(path)[1].lower()
        if ext in SKIP_EXT:
            if ext == '.mo':
                skipped_bin.append(rel)
            continue
        if ext not in TEXT_EXT:
            continue
        try:
            with open(path, 'rb') as fh:
                raw = fh.read()
        except OSError as exc:
            print('  ! خواندن نشد: %s (%s)' % (rel, exc))
            continue
        try:
            text = raw.decode('utf-8')
        except UnicodeDecodeError:
            print('  ! UTF-8 نیست: %s' % rel)
            continue
        new = text
        hit = 0
        for old, repl in rules:
            n = new.count(old)
            if n:
                counts[old] += n
                hit += n
                new = new.replace(old, repl)
        if hit:
            touched.append((rel, hit))
            if args.apply:
                with open(path, 'wb') as fh:
                    fh.write(new.encode('utf-8'))
                total_bytes += len(raw)

    # تغییر نام فایل‌ها/پوشه‌ها (از عمیق به کم‌عمق)
    if args.apply:
        for dirpath, dirnames, filenames in os.walk(root, topdown=False):
            dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
            for fn in filenames:
                if 'pasokhban' in fn.lower():
                    new_fn = fn.replace('Pasokhban', args.camel) \
                               .replace('PASOKHBAN', slug.upper()) \
                               .replace('pasokhban', slug)
                    os.rename(os.path.join(dirpath, fn), os.path.join(dirpath, new_fn))
                    renamed.append((os.path.relpath(os.path.join(dirpath, fn), root), new_fn))
            for dn in dirnames:
                if 'pasokhban' in dn.lower():
                    new_dn = dn.replace('pasokhban', slug)
                    os.rename(os.path.join(dirpath, dn), os.path.join(dirpath, new_dn))
                    renamed.append((os.path.relpath(os.path.join(dirpath, dn), root), new_dn))

    print('── قواعد ──')
    for old, repl in rules:
        shown = old if old.isascii() else 'پاسخ‌بان'
        print('  %-12s → %-12s %5d مورد' % (shown, repl, counts[old]))
    print('── فایل‌های تغییر‌یافته ── %d فایل، %d مورد' %
          (len(touched), sum(c for _, c in touched)))
    print('── فایل‌های تغییرنام‌یافته ── %d' % len(renamed))
    for old, new in renamed:
        print('   %s → %s' % (old, new))
    if skipped_bin:
        print('── .mo باینری (دست‌نخورده) ── ' + ', '.join(skipped_bin))

    if not args.apply:
        print('\n(dry-run) با --apply اجرا کن.')
        return

    # پس‌بینی: هیچ اثری از نام قدیم در فایل‌های متنی نباید بماند
    residual = []
    for path in walk(root):
        ext = os.path.splitext(path)[1].lower()
        if ext not in TEXT_EXT:
            continue
        try:
            text = open(path, 'rb').read().decode('utf-8')
        except (OSError, UnicodeDecodeError):
            continue
        for old, _ in rules:
            if old in text:
                residual.append((os.path.relpath(path, root), old))
    if residual:
        print('!! %d فایل هنوز نام قدیم را دارد:' % len(residual))
        for rel, old in residual[:20]:
            print('   %s (%s)' % (rel, old if old.isascii() else 'پاسخ‌بان'))
        sys.exit(1)
    print('✓ صفر اثر از نام قدیم در فایل‌های متنی. %d بایت بازنویسی شد.' % total_bytes)


if __name__ == '__main__':
    main()
