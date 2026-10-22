#!/usr/bin/env bash
# ساخت بستۀ نصب گپینه — با امکان جایگزینی فایل رمزنگاری‌شدۀ لایسنس.
#
#   bash tools/pack-release.sh <درخت افزونه> <نسخه> [--encoded-license <فایل>] [--out <مسیر.zip>]
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
ENCODED=""; OUT=""
while [ $# -gt 0 ]; do
  case "$1" in
    --encoded-license) ENCODED="$2"; shift 2 ;;
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

# فایل لایسنس خریدار هرگز نباید در بسته برود.
if find "$STAGE/gapineh" -maxdepth 1 -name 'RTL_License_*.php' | grep -q .; then
  echo "!! فایل لایسنس خریدار در بسته است؛ حذفش کن" >&2
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
