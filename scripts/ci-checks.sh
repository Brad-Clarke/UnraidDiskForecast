#!/usr/bin/env bash
# Checks a build made by scripts/build.sh: the manifest is valid XML, every shell script
# (including the manifest's inline scripts) passes shellcheck, every PHP file parses, the
# package is root-owned, stays inside the plugin's folder, has executable scripts and no
# CRLF, and the manifest's MD5 matches the package. Exits non-zero on the first failure.
#
# Usage: scripts/ci-checks.sh
set -euo pipefail

name=diskforecast
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
build="$root/build"
plg="$build/$name.plg"
python="$(command -v python3 || command -v python)"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "ok    $*"; }

[ -f "$plg" ] || fail "no $plg; run scripts/build.sh first"
shopt -s nullglob
packages=("$build/$name"-*-noarch-1.txz)
[ "${#packages[@]}" -eq 1 ] || fail "expected exactly one package in build/, found ${#packages[@]}"
package="${packages[0]}"

# 1. The manifest is valid XML with its entities resolved.
if command -v xmllint >/dev/null; then
  xmllint --noout --noent "$plg" || fail "$plg is not valid XML"
else
  "$python" -c 'import sys, xml.dom.minidom; xml.dom.minidom.parse(sys.argv[1])' "$plg" || fail "$plg is not valid XML"
fi
pass "manifest is valid XML"

# 2. shellcheck: repository scripts, event hooks, and the manifest's inline scripts.
inline="$build/inline"
rm -rf "$inline"
mkdir -p "$inline"
"$python" - "$plg" "$inline" <<'PY'
import sys, xml.dom.minidom
doc = xml.dom.minidom.parse(sys.argv[1])
for n, node in enumerate(doc.getElementsByTagName("INLINE"), 1):
    text = "".join(child.data for child in node.childNodes if child.nodeType in (child.TEXT_NODE, child.CDATA_SECTION_NODE))
    with open(f"{sys.argv[2]}/inline-{n}.sh", "w", newline="\n") as out:
        out.write("#!/bin/bash\n" + text)
PY
scripts=("$root"/scripts/*.sh "$root"/dev/*.sh "$root/src/usr/local/emhttp/plugins/$name/event/"* "$inline"/*.sh)
shellcheck -s bash -x -P SCRIPTDIR "${scripts[@]}" || fail "shellcheck"
pass "shellcheck: ${#scripts[@]} scripts"
rm -rf "$inline"

# 3. Every PHP file parses.
count=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null || fail "php -l $file"
  count=$((count + 1))
done < <(find "$root/src" "$root/dev" "$root/tests" -name '*.php' -not -path "$root/dev/data/*" -print0)
pass "php -l: $count files"

# 4. Package audit.
allowed="./usr/local/emhttp/plugins/$name"
while IFS= read -r line; do
  perms="$(awk '{print $1}' <<<"$line")"
  owner="$(awk '{print $2}' <<<"$line")"
  path="$(awk '{print $6}' <<<"$line")"
  [ "$owner" = "0/0" ] || [ "$owner" = "root/root" ] || fail "$path is owned by $owner, not root:root"
  case "$path" in
    ./|./usr/|./usr/local/|./usr/local/emhttp/|./usr/local/emhttp/plugins/)
      [ "${perms:0:1}" = "d" ] || fail "$path must be a directory"
      ;;
    "$allowed"|"$allowed"/*) ;;
    *) fail "$path is outside $allowed" ;;
  esac
  case "$path" in
    "$allowed"/scripts/?*|"$allowed"/event/?*)
      [ "${perms:0:1}" = "d" ] || [ "${perms:3:1}" = "x" ] || fail "$path is not executable ($perms)"
      ;;
  esac
done < <(tar -tvJf "$package")
extract="$build/audit"
rm -rf "$extract"
mkdir -p "$extract"
tar -xJf "$package" -C "$extract"
# grep builds the \r itself (-P) because Git Bash drops a literal CR from scripts, and -U
# stops Git Bash's grep stripping CRs from the files; on Linux -U changes nothing.
crlf="$(LC_ALL=C grep -rlIUP '\r' "$extract" || true)"
rm -rf "$extract"
[ -z "$crlf" ] || fail "CRLF line endings in: $crlf"
pass "package audit: root-owned, inside $allowed, scripts executable, LF only"

# 5. The manifest's MD5 matches the package.
declared="$(sed -n 's/.*<!ENTITY md5 *"\([0-9a-f]*\)">.*/\1/p' "$plg")"
actual="$(md5sum "$package" | cut -d' ' -f1)"
if [ -z "$declared" ] || [ "$declared" != "$actual" ]; then
  fail "manifest MD5 '$declared' does not match package MD5 '$actual'"
fi
pass "manifest MD5 matches $(basename "$package")"
