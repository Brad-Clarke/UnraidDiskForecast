<?php

declare(strict_types=1);

namespace DiskForecast;

/**
 * Human-readable sizes, durations and dates for notifications, following the date format
 * (Settings > Date and Time) and number format (Settings > Display) chosen in Unraid.
 * The web pages do the same in assets/common.js.
 */
final class Format
{
    private const MINUTE = 60;
    private const HOUR = 3600;
    private const DAY = 86400;
    private const MONTH = 2629800;
    private const YEAR = 31557600;

    /** strftime tokens Unraid's date formats use, as PHP date() letters. */
    private const DATE_TOKENS = ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%e' => 'j', '%B' => 'F', '%b' => 'M'];

    private readonly string $decimal;
    private readonly string $group;

    /**
     * @param string $dateFormat Unraid's date format, such as "%A, %e %B %Y", or "%c" for the system's own.
     * @param string $numberFormat Unraid's number format: the decimal mark, then the thousands separator (if any), such as ".," or ", ".
     */
    public function __construct(
        private readonly string $dateFormat = '%c',
        string $numberFormat = '.,',
    ) {
        $this->decimal = substr($numberFormat, 0, 1) ?: '.';
        $this->group = substr($numberFormat, 1, 1);
    }

    /**
     * The formats set in Unraid's display settings, or the defaults when they cannot be read.
     */
    public static function fromUnraid(string $dynamixCfg = '/boot/config/plugins/dynamix/dynamix.cfg'): self
    {
        $cfg = is_file($dynamixCfg) ? @parse_ini_file($dynamixCfg, true, INI_SCANNER_RAW) : false;
        $display = is_array($cfg) && is_array($cfg['display'] ?? null) ? $cfg['display'] : [];
        $value = static fn (string $key, string $default): string => trim((string) ($display[$key] ?? $default), '"');

        return new self($value('date', '%c'), $value('number', '.,'));
    }

    /**
     * Decimal bytes, as Unraid shows them: "38.2 TB", "312 GB" (or "38,2 TB" with a decimal comma).
     */
    public function bytes(float $bytes): string
    {
        foreach (['PB' => 1e15, 'TB' => 1e12, 'GB' => 1e9, 'MB' => 1e6, 'KB' => 1e3] as $unit => $scale) {
            if (abs($bytes) >= $scale) {
                $value = $bytes / $scale;

                return $this->number($value, abs($value) >= 100 ? 0 : 1) . " {$unit}";
            }
        }

        return $this->number($bytes, 0) . ' B';
    }

    /**
     * A number with the chosen decimal mark and thousands separator.
     */
    public function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, $this->decimal, $this->group);
    }

    /**
     * The two largest units of a duration: "2 years 7 months", "5 days 4 hours", "20 minutes".
     */
    public function duration(float $seconds): string
    {
        $units = [
            ['year', self::YEAR],
            ['month', self::MONTH],
            ['day', self::DAY],
            ['hour', self::HOUR],
            ['minute', self::MINUTE],
        ];
        $remaining = max(0.0, $seconds);
        $parts = [];
        foreach ($units as [$name, $size]) {
            $count = (int) floor($remaining / $size);
            if ($count > 0 || $parts !== []) {
                if ($count > 0) {
                    $parts[] = $count . ' ' . $name . ($count === 1 ? '' : 's');
                }

                $remaining -= $count * $size;
                if (count($parts) === 2 || ($parts !== [] && $count === 0)) {
                    break;
                }
            }
        }

        return $parts === [] ? 'less than a minute' : implode(' ', $parts);
    }

    /**
     * A date in the chosen format without the weekday: "12 July 2028", "July 12, 2028",
     * "2028-07-12". The system format ("%c") reads "12 Jul 2028".
     */
    public function date(int $time): string
    {
        $format = preg_replace('/^%[Aa],?\s*/', '', $this->dateFormat) ?? '';
        if ($format === '' || $format === '%c' || preg_match('/%[^YmdeBb]/', $format) === 1) {
            return date('j M Y', $time);
        }

        $pattern = '';
        foreach (preg_split('/(%[YmdeBb])/', $format, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $pattern .= self::DATE_TOKENS[$part] ?? addcslashes($part, 'A..Za..z\\');
        }

        return date($pattern, $time);
    }
}
