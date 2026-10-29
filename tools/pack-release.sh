#!/usr/bin/env bash
# Build the independent installable ZIP; no marketplace license is included.
# Usage: NODE_PATH=<php-parser's node_modules> bash tools/pack-release.sh <plugin-tree> <version> [--out <zip>]
set -euo pipefail
if [[ $# != 2 && $# != 4 ]]; then
  echo 'Usage: pack-release.sh <plugin-tree> <version> [--out <zip>]' >&2
  exit 2
fi
tree=$1; version=$2; shift 2
[[ -d "$tree" && -f "$tree/gapineh.php" ]] || { echo 'Plugin tree not found' >&2; exit 2; }
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Invalid version' >&2; exit 2; }
out="$(pwd)/gapineh.${version}.zip"
if [[ $# == 2 ]]; then
  [[ $1 == --out ]] || { echo "Unknown option: $1" >&2; exit 2; }
  out=$2
fi
case "$out" in /*) ;; *) out="$(pwd)/$out" ;; esac
[[ "$out" == *.zip ]] || { echo 'Output must end in .zip' >&2; exit 2; }
[[ -d $(dirname "$out") ]] || { echo 'Output directory does not exist' >&2; exit 2; }
script_dir=$(cd "$(dirname "$0")" && pwd)
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
cp -a "$tree" "$stage/gapineh"
rm -rf "$stage/gapineh/.git" "$stage/gapineh/node_modules"
# Verify the requested filename version actually matches the plugin header.
if ! grep -Eq "^[[:space:]]*\\*[[:space:]]*Version:[[:space:]]*${version//./\\.}[[:space:]]*$" "$stage/gapineh/gapineh.php"; then
  echo 'Version does not match plugin header' >&2; exit 1
fi
python3 "$script_dir/verify-release.py" "$stage/gapineh"
( cd "$stage" && zip -qrX "$stage/package.zip" gapineh -x '*.DS_Store' -x '*/.*' )
unzip -tq "$stage/package.zip"
cp "$stage/package.zip" "$out"
printf '\nPackage: %s\n' "$out"
sha256sum "$out"
