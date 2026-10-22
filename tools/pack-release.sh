#!/usr/bin/env bash
# ساخت بستۀ نصب گپینه — با امکان جایگزینی فایل رمزنگاری‌شدۀ لایسنس.
#
#   bash tools/pack-release.sh <درخت افزونه> <نسخه> [--encoded-license <فایل>]
#                               [--license-lib <فایل>] [--out <مسیر.zip>]
#
# نمونه (روز انتشار روی رایانهٔ خودت):
#   1) فایل gapineh-license.php را در پنل راست‌چین رمزنگاری کن و خروجی را ذخیره کن
#   2) bash tools/pack-release.sh /path/to/gapineh 1.0.0 \
#          --encoded-license ~/Downloads/gapineh-license.php \
#          --out ./gapineh.1.0.0.zip
#
# اگر --encoded-license داده نشود، همان نسخهٔ خوانا بسته‌بندی می‌شود
# (کار می‌کند، ولی کد بررسی لایسنس قابل خواندن می‌ماند).
set -euo pipefail

if [ $# -lt 2 ]; then
  sed -n '2,14p' "$0" | sed 's/^# \{0,1\}//'
  exit 1
fi

TREE="$1"; VER="$2"; shift 2
ENCODED=""; OUT=""; LIB=""
while [ $# -gt 0 ]; do
  case "$1" in
    --encoded-license) ENCODED="$2"; shift 2 ;;
    --license-lib)     LIB="$2"; shift 2 ;;
    --out)             OUT="$2"; shift 2 ;;
    *) echo "گزینهٔ ناشناخته: $1" >&2; exit 1 ;;
  esac
done

[ -d "$TREE" ] || { echo "درخت افزونه نیست: $TREE" >&2; exit 1; }
[ -f "$TREE/gapineh.php" ] || { echo "gapineh.php در ریشهٔ درخت نیست" >&2; exit 1; }
OUT="${OUT:-$(pwd)/gapineh.${VER}.zip}"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
cp -a "$TREE" "$STAGE/gapineh"
rm -rf "$STAGE/gapineh/.git" "$STAGE/gapineh/node_modules"

if [ -n "$ENCODED" ]; then
  [ -f "$ENCODED" ] || { echo "فایل رمزنگاری‌شده پیدا نشد: $ENCODED" >&2; exit 1; }
  head -c 5 "$ENCODED" | grep -q '<?php' || { echo "خروجی رمزنگار با <?php شروع نمی‌شود" >&2; exit 1; }
  if cmp -s "$ENCODED" "$STAGE/gapineh/gapineh-license.php"; then
    echo "!! فایل رمزنگاری‌شده با نسخهٔ خوانا یکسان است — احتمالاً رمزنگاری انجام نشده" >&2
  fi
  cp -f "$ENCODED" "$STAGE/gapineh/gapineh-license.php"
  echo "◈ فایل لایسنس با نسخهٔ رمزنگاری‌شده جایگزین شد: $(wc -c < "$ENCODED") بایت"
else
  echo "◈ حالت خوانا (بدون رمزنگاری) — برای انتشار تجاری از --encoded-license استفاده کن"
fi

# کتابخانهٔ لایسنس راست‌چین (ionCube) باید در ریشهٔ افزونه باشد.
LIB="${LIB:-$(cd "$(dirname "$0")/.." && pwd)/RTL_License_b0e384722fc261db.php}"
LIB_NAME="RTL_License_b0e384722fc261db.php"
LIB_SHA="5210705eb2491e92815cd01b5877475e4a69fcba"
if [ -f "$LIB" ]; then
  cp -f "$LIB" "$STAGE/gapineh/$LIB_NAME"
fi
if [ ! -f "$STAGE/gapineh/$LIB_NAME" ]; then
  echo "!! کتابخانهٔ لایسنس نیست: $LIB_NAME (با --license-lib مسیرش را بده)" >&2
  exit 1
fi
GOT_SHA="$(sha1sum "$STAGE/gapineh/$LIB_NAME" | cut -d' ' -f1)"
if [ "$GOT_SHA" != "$LIB_SHA" ]; then
  echo "!! sha1 کتابخانهٔ لایسنس نمی‌خواند: $GOT_SHA ≠ $LIB_SHA" >&2
  echo "   فایل درست را از پنل راست‌چین دوباره دانلود کن." >&2
  exit 1
fi
echo "◈ کتابخانهٔ لایسنس: $LIB_NAME ($(wc -c < "$STAGE/gapineh/$LIB_NAME") بایت، sha1 ✓)"

# هر RTL_License_*.php دیگری (فایل لایسنس دست‌کاری‌شده) نبايد در بسته باشد.
EXTRA="$(cd "$STAGE/gapineh" && ls RTL_License_*.php 2>/dev/null | grep -v "^$LIB_NAME$" || true)"
if [ -n "$EXTRA" ]; then
  echo "!! فایل لایسنس اضافی در بسته: $EXTRA" >&2
  exit 1
fi

rm -f "$OUT"
( cd "$STAGE" && zip -qrX "$OUT" gapineh -x "*.DS_Store" -x "*/.*" )
unzip -t "$OUT" >/dev/null || { echo "بستۀ ساخته‌شده سالم نیست" >&2; exit 1; }

echo "── دروازه‌های انتشار ──"
NODE_PATH="${NODE_PATH:-}" python3 "$(dirname "$0")/verify-release.py" "$STAGE/gapineh" | tail -3 || true

echo "── بسته ──"
printf '  %s\n  %s فایل، %s بایت\n  sha256: %s\n' \
  "$OUT" \
  "$(unzip -Z1 "$OUT" | grep -vc '/$')" \
  "$(wc -c < "$OUT")" \
  "$(sha256sum "$OUT" | cut -d' ' -f1)"
