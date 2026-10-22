#!/usr/bin/env node
/**
 * دروازهٔ متدهای ناموجود: هر فراخوانی ایستا مانند `Gapineh_Foo::bar(` را
 * با متدهای تعریف‌شده در همان درخت مقایسه می‌کند.
 *
 * این همان اشتباهی است که نسخهٔ اول ۱.۰.۰ را روی هاست سفید کرد:
 * `Gapineh_License::is_pro()` صدا زده می‌شد ولی متد وجود نداشت و چون
 * افزونه هنگام بارگذاری به آن می‌رسید، خطای مرگ‌بار می‌داد.
 *
 *   NODE_PATH=... node tools/check-methods.js <درخت افزونه>
 *
 * کد خروج: ۰ سالم، ۱ متد ناموجود، ۲ خطای اجرا.
 */
'use strict';

const fs = require('fs');
const path = require('path');

let parser;
try {
  parser = require('php-parser');
} catch (e) {
  console.error('php-parser نصب نیست (NODE_PATH را تنظیم کن)');
  process.exit(2);
}

const root = process.argv[2] || '.';

function walk(dir, out = []) {
  for (const name of fs.readdirSync(dir)) {
    const p = path.join(dir, name);
    const st = fs.statSync(p);
    if (st.isDirectory()) {
      if (name === 'node_modules' || name === '.git') continue;
      walk(p, out);
    } else if (name.endsWith('.php')) {
      out.push(p);
    }
  }
  return out;
}

const files = walk(root);
const engine = new parser.Engine({
  parser: { extractDoc: false, suppressErrors: true },
  ast: { withPositions: true },
});

const classes = new Map(); // class -> Set(method)
const calls = []; // {cls, method, file, line}
const funcs = new Set();

function className(node) {
  if (!node) return null;
  if (node.kind === 'name') return String(node.name);
  if (node.kind === 'identifier') return String(node.name);
  return null;
}

function visit(node, file) {
  if (!node || typeof node !== 'object') return;
  if (Array.isArray(node)) {
    node.forEach((n) => visit(n, file));
    return;
  }
  switch (node.kind) {
    case 'class': {
      const name = className(node.name);
      if (name) {
        const methods = classes.get(name) || new Set();
        for (const m of node.body || []) {
          if (m.kind === 'method' && m.name) methods.add(String(m.name.name || m.name));
        }
        classes.set(name, methods);
      }
      break;
    }
    case 'function': {
      const name = className(node.name);
      if (name) funcs.add(name);
      break;
    }
    case 'call': {
      // php-parser 3.x: A::b() => call { what: staticlookup { what: name, offset: name } }
      const what = node.what;
      if (what && what.kind === 'staticlookup') {
        const cls = className(what.what);
        const method = what.offset ? String(what.offset.name || what.offset) : '';
        if (cls && method && /^Gapineh/.test(cls)) {
          calls.push({ cls, method, file, line: node.loc ? node.loc.start.line : 0 });
        }
      }
      break;
    }
    case 'new': {
      // new Foo() روی کلاس‌های افزونه اشکالی ندارد
      break;
    }
    default:
      break;
  }
  for (const key of Object.keys(node)) {
    if (key === 'loc' || key === 'kind') continue;
    const v = node[key];
    if (v && typeof v === 'object') visit(v, file);
  }
}

for (const f of files) {
  let ast;
  try {
    ast = engine.parseCode(fs.readFileSync(f, 'utf8'), f);
  } catch (e) {
    console.error('پارس نشد: ' + path.relative(root, f) + ' — ' + e.message);
    process.exit(2);
  }
  visit(ast, path.relative(root, f));
}

const bad = [];
for (const c of calls) {
  if (!classes.has(c.cls)) continue; // کلاس‌های وردپرس/بیرونی
  if (!classes.get(c.cls).has(c.method)) bad.push(c);
}

if (bad.length) {
  for (const b of bad) {
    console.log(`  ❌ ${b.cls}::${b.method}() تعریف نشده — ${b.file}:${b.line}`);
  }
  console.log(`\n${bad.length} فراخوانی به متد ناموجود`);
  process.exit(1);
}

console.log(`  ✅ ${classes.size} کلاس، ${calls.length} فراخوانی ایستا — همه متدها موجودند`);
process.exit(0);
