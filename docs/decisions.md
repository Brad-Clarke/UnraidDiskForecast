# Decisions

## 2026-09-28: Space is read, never counted

Every target is measured from free-space figures the filesystem already keeps (statvfs on
each mounted array disk and pool). Nothing walks folders, so readings are instant and never
wake a sleeping disk. A mount point that is not in `/proc/mounts` is skipped, so an unmounted
disk is never mistaken for the RAM disk underneath it, and a target whose disks are not all
mounted is skipped rather than recorded as a sudden drop.

## 2026-09-28: Shares follow their disks

A share target adds up the disks and pools the share may use (its included/excluded disks, the
global include/exclude list, its primary pool and any secondary pool), so its forecast answers
"when will this share run out of room", and other shares on those disks count towards it.
A note beside the share picker says so.

## 2026-09-30: Three kinds of target, no share switch

Targets are the whole array, disks or pools (any mix, added together, so one pool is simply a
one-item choice), or a share. The "Allow share targets" switch is gone: it only made sense
while measuring a share meant scanning its folders, and nothing is scanned any more.

## 2026-09-28: Where things are kept

- **Settings:** `/boot/config/plugins/diskforecast/diskforecast.cfg`, Unraid's usual
  `key="value"` file over the packaged `default.cfg`, read with `parse_plugin_cfg` where
  Unraid provides it. Targets are numbered keys (`TARGETS="2"`, `TARGET_1_NAME="Array"`,
  ...); names may not contain `"`. Written only when the user presses Apply. While
  `AUTO_TARGETS="yes"` (the default) the plugin tracks the whole array plus each pool, hourly,
  180-day window, no warnings, so readings start at install.
- **Readings:** one CSV per target (`time,size,used,free`) in the readings folder, which is
  not a setting (2026-09-30: other plugins do not ask either). It is the first pool holding
  `appdata`, addressed directly (`/mnt/cache/appdata/diskforecast`), because a path through
  `/mnt/user` makes the share filesystem look on array disks too; `/mnt/user/appdata/diskforecast`
  when no pool holds appdata. The Settings page shows where it is. Nothing is written while
  the folder's mount is missing (array stopped). Removing a target that has readings asks
  first (how many readings, since when); its file is deleted when Apply saves the settings.
- **Forecast cache and lock:** `/tmp/diskforecast` (RAM), keyed by history size, modification
  time and window.
- **Warning state:** `<readings folder>/state/warnings.json`, written only when a warning is
  sent or cleared.

## 2026-09-30: Where the pages live

Following Unraid's convention of viewing under Tools and configuring under Settings:
- **Forecast:** Tools › Disk Utilities › Disk Forecast (`/Tools/DiskForecast`). A list of
  targets in the same row style as the dashboard tile, plus the kind, date, likely range and
  notes; the graph and table for the selected target; and a separate "What if I add a drive"
  card for the selected target (preset sizes or any size), whose result also draws the new
  capacity on the graph. The card is always shown: a target that is not filling reads
  "never (∞)" and one without a forecast yet reads "N/A". `?target=<id>` preselects a target;
  the dashboard rows link that way.
- **Settings:** Settings › User Utilities › Disk Forecast (`/Settings/DiskForecastSettings`).

## 2026-09-30: Packaging and releases follow the Unraid plugin brief

- **Layout:** `src/` is the package root; `plugin/diskforecast.plg.in` is the manifest
  template and `plugin/plugin.conf` holds the repository, support URL and minimum Unraid
  version; `build/` is output only and never committed.
- **One build script**, `scripts/build.sh --version`, used locally and in CI: normalises
  permissions, builds the `.txz` with GNU tar (root-owned, sorted, single-threaded xz, every
  timestamp set to the last commit's time), so the same commit always builds byte-identical
  output, then renders the `.plg` with the MD5 and `<CHANGES>` from `CHANGELOG.md`.
- **Manifest:** `pluginURL` is `releases/latest/download/diskforecast.plg`; the package URL
  is the versioned release asset. The install script deletes older cached packages, copies
  `default.cfg` only if there is no settings file, writes the cron file only when its line
  changes (no flash write on an ordinary boot) and needs no network. Remove stops the cron job
  first, deletes the readings (2026-10-01: the owner wants nothing left behind), then removes
  the package, the plugin folder, `/boot/config/plugins/diskforecast` (settings and cached
  package) and `/tmp/diskforecast`. The readings folder comes from `scripts/data-dir.php`,
  and only a path ending in `/appdata/diskforecast` under `/mnt` is deleted; while the array
  is stopped it cannot be reached, so it is left and the uninstall says so. Updates never run
  the remove script, so they keep settings and readings.
- **Array start:** `event/disks_mounted` takes due readings as soon as the disks mount; the
  cron run already skips while the readings folder's mount is missing.
- **Checks** (`scripts/ci-checks.sh`, `test-install-scripts.sh`, `test-release-checks.sh`)
  run in CI on every pull request and push to main, and again in the release workflow. The
  release gate (`scripts/release-checks.sh`) refuses a bad tag, an impossible date, a version
  already released, a missing changelog section, a placeholder support URL, and a repository
  that does not match `plugin.conf`. After publishing, `scripts/smoke-release.sh` fetches the
  install URL and the package and checks the version and MD5.
- **Deliberate differences from the brief:**
  - Settings are saved through the plugin's own endpoint (`api.php?action=save`, carrying
    Unraid's CSRF token) rather than a form posting to `/update.php`: targets are a list that
    is validated against the server's disks, pools and shares, and removing one deletes its
    readings only after the save succeeds. `/update.php` writes whatever it is sent and
    cannot do either.
  - The pages keep the plugin's own styles (light and dark themes follow Unraid's theme)
    rather than stock webGui styling. Nothing is loaded from outside the plugin: Chart.js is
    bundled.

