#!/usr/bin/env bash
# Runs the built manifest's install script twice and its remove script twice inside a
# sandbox (paths under /boot, /usr/local/emhttp and /tmp redirected; crontab and removepkg
# stubbed, the cron table holding another plugin's job) and checks they are idempotent and
# need no network:
#   install: removes older cached packages, keeps the current one, creates the settings file
#            once and never overwrites the user's or the readings (an update keeps all data),
#            writes the cron file only when it changes, and puts its job in the live cron
#            table itself (Unraid's update_cron skips a plugin until its install has finished);
#   remove:  takes the job out of the cron table first, then removes the package, the plugin
#            folder, the settings and readings (saved and pending), and succeeds when run again.
# Other plugins' cron jobs are never touched.
#
# Usage: scripts/test-install-scripts.sh   (after scripts/build.sh)
set -euo pipefail

name=diskforecast
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
build="$root/build"
plg="$build/$name.plg"
python="$(command -v python3 || command -v python)"
shopt -s nullglob
packages=("$build/$name"-*-noarch-1.txz)
[ "${#packages[@]}" -eq 1 ] || { echo "Run scripts/build.sh first." >&2; exit 1; }
package="${packages[0]}"
pkg="$(basename "$package" .txz)"

sandbox="$(mktemp -d)"
trap 'rm -rf "$sandbox"' EXIT
plgdir="$sandbox/boot/config/plugins/$name"
emhttp="$sandbox/usr/local/emhttp/plugins/$name"
calls="$sandbox/calls"
mkdir -p "$sandbox/bin" "$plgdir" "$sandbox/tmp/$name"
touch "$calls"

printf '#!/usr/bin/env bash\necho "removepkg $*" >> "%s"\n' "$calls" > "$sandbox/bin/removepkg"
# crontab keeps root's table in the sandbox: -l prints it (failing when there is none), - replaces
# it once all of its input has been read, as the real crontab does.
cat > "$sandbox/bin/crontab" <<STUB
#!/usr/bin/env bash
echo "crontab \$*" >> "$calls"
case "\${@: -1}" in
  -l) cat "$sandbox/crontab" 2>/dev/null ;;
  -) cat > "$sandbox/crontab.new" && mv "$sandbox/crontab.new" "$sandbox/crontab" ;;
esac
STUB
chmod +x "$sandbox/bin/removepkg" "$sandbox/bin/crontab"
other_job="0 3 * * * /usr/local/emhttp/plugins/other.plugin/scripts/nightly >/dev/null 2>&1"
readings="$plgdir/history"
pending="$sandbox/tmp/$name/pending"

# Python writes @SANDBOX@ and bash fills it in, so both sides use the same form of the path.
"$python" - "$plg" "$sandbox/raw" <<'PY'
import sys, xml.dom.minidom
plg, prefix = sys.argv[1], sys.argv[2]
doc = xml.dom.minidom.parse(plg)
for node in doc.getElementsByTagName("FILE"):
    inline = node.getElementsByTagName("INLINE")
    if not inline:
        continue
    text = "".join(c.data for c in inline[0].childNodes if c.nodeType in (c.TEXT_NODE, c.CDATA_SECTION_NODE))
    text = (text.replace("/usr/local/sbin/update_cron", "update_cron")
                .replace("/tmp/diskforecast", "@SANDBOX@/tmp/diskforecast")
                .replace("/boot/", "@SANDBOX@/boot/")
                .replace("/usr/local/emhttp/", "@SANDBOX@/usr/local/emhttp/"))
    which = "remove" if node.getAttribute("Method") == "remove" else "install"
    with open(f"{prefix}-{which}.sh", "w", newline="\n") as out:
        out.write("#!/bin/bash\nset -e\n" + text)
PY
for which in install remove; do
  sed "s|@SANDBOX@|$sandbox|g" "$sandbox/raw-$which.sh" > "$sandbox/$which.sh"
done

