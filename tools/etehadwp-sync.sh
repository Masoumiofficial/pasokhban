#!/usr/bin/env bash
# =============================================================================
# هم‌سازی مخزن عمومیِ مستندات (etehadwp/pasokhban) با بستۀ این نسخّه.
#
# توکن sandbox روی آن مخزن فقط pull دارد، پس این اسکریپت را روی رایانۀ خودت
# (جایی که به مخزن etehadwp/pasokhban نوشت داری) اجرا کن:
#
#     bash tools/etehadwp-sync.sh              # پیش‌فرض: branch = main
#     BRANCH=docs-1.0.0 bash tools/etehadwp-sync.sh   # برای PR دادن
#
# کاری که می‌کند: مخزن عمومی را clone می‌کند، کل محتوای داخل
# pasokhban-github-repo.zip را جایگزین working tree می‌کند (بدون دست‌زدن به .git)،
# commit می‌زند و push می‌کند. اگر فقط بخواهی ببینی چه چیزی عوض می‌شود،
# DRY=1 بگذار.
# =============================================================================
set -euo pipefail

REPO="${REPO:-git@github.com:etehadwp/pasokhban.git}"
BRANCH="${BRANCH:-main}"
DRY="${DRY:-0}"
PKG="${PKG:-pasokhban-github-repo.zip}"

[ -f "$PKG" ] || { echo "❌ $PKG را در ریشۀ مخزن خصوصی پیدا نکردم." >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
echo "↻ clone: $REPO"
git clone --quiet "$REPO" "$WORK/pub"
cd "$WORK/pub"

# پاک‌کردن ردپای نسخۀ قبلی (مستثنی: .git و فایل‌های محلیِ ignore‌شده)
find . -mindepth 1 -maxdepth 1 ! -name '.git' -exec rm -rf {} +
unzip -q "$OLDPWD/$PKG" -d .
git add -A
if [ "$DRY" = "1" ]; then
  echo "── تغییرات (DRY) ──"; git status --short; git diff --cached --stat | tail -5
  echo "✅ DRY: چیزی commit/push نشد."; exit 0
fi
VER="$(grep -m1 -oE '^#+ *[۰-۹0-9.]+' CHANGELOG.md | tr -d '# ')"
git commit -q -m "docs: هم‌سازی مستندات با نسخۀ ${VER:-جاری} (لانچ)"
echo "→ push روی ${REPO} شاخۀ ${BRANCH}"
git push -q origin "HEAD:${BRANCH}"
echo "✅ مستندات عمومی روی ${BRANCH} به‌روز شد."