## 2026-09-28: Schedule

One cron line runs every 15 minutes; each target takes a reading when its own interval has
passed (up to two minutes early counts, so cron jitter never skips a slot). The same run checks
warnings: one notification when a target first falls inside its threshold, then weekly while
it stays there, through Unraid's own notify script.

## 2026-09-28: Forecast method: Theil–Sen trend, calibrated on the target's own past

Chosen by `php dev/backtest.php`, which forecasts every week through seven fake four-year
histories using only what was known at the time and scores the result against the real
fill date.

| Method (180-day window) | Median error | 90th pct error | Range coverage |
|---|---|---|---|
| Straight average, first to last reading | 18% | 73% | n/a |
| Least-squares line | 19% | 75% | n/a |
| Theil–Sen line | 17% | 70% | 63% (block range) |
| **Theil–Sen + hindcast calibration (chosen)** | **12%** | **57%** | **67%** (see 2026-09-30 below) |

- **Trend:** Theil–Sen (median of pair slopes). A one-off 15 TB import pushed least-squares
  and straight-average errors to 55%.
- **Start point:** the newest reading, not the trend line's value. Never worse, often better.
- **Estimate and range:** the forecaster re-runs itself at weekly points in the target's own
  past and measures how far the real fill rate strayed from the trend over 30, 90, 180, 365
  and 730 days, interpolating between those horizons so projections stay smooth. The typical
  miss shifts the estimate; the 5th–95th percentile of misses is the range. Per scenario
  (median error): steady 2%, bursty 16%, deletes 21%, accelerating 3%, slowing 9%, changing
  habits 26%, one-off import 29%. The recent short-term spread is laid around the estimate
  and fades beyond the trend window (below).
- **Before there is enough history** (window + 30 days) the range is the 10th–90th percentile
  of the fill rate across week-or-longer blocks of the window (up to 12), and the page labels
  it a rough estimate.
- **Rejected ranges:** the textbook Theil–Sen confidence interval covered 4% of outcomes; a
  square-root-of-time spread covered 37%. Both assume growth is random around a fixed rate.
- **Known limit:** coverage is 67%. The misses are growth that keeps speeding up or slowing
  down, habits that switch, and one-off imports: no range built from the past sees those
  coming, and widening it only makes every range vague. Range edges read as "likely", not
  "guaranteed". Range ends past ten years show as "over 10y".
- **Window:** 180 days by default, per target. Shorter windows are slightly better under six
  months; "all history" turns the calibration off.
- **Cost:** about 0.2 s per forecast on a desktop CPU with four years of hourly readings;
  results are cached until the next reading.

## 2026-09-30: The range stays readable, and the numbers on a row agree

Faults found reviewing the preview (Array showed "full in 1y 9mo, likely 1y 1mo – over 10y"
beside "growing 925 GB a month" with 46 TB free; the cache's range fanned out below zero):
- **The slow end took a short slow spell as permanent.** The recent spread (slowest and
  fastest two-week blocks of the window) was applied as a fixed rate for any horizon,
  measured from the raw trend, so one 4 GB/day spell meant 33 years. It is now laid around
  the estimate and fades as the horizon passes the trend window (window ÷ horizon), leaving
  the calibrated spread to decide long horizons. Array now reads 1y 1mo – 3y 9mo; the real
  fill in that fake history (480 days) is inside it, and was not with the square-root fade
  tried first. Coverage 77% → 66%, width 57% → 38%: the old coverage was bought with ranges
  like "over 10y".
- **A cache the mover empties nightly fanned out to ±1.3 TB, below zero.** With a 30-day
  window the recent spread came from 2.5-day blocks, each catching a different point of the
  daily fill-and-empty cycle (−48 to +38 GB/day), while the calibrated past said ±1.6 GB/day.
  Blocks are now at least a week long (three to twelve of them) and each block's rate is a
  least-squares slope through all its points, so daily and weekly cycles cancel; projections
  never go below zero. The cache now reads 283–385 GB six months out. Coverage 66% → 67%.
- **The graph's usage line now ends on the newest reading.** Zoomed out, the line is averaged
  into points up to a day wide, so on a daily-cycling cache it ended near the daily average
  while the estimate starts from the reading itself, which looked like a 60 GB jump.
- **The growth shown was not the growth behind the date.** It was the estimate's first month;
  the calibrated estimate speeds up later. The row now shows the expected growth, free space ÷
  time to full, so the three numbers always agree.
