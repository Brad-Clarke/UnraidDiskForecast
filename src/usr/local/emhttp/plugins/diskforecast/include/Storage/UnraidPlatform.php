<?php

declare(strict_types=1);

namespace DiskForecast\Storage;

/**
 * Reads storage from Unraid's own state files and the kernel's filesystem counters.
 *
 * Disks come from emhttp's disks.ini, pools from the pool config folder, shares from
 * emhttp's shares.ini (or the share config files when it is absent). Space is read
 * with statvfs on mounted filesystems only, so an unmounted mount point is never mistaken
 * for the RAM disk underneath it.
 */
final class UnraidPlatform implements Platform
{
    /** @var array<string, true>|null */
    private ?array $mounts = null;

    /**
     * @param string $disksIni emhttp's disk state.
     * @param string $poolsDir One <pool>.cfg per pool.
     * @param string $sharesIni emhttp's share state.
     * @param string $sharesDir One <share>.cfg per share, used when shares.ini is missing.
     * @param string $shareCfg Global share settings (disks allowed for all shares).
     * @param string $procMounts The kernel's mount table.
     */
    public function __construct(
        private readonly string $disksIni = '/var/local/emhttp/disks.ini',
        private readonly string $poolsDir = '/boot/config/pools',
        private readonly string $sharesIni = '/var/local/emhttp/shares.ini',
        private readonly string $sharesDir = '/boot/config/shares',
        private readonly string $shareCfg = '/boot/config/share.cfg',
        private readonly string $procMounts = '/proc/mounts',
    ) {
    }

    public function units(): array
    {
        $disks = [];
        foreach (self::readIni($this->disksIni, true) as $key => $section) {
            if (!is_array($section)) {
                continue;
            }

            $name = (string) ($section['name'] ?? $key);
            $type = (string) ($section['type'] ?? '');
            $status = (string) ($section['status'] ?? '');
            if ($type === 'Data' && preg_match('/^disk\d+$/', $name) === 1 && $status !== 'DISK_NP') {
                $disks[] = $name;
            }
        }

        natsort($disks);
        $pools = [];
        foreach (glob($this->poolsDir . '/*.cfg') ?: [] as $file) {
            $pools[] = basename($file, '.cfg');
        }

        natsort($pools);
        $units = [];
        foreach ($disks as $name) {
            $units[] = new StorageUnit($name, UnitKind::Disk, "/mnt/{$name}");
        }

        foreach ($pools as $name) {
            $units[] = new StorageUnit($name, UnitKind::Pool, "/mnt/{$name}");
        }

        return $units;
    }

    public function shares(): array
    {
        $units = $this->units();
        $disks = [];
        $pools = [];
        foreach ($units as $unit) {
            if ($unit->kind === UnitKind::Disk) {
                $disks[] = $unit->name;
            } else {
                $pools[] = $unit->name;
            }
        }

        $global = self::readIni($this->shareCfg, false);
        $globalInclude = self::names($global['shareUserInclude'] ?? '');
        $globalExclude = self::names($global['shareUserExclude'] ?? '');

        $shares = [];
        foreach ($this->shareSettings() as $name => $settings) {
            $useCache = $settings['useCache'] ?: 'no';
            $secondaryPool = $settings['cachePool2'];
            $members = [];
            if ($useCache === 'no' || ($secondaryPool === '' && in_array($useCache, ['yes', 'prefer'], true))) {
                $include = self::names($settings['include']) ?: $disks;
                if ($globalInclude !== []) {
                    $include = array_intersect($include, $globalInclude);
                }

                $exclude = array_merge(self::names($settings['exclude']), $globalExclude);
                $members = array_values(array_intersect($disks, array_diff($include, $exclude)));
            }

            if ($useCache !== 'no') {
                $members[] = $settings['cachePool'] ?: 'cache';
            }

            if ($secondaryPool !== '') {
                $members[] = $secondaryPool;
            }

            $members = array_values(array_unique(array_filter(
                $members,
                static fn (string $member): bool => in_array($member, $disks, true) || in_array($member, $pools, true),
            )));
            $shares[] = new ShareInfo((string) $name, $members);
        }

        usort($shares, static fn (ShareInfo $a, ShareInfo $b): int => strnatcasecmp($a->name, $b->name));

        return $shares;
    }

    public function space(StorageUnit $unit): ?Space
    {
        if (!$this->isMounted($unit->mount)) {
            return null;
        }

        $total = @disk_total_space($unit->mount);
        $free = @disk_free_space($unit->mount);
        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        return new Space((int) $total, (int) $free);
    }

    public function pathAvailable(string $path): bool
    {
        if (preg_match('#^(/mnt/[^/]+)/#', $path, $match) !== 1) {
            return false;
        }

        return $this->isMounted($match[1]);
    }

    /**
     * The appdata folder on a pool, addressed directly (/mnt/cache/appdata/diskforecast):
     * going through /mnt/user makes the share filesystem look on array disks too, which
     * can wake them. Falls back to /mnt/user when no pool holds appdata.
     */
    public function dataDir(): string
    {
        foreach ($this->units() as $unit) {
            if ($unit->kind === UnitKind::Pool && $this->isMounted($unit->mount) && is_dir("{$unit->mount}/appdata")) {
                return "{$unit->mount}/appdata/diskforecast";
            }
        }

        return '/mnt/user/appdata/diskforecast';
    }

    private function isMounted(string $mountPoint): bool
    {
        if ($this->mounts === null) {
            $this->mounts = [];
            $lines = @file($this->procMounts, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $line) {
                $fields = explode(' ', $line);
                if (isset($fields[1])) {
                    $this->mounts[str_replace('\040', ' ', $fields[1])] = true;
                }
            }
        }

        return isset($this->mounts[$mountPoint]);
    }

    /**
     * Share settings keyed by share name, from shares.ini or the share config files.
     *
     * @return array<string, array{include: string, exclude: string, useCache: string, cachePool: string, cachePool2: string}>
     */
    private function shareSettings(): array
    {
        $settings = [];
        if (is_file($this->sharesIni)) {
            foreach (self::readIni($this->sharesIni, true) as $key => $section) {
                if (is_array($section)) {
                    $settings[(string) ($section['name'] ?? $key)] = self::shareFields($section, '');
                }
            }

            return $settings;
        }

        foreach (glob($this->sharesDir . '/*.cfg') ?: [] as $file) {
            $settings[basename($file, '.cfg')] = self::shareFields(self::readIni($file, false), 'share');
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $section
     * @return array{include: string, exclude: string, useCache: string, cachePool: string, cachePool2: string}
     */
    private static function shareFields(array $section, string $prefix): array
    {
        $field = static function (string $name) use ($section, $prefix): string {
            $key = $prefix === '' ? $name : $prefix . ucfirst($name);

            return trim((string) ($section[$key] ?? ''));
        };

        return [
            'include' => $field('include'),
            'exclude' => $field('exclude'),
            'useCache' => $field('useCache'),
            'cachePool' => $field('cachePool'),
            'cachePool2' => $field('cachePool2'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $list)), 'strlen'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function readIni(string $path, bool $sections): array
    {
        if (!is_file($path)) {
            return [];
        }

        $data = @parse_ini_file($path, $sections, INI_SCANNER_RAW);
        if (!is_array($data)) {
            return [];
        }

        array_walk_recursive($data, static function (mixed &$value): void {
            $value = trim((string) $value, '"');
        });

        return $data;
    }
}