failures=0
check() {
  if eval "$2"; then echo "ok    $1"; else echo "FAIL  $1"; failures=$((failures + 1)); fi
}
run() { PATH="$sandbox/bin:$PATH" bash "$sandbox/$1.sh" > /dev/null; }

check "the manifest's scripts use no network" "! grep -Eq 'curl|wget' '$sandbox/install.sh' '$sandbox/remove.sh'"

# What Unraid does before the install script: cache the package on flash and install it.
cp "$package" "$plgdir/"
touch "$plgdir/$name-2020.01.01-noarch-1.txz"
tar -xJf "$package" -C "$sandbox"
mkdir -p "$readings" "$pending"
echo "time,size,used,free" > "$readings/array.csv"
echo "1800000000,1,1,0" > "$pending/array.csv"
echo "$other_job" > "$sandbox/crontab"
jobs_of_ours() { grep -cF "plugins/$name/scripts/sample.php" "$sandbox/crontab" || true; }

run install
check "install removes older cached packages" "[ ! -e '$plgdir/$name-2020.01.01-noarch-1.txz' ]"
check "install keeps the current package cached" "[ -f '$plgdir/$pkg.txz' ]"
check "install creates the settings file from default.cfg" "cmp -s '$emhttp/default.cfg' '$plgdir/$name.cfg'"
check "install writes the cron file for later update_cron runs" "grep -Eq '^\*/15 \* \* \* \* .*/usr/local/emhttp/plugins/$name/scripts/sample\.php >/dev/null 2>&1$' '$plgdir/$name.cron'"
check "install puts its job in the live cron table itself" "[ \"\$(jobs_of_ours)\" = 1 ] && grep -qE '^\*/15 \* \* \* \* ' '$sandbox/crontab'"
check "install keeps other plugins' cron jobs" "grep -qxF '$other_job' '$sandbox/crontab'"

printf 'AUTO_TARGETS="no"\nTARGETS="0"\n' > "$plgdir/$name.cfg"
touch -d '2001-01-01' "$plgdir/$name.cron"
cron_time="$(stat -c %Y "$plgdir/$name.cron")"
run install
check "installing again succeeds and keeps the user's settings" "grep -q 'AUTO_TARGETS=\"no\"' '$plgdir/$name.cfg'"
check "installing again does not rewrite an unchanged cron file (no flash write)" "[ \"\$(stat -c %Y '$plgdir/$name.cron')\" = '$cron_time' ]"
check "installing (and so updating) keeps saved and pending readings" "[ -f '$readings/array.csv' ] && [ -f '$pending/array.csv' ]"
check "installing again leaves exactly one job in the cron table" "[ \"\$(jobs_of_ours)\" = 1 ] && grep -qxF '$other_job' '$sandbox/crontab'"

: > "$calls"
run remove
check "remove takes the job out of the cron table before removing the package" "grep -q '^crontab' '$calls' && [ \"\$(grep -n '^crontab' '$calls' | head -1 | cut -d: -f1)\" -lt \"\$(grep -n '^removepkg' '$calls' | cut -d: -f1)\" ] && grep -qx 'removepkg $pkg' '$calls'"
check "remove leaves no job of ours and keeps other plugins' jobs" "[ \"\$(jobs_of_ours)\" = 0 ] && grep -qxF '$other_job' '$sandbox/crontab'"
check "remove deletes the plugin folder" "[ ! -e '$emhttp' ]"
check "remove deletes the settings, saved readings, cron file and cached package" "[ ! -e '$plgdir' ]"
check "remove deletes the pending readings and the rest of the runtime folder" "[ ! -e '$sandbox/tmp/$name' ]"
run remove
check "removing again succeeds and still keeps other plugins' jobs" "grep -qxF '$other_job' '$sandbox/crontab'"

rm -f "$sandbox/crontab"
mkdir -p "$plgdir"
tar -xJf "$package" -C "$sandbox"
run install
check "install works when there is no cron table yet" "[ \"\$(jobs_of_ours)\" = 1 ]"

[ "$failures" -eq 0 ] || { echo "$failures install script test(s) failed" >&2; exit 1; }
