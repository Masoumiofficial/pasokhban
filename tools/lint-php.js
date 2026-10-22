#!/usr/bin/env node
/**
 * ابزار بررسی ساختاری PHP (بدون نیاز به نصب PHP)
 *
 *   node tools/lint-php.js <ریشۀ افزونه>
 *
 * هر فایل .php را با گرامر PHP 7.4 و 8.3 پارس می‌کند؛ اگر جملۀ کلاسی/تابعی
 * به‌هم‌ریخته باشد همین‌جا می‌گیرد. خروجی: تعداد فایل‌های سالم و خطاها.
 * (نکتۀ قدیمی: فراخوانی استاتیکِ متد غیراستاتیک را این ابزار نمی‌گیرد —
 *  برای آن tools/verify-release.py بخش «static-call audit» را ببینید.)
 */
'use strict';
const fs = require('fs');
const path = require('path');
const parser = require('php-parser');

const root = process.argv[2];
if (!root) {
  console.error('❌ مصرف: node tools/lint-php.js <ریشۀ افزونه>');
  process.exit(2);
}

function walk(dir, out) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name.endsWith('.php')) out.push(p);
  }
  return out;
}

const files = walk(root, []).sort();
const grammars = { '7.4': 704, '8.3': 803 };
let bad = 0;

for (const f of files) {
  const code = fs.readFileSync(f, 'utf8');
  for (const [label, ver] of Object.entries(grammars)) {
    try {
      new parser({ parser: { phpVersion: ver, suppressErrors: false }, ast: { withPositions: true } }).parseCode(code);
    } catch (err) {
      bad++;
      console.log(`  ✖ ${path.relative(root, f)} [PHP ${label}] ${String(err.message).split('\n')[0]}`);
    }
  }
}
console.log(`  ${files.length} فایل PHP × ${Object.keys(grammars).length} گرامر — ${bad ? bad + ' خطا' : 'بدون خطای ساختاری'}`);
process.exit(bad ? 1 : 0);
