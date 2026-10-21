#!/usr/bin/env bash
# =============================================================================
# ساخت نسخۀ فشردهٔ فایل‌های CSS/JS افزونه (پیش از بستن ZIP)
#
#  روش:  bash tools/build-assets.sh [مسیر ریشۀ افزونه]
#   مثال:  bash tools/build-assets.sh /tmp/build/pasokhban
#
# ابزار لازم (یک‌بار):  npm i terser csso-cli
# فایل‌های .min کنار فایل اصلی ساخته می‌شوند و Pasokhban::asset_url() در حالت
# عادی همان‌ها را بارگذاری می‌کند؛ با SCRIPT_DEBUG=true نسخهٔ خوانا استفاده می‌شود.
# =============================================================================
set -euo pipefail

ROOT="${1:-pasokhban}"
command -v terser >/dev/null 2>&1 || { echo "❌ terser نصب نیست:  npm i terser csso-cli" >&2; exit 1; }
command -v csso   >/dev/null 2>&1 || { echo "❌ csso نصب نیست:   npm i terser csso-cli" >&2; exit 1; }
[ -d "$ROOT/assets" ] || { echo "❌ $ROOT/assets پیدا نشد" >&2; exit 1; }

total_in=0
total_out=0
for f in "$ROOT"/assets/js/*.js "$ROOT"/assets/css/*.css; do
  case "$(basename "$f")" in *.min.*) continue ;; esac
  out="${f%.*}.min.${f##*.}"
  if [[ "$f" == *.js ]]; then
    terser "$f" --compress passes=2 --mangle > "$out"
  else
    csso "$f" --output "$out"
  fi
  ci=$(wc -c < "$f"); co=$(wc -c < "$out")
  total_in=$(( total_in + ci )); total_out=$(( total_out + co ))
  printf '  %-38s %7d B → %7d B  (−%d%%)\n' "$(basename "$f")" "$ci" "$co" $(( (ci - co) * 100 / ci ))
done
echo "  ── مجموع: ${total_in} B → ${total_out} B (−$(( (total_in - total_out) * 100 / total_in ))%)"
echo "✅ فایل‌های .min ساخته شد"
