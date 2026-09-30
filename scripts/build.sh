#!/usr/bin/env bash
# Builds the plugin into build/: the Slackware package build/<name>-<version>-noarch-1.txz
# and the manifest build/<name>.plg rendered from plugin/<name>.plg.in.
# The same script runs locally and in CI.
#
# Usage: scripts/build.sh --version YYYY.MM.DD[.N]
#
# SOURCE_DATE_EPOCH (default: the last commit's time) fixes every timestamp in the package,
# so building the same commit twice gives byte-identical output.
set -euo pipefail

name=diskforecast
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

version=""
while [ $# -gt 0 ]; do
  case "$1" in
    --version) version="${2:-}"; shift 2 ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done
if [ -z "$version" ]; then
  echo "Usage: scripts/build.sh --version YYYY.MM.DD[.N]" >&2
  exit 2
fi

# shellcheck source=../plugin/plugin.conf
. "${PLUGIN_CONF:-$root/plugin/plugin.conf}"

pkg="$name-$version-noarch-1"
build="$root/build"
stage="$build/stage"
rm -rf "$build"
mkdir -p "$stage"
cp -R "$root/src/." "$stage/"

# Permissions: directories 755, files 644, scripts and event hooks 755.
plugin="$stage/usr/local/emhttp/plugins/$name"
find "$stage" -type d -exec chmod 755 {} +
find "$stage" -type f -exec chmod 644 {} +
find "$plugin/scripts" "$plugin/event" -type f -exec chmod 755 {} +

: "${SOURCE_DATE_EPOCH:=$(git -C "$root" log -1 --format=%ct 2>/dev/null || echo 0)}"
XZ_OPT="-6 -T1" tar --create --xz --file "$build/$pkg.txz" \
  --owner=0 --group=0 --numeric-owner --sort=name \
  --mtime="@$SOURCE_DATE_EPOCH" -C "$stage" .
rm -rf "$stage"

md5="$(md5sum "$build/$pkg.txz" | cut -d' ' -f1)"

xml_escape() { sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g'; }
sed_escape() { sed -e 's/[\\|&]/\\&/g'; }

# <CHANGES>: every "## <version>" section of CHANGELOG.md as "### <version>" plus its bullets.
awk '/^## / { sub(/^## /, "### "); print; next } /^- / { print }' "${CHANGELOG:-$root/CHANGELOG.md}" \
  | xml_escape > "$build/changes.txt"

support="$(printf '%s' "$SUPPORT_URL" | xml_escape | sed_escape)"
sed -e "s|@VERSION@|$version|g" \
    -e "s|@MD5@|$md5|g" \
    -e "s|@SUPPORT_URL@|$support|g" \
    -e "s|@REPOSITORY@|$(printf '%s' "$REPOSITORY" | sed_escape)|g" \
    -e "s|@MIN_VERSION@|$(printf '%s' "$MIN_VERSION" | sed_escape)|g" \
    "$root/plugin/$name.plg.in" \
  | awk -v changes="$build/changes.txt" '
      $0 == "@CHANGES@" { while ((getline line < changes) > 0) print line; next }
      { print }
    ' > "$build/$name.plg"
rm -f "$build/changes.txt"

echo "Built build/$pkg.txz (md5 $md5)"
echo "Built build/$name.plg"
