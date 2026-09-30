#!/bin/bash
# Disk Forecast: read-only pre-install check.
#
# Prints the files and figures the plugin reads, so they can be checked before it is
# installed. It only reads Unraid's state files and filesystem counters: nothing is written,
# created, started or stopped, and nothing looks inside your folders, so no disk is woken.
# Run it on the server and paste the output back:
#
#   bash check-server.sh > diskforecast-check.txt

section() { printf '\n===== %s =====\n' "$1"; }

section "Unraid and PHP"
cat /etc/unraid-version 2>/dev/null
php -r 'echo "PHP ", PHP_VERSION, "\n";' 2>/dev/null || echo "php not found"
php -m 2>/dev/null | tr '\n' ' ' | fold -w 120; echo

section "Disks (disks.ini: name, type, status, filesystem)"
awk -F= '/^\[/{print ""; print} /^(name|type|status|fsType|fsStatus|fsSize|fsFree|fsUsed)=/{print "  " $0}' /var/local/emhttp/disks.ini 2>/dev/null || echo "disks.ini not found"

section "Pools (/boot/config/pools)"
ls -1 /boot/config/pools 2>/dev/null || echo "no pools folder"

section "Shares (shares.ini: placement fields only)"
awk -F= '/^\[/{print ""; print} /^(name|include|exclude|useCache|cachePool|cachePool2)=/{print "  " $0}' /var/local/emhttp/shares.ini 2>/dev/null || echo "shares.ini not found"

section "Share config files (/boot/config/shares, placement fields only)"
for f in /boot/config/shares/*.cfg; do
  [ -e "$f" ] || { echo "none"; break; }
  echo "$(basename "$f"):"
  grep -E '^share(Include|Exclude|UseCache|CachePool|CachePool2)=' "$f" | sed 's/^/  /'
done

section "Global share settings (/boot/config/share.cfg)"
grep -E '^shareUser(Include|Exclude)=' /boot/config/share.cfg 2>/dev/null || echo "none"

section "Mounts under /mnt"
awk '$2 ~ "^/mnt/" {print $2, $3}' /proc/mounts

section "Space as the plugin will read it (statvfs, bytes)"
awk '$2 ~ "^/mnt/[^/]+$" {print $2}' /proc/mounts | sort -V | while read -r mount; do
  php -r '$m = $argv[1]; printf("%-22s size %16s  free %16s\n", $m, number_format(disk_total_space($m)), number_format(disk_free_space($m)));' "$mount" 2>/dev/null
done

section "df for comparison"
df -B1 --output=target,fstype,size,used,avail $(awk '$2 ~ "^/mnt/[^/]+$" {print $2}' /proc/mounts | sort -V) 2>/dev/null

section "Notify script"
ls -l /usr/local/emhttp/webGui/scripts/notify 2>/dev/null || echo "not found"

section "Done"
echo "Nothing was changed."
