#!/usr/bin/env bash
# Proves scripts/release-checks.sh accepts a good release and refuses each bad one, for the
# right reason. `gh` is replaced by a stub in which only v2026.01.01 has been released.
#
# Usage: scripts/test-release-checks.sh
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

mkdir "$tmp/bin"
cat > "$tmp/bin/gh" <<'STUB'
#!/usr/bin/env bash
if [ "$1 $2" = "release view" ]; then
  if [ "$3" = "v2026.01.01" ]; then echo "v2026.01.01"; exit 0; fi
  echo "release not found" >&2
  exit 1
fi
echo "unexpected gh call: $*" >&2
exit 2
STUB
chmod +x "$tmp/bin/gh"

cat > "$tmp/good.conf" <<'CONF'
REPOSITORY="example/plugin"
SUPPORT_URL="https://github.com/example/plugin/issues"
MIN_VERSION="7.0.0"
CONF
sed 's|^SUPPORT_URL=.*|SUPPORT_URL="https://forums.unraid.net/topic/123456-plugin-example/"|' "$tmp/good.conf" > "$tmp/forum.conf"
sed 's|^SUPPORT_URL=.*|SUPPORT_URL="@SUPPORT_URL@"|' "$tmp/good.conf" > "$tmp/placeholder.conf"
sed 's|^SUPPORT_URL=.*|SUPPORT_URL="https://github.com/someone/else/issues"|' "$tmp/good.conf" > "$tmp/other-issues.conf"
printf '# Changelog\n\n## 2026.02.01\n\n- Something new.\n\n## 2026.01.01\n\n- First release.\n' > "$tmp/CHANGELOG.md"

failures=0
# expect <pass|refuse> <text the output must contain> <description> <env assignments...> -- <version>
expect() {
  local want="$1" needle="$2" label="$3"
  shift 3
  local assignments=()
  while [ "$1" != "--" ]; do assignments+=("$1"); shift; done
  shift
  local got output
  if output="$(env PATH="$tmp/bin:$PATH" CHANGELOG="$tmp/CHANGELOG.md" GITHUB_REPOSITORY="example/plugin" \
      "${assignments[@]}" bash "$root/scripts/release-checks.sh" --version "$1" --notes-out "$tmp/notes.md" 2>&1)"; then
    got=pass
  else
    got=refuse
  fi
  if [ "$got" = "$want" ] && grep -qF -- "$needle" <<<"$output"; then
    echo "ok    $label"
  else
    echo "FAIL  $label: expected $want with '$needle', got $got: $output"
    failures=$((failures + 1))
  fi
}

expect pass   "may be released"        "a good release passes"            PLUGIN_CONF="$tmp/good.conf" -- 2026.02.01
expect pass   "may be released"        "a forum topic as support passes"  PLUGIN_CONF="$tmp/forum.conf" -- 2026.02.01
expect refuse "not YYYY.MM.DD"         "a badly formed tag is refused"    PLUGIN_CONF="$tmp/good.conf" -- 2026.2.1
expect refuse "not a real date"        "an impossible date is refused"    PLUGIN_CONF="$tmp/good.conf" -- 2026.13.01
expect refuse "already exists"         "a duplicate version is refused"   PLUGIN_CONF="$tmp/good.conf" -- 2026.01.01
expect refuse "no '## 2026.03.01'"     "a missing changelog section is refused" PLUGIN_CONF="$tmp/good.conf" -- 2026.03.01
expect refuse "is '@SUPPORT_URL@'"     "a placeholder support URL is refused" PLUGIN_CONF="$tmp/placeholder.conf" -- 2026.02.01
expect refuse "someone/else/issues"    "another repository's issues are refused" PLUGIN_CONF="$tmp/other-issues.conf" -- 2026.02.01
expect refuse "pluginURL would point"  "a repository mismatch is refused" PLUGIN_CONF="$tmp/good.conf" GITHUB_REPOSITORY="someone/else" -- 2026.02.01

env PATH="$tmp/bin:$PATH" CHANGELOG="$tmp/CHANGELOG.md" GITHUB_REPOSITORY="example/plugin" PLUGIN_CONF="$tmp/good.conf" \
  bash "$root/scripts/release-checks.sh" --version 2026.02.01 --notes-out "$tmp/notes.md" >/dev/null
if grep -qx -- "- Something new." "$tmp/notes.md" && ! grep -q "First release" "$tmp/notes.md"; then
  echo "ok    release notes hold only that version's section"
else
  echo "FAIL  release notes: $(cat "$tmp/notes.md")"
  failures=$((failures + 1))
fi

[ "$failures" -eq 0 ] || { echo "$failures release check test(s) failed" >&2; exit 1; }
