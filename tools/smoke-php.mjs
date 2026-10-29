#!/usr/bin/env node
/**
 * دودآزمای افزونه با PHP واقعی (WebAssembly).
 *
 *   npm i php-wasm
 *   NODE_PATH=./node_modules node tools/smoke-php.mjs <درخت افزونه>
 *
 * خروجی: گزارش بارگذاری، اجرای قلاب‌ها، رندر صفحه‌ها و هر warning/notice.
 * کد خروج ۱ اگر FATAL دیده شود.
 */
let PhpNode;
try {
  ({ PhpNode } = await import('php-wasm/PhpNode'));
} catch (e) {
  const fallback = process.env.PHP_WASM_PATH;
  if (!fallback) {
    console.error('php-wasm نصب نیست: npm i php-wasm  (یا PHP_WASM_PATH را به PhpNode.mjs بده)');
    process.exit(2);
  }
  ({ PhpNode } = await import(fallback));
}
import fs from 'node:fs';
import path from 'node:path';

const SRC = process.argv[2] || '.';
const PREFIX = '/var/www/html/wp-content/plugins/gapineh';

const php = new PhpNode();

// نیازمندی: npm i php-wasm
let out = '';
php.addEventListener('output', e => { out += e.detail.join(''); });
php.addEventListener('error', e => { out += 'STDERR:' + e.detail.join(''); });

async function walk(dir) {
  const res = [];
  for (const name of fs.readdirSync(dir)) {
    const p = path.join(dir, name);
    const st = fs.statSync(p);
    if (st.isDirectory()) res.push(...await walk(p));
    else res.push(p);
  }
  return res;
}

await php.run('<?php ');
const files = await walk(SRC);
for (const f of files) {
  const rel = path.relative(SRC, f).split(path.sep).join('/');
  const target = PREFIX + '/' + rel;
  const dir = target.slice(0, target.lastIndexOf('/'));
  await php.run(`<?php @mkdir('${dir}', 0777, true);`);
  const data = fs.readFileSync(f);
  const b64 = data.toString('base64');
  await php.run(`<?php file_put_contents('${target}', base64_decode('${b64}'));`);
}
for (const d of ['/var/www/html/wp-admin/includes', '/var/www/html/wp-includes']) {
  await php.run(`<?php @mkdir('${d}', 0777, true);`);
}
for (const f of ['upgrade.php','plugin.php','file.php','image.php','misc.php','media.php','class-wp-filesystem-base.php','class-wp-filesystem-direct.php','admin.php','schema.php']) {
  await php.run(`<?php file_put_contents('/var/www/html/wp-admin/includes/${f}', '<?php\n');`);
}
await php.run(`<?php file_put_contents('/run.php', base64_decode('${fs.readFileSync(new URL('./smoke/bootstrap.php', import.meta.url)).toString('base64')}'));`);
await php.run('<?php ');
php.exec = php.exec.bind(php);
const code = `<?php require '/run.php';`;
try { await php.run(code); } catch (e) { console.error('خطای اجرا: ' + e.message); process.exit(2); }
process.stdout.write(out);

if (/FATAL/.test(out)) { process.exit(1); }
