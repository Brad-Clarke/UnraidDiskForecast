#!/usr/bin/env bash
# After publishing: fetches the manifest users install from and checks its version is the
# one just released, then fetches the package it points at and checks the MD5.
# Retries for a minute while GitHub's release redirect catches up.
#
# Usage: scripts/smoke-release.sh --version <version> [--prerelease]
set -euo pipefail

name=diskforecast
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=../plugin/plugin.conf
. "${PLUGIN_CONF:-$root/plugin/plugin.conf}"

version=""
prerelease=false
while [ $# -gt 0 ]; do
  case "$1" in
    --version) version="${2:-}"; shift 2 ;;
    --prerelease) prerelease=true; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done
[ -n "$version" ] || { echo "Usage: scripts/smoke-release.sh --version <version> [--prerelease]" >&2; exit 2; }

base="https://github.com/$REPOSITORY/releases"
if [ "$prerelease" = true ]; then
  plugin_url="$base/download/v$version/$name.plg"
else
  plugin_url="$base/latest/download/$name.plg"
fi
package_url="$base/download/v$version/$name-$version-noarch-1.txz"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

fetch() {
  local url="$1" out="$2" attempt
  for attempt in 1 2 3 4 5 6; do
    if curl -fsSL "$url" -o "$out"; then return 0; fi
    echo "Waiting for $url (attempt $attempt)..." >&2
    sleep 10
  done
  return 1
}

published=""
for attempt in 1 2 3 4 5 6; do
  fetch "$plugin_url" "$tmp/$name.plg" || { echo "FAIL: could not fetch $plugin_url" >&2; exit 1; }
  published="$(sed -n 's/.*<!ENTITY version *"\([^"]*\)">.*/\1/p' "$tmp/$name.plg")"
  [ "$published" = "$version" ] && break
  echo "The manifest still says '$published' (attempt $attempt); waiting..." >&2
  sleep 10
done
[ "$published" = "$version" ] || { echo "FAIL: $plugin_url serves version '$published', not $version" >&2; exit 1; }
echo "ok    $plugin_url serves version $version"

fetch "$package_url" "$tmp/package.txz" || { echo "FAIL: could not fetch $package_url" >&2; exit 1; }
declared="$(sed -n 's/.*<!ENTITY md5 *"\([0-9a-f]*\)">.*/\1/p' "$tmp/$name.plg")"
actual="$(md5sum "$tmp/package.txz" | cut -d' ' -f1)"
[ "$declared" = "$actual" ] || { echo "FAIL: package MD5 $actual does not match the manifest's $declared" >&2; exit 1; }
echo "ok    $package_url matches the manifest's MD5"
