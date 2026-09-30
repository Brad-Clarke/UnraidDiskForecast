# Decisions

## 2026-09-28: Space is read, never counted

Every target is measured from free-space figures the filesystem already keeps (statvfs on
each mounted array disk and pool). Nothing walks folders, so readings are instant and never
wake a sleeping disk. A mount point that is not in `/proc/mounts` is skipped, so an unmounted
disk is never mistaken for the RAM disk underneath it, and a target whose disks are not all
mounted is skipped rather than recorded as a sudden drop.

## 2026-09-28: Shares are opt-in and follow their disks

Shares are off until "Allow share targets" is turned on, beside a note that explains the catch.
A share target adds up the disks and pools the share may use (its included/excluded disks, the
global include/exclude list, its primary pool and any secondary pool), so its forecast answers
"when will this share run out of room", and other shares on those disks count towards it.

## 2026-09-28: Where things are kept

- **Settings:** `/boot/config/plugins/diskforecast/settings.json`, written only when the user
  presses Apply. Until then defaults are used: the whole array plus each pool, hourly, 180-day
  window, no warnings. Readings start at install with those defaults.
- **Readings:** one CSV per target (`time,size,used,free`) in the readings folder. The default
  is the first pool holding `appdata`, addressed directly (`/mnt/cache/appdata/diskforecast`),
  because a path through `/mnt/user` makes the share filesystem look on array disks too.
  Nothing is written while the folder's mount is missing (array stopped).
- **Forecast cache and lock:** `/tmp/diskforecast` (RAM), keyed by history size, modification
  time and window.
- **Warning state:** `<readings folder>/state/warnings.json`, written only when a warning is
  sent or cleared.

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
| **Theil–Sen + hindcast calibration (chosen)** | **12%** | **57%** | **77%** |

- **Trend:** Theil–Sen (median of pair slopes). A one-off 15 TB import pushed least-squares
  and straight-average errors to 55%.
- **Start point:** the newest reading, not the trend line's value. Never worse, often better.
- **Estimate and range:** the forecaster re-runs itself at weekly points in the target's own
  past and measures how far the real fill rate strayed from the trend over 30, 90, 180, 365
  and 730 days, interpolating between those horizons so projections stay smooth. The typical
  miss shifts the estimate; the 10th–90th percentile of misses is the range. Per scenario
  (median error): steady 2%, bursty 16%, deletes 21%, accelerating 3%, slowing 9%, changing
  habits 26%, one-off import 29%.
- **Before there is enough history** (window + 30 days) the range is the 10th–90th percentile
  of the fill rate across 12 blocks of the window, and the page labels it an early estimate.
- **Rejected ranges:** the textbook Theil–Sen confidence interval covered 4% of outcomes; a
  square-root-of-time spread covered 37%. Both assume growth is random around a fixed rate.
- **Known limit:** coverage is 77% against an 80% target. The misses are growth that keeps
  speeding up and one-off imports no method can see coming. Range edges read as "likely", not
  "guaranteed". Range ends past ten years show as "over 10y".
- **Window:** 180 days by default, per target. Shorter windows are slightly better under six
  months; "all history" turns the calibration off.
- **Cost:** about 0.2 s per forecast on a desktop CPU with four years of hourly readings;
  results are cached until the next reading.
