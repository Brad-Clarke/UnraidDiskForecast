#!/usr/bin/env bash
# Refuses a release unless: the version is YYYY.MM.DD[.N], no GitHub release exists for it,
# CHANGELOG.md has a "## <version>" section, the support URL is this repository's GitHub
# issues or a forums.unraid.net topic, and (in GitHub Actions) the repository matches
# plugin/plugin.conf. Optionally writes that changelog section out as the release notes.
#
# Usage: scripts/release-checks.sh --version <version> [--notes-out <file>]
# PLUGIN_CONF and CHANGELOG override the files read, for scripts/test-release-checks.sh.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
conf="${PLUGIN_CONF:-$root/plugin/plugin.conf}"
changelog="${CHANGELOG:-$root/CHANGELOG.md}"

version=""
notes=""
while [ $# -gt 0 ]; do
  case "$1" in
    --version) version="${2:-}"; shift 2 ;;
    --notes-out) notes="${2:-}"; shift 2 ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

refuse() { echo "REFUSED: $*" >&2; exit 1; }

# 1. Version format: YYYY.MM.DD, zero-padded, optionally .N for a second release that day.
if ! [[ "$version" =~ ^([0-9]{4})\.([0-9]{2})\.([0-9]{2})(\.[1-9][0-9]*)?$ ]]; then
  refuse "version '$version' is not YYYY.MM.DD or YYYY.MM.DD.N (tag v<version>)"
fi
month=$((10#${BASH_REMATCH[2]}))
day=$((10#${BASH_REMATCH[3]}))
if [ "$month" -lt 1 ] || [ "$month" -gt 12 ] || [ "$day" -lt 1 ] || [ "$day" -gt 31 ]; then
  refuse "version '$version' is not a real date"
fi

# 2. The version has never been released: Unraid only offers an update when it changes.
if view="$(gh release view "v$version" 2>&1)"; then
  refuse "release v$version already exists; versions are never reused"
elif ! grep -qi "not found" <<<"$view"; then
  refuse "could not check for an existing release v$version: $view"
fi

# 3. CHANGELOG.md has a section for it.
grep -qxF "## $version" "$changelog" || refuse "$changelog has no '## $version' section"

# 4. The support URL is real (this repository's issues or a forum topic), and the repository
#    is the one releases go to.
# shellcheck source=../plugin/plugin.conf
. "$conf"
if [ "$SUPPORT_URL" != "https://github.com/$REPOSITORY/issues" ] \
    && ! [[ "$SUPPORT_URL" =~ ^https://forums\.unraid\.net/topic/[^[:space:]]+$ ]]; then
  refuse "SUPPORT_URL in $conf is '$SUPPORT_URL', not https://github.com/$REPOSITORY/issues or a forums.unraid.net/topic/ URL"
fi
if [ -n "${GITHUB_REPOSITORY:-}" ] && [ "$GITHUB_REPOSITORY" != "$REPOSITORY" ]; then
  refuse "running in $GITHUB_REPOSITORY but REPOSITORY in $conf is $REPOSITORY; pluginURL would point elsewhere"
fi

if [ -n "$notes" ]; then
  awk -v heading="## $version" '$0 == heading { on = 1; next } /^## / { on = 0 } on' "$changelog" > "$notes"
fi

echo "ok    v$version may be released"
