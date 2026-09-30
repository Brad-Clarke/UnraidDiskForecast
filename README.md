# Disk Forecast for Unraid

Tracks how fast your array, pools, disks or shares fill up and projects when each one runs
out of space, with an estimate, a likely range, a graph and optional warnings, so you can
budget for new drives before you need them.

- **Forecast** (Tools › Disk Utilities › Disk Forecast): every target with its meter, time to
  full, date, likely range and monthly growth; a graph of history, the estimate, the range band
  and capacity (30 days to all history) with a projected-usage table; and a "What if I add a
  drive" card that shows the new full date and draws the new capacity on the graph.
- **Settings** (Settings › User Utilities › Disk Forecast): targets that are the whole array,
  any mix of disks and pools, or a share, each with its own reading interval (15 minutes to
  1 day), trend window (7 days to all history) and warning threshold.
- **Dashboard tile:** every target with its meter and time to full.
- **Warnings** through Unraid notifications.

It only reads filesystem counters: no folder is scanned and no disk is woken. See
[docs/decisions.md](docs/decisions.md) for how the forecast works and was chosen.

## Layout

- `source/usr/local/emhttp/plugins/diskforecast/`: what gets installed on the server.
  - `*.page`: the Forecast page (Tools), the Settings page and the dashboard tile.
  - `api.php`: JSON for the pages. `scripts/sample.php`: the 15-minute cron run.
  - `include/`: storage reading, history files, forecast maths, settings, warnings.
  - `assets/`: styles, scripts and Chart.js 4.4.7 (bundled, works offline).
- `plugin/`: the `.plg` template and the generated `.plg`. `archive/`: built packages.
- `dev/`: development tools. Nothing here is installed.
- `tests/`: PHPUnit tests.

## Development (on a PC, no server needed)

Needs PHP 8.1+ and Python 3 (for the build).

```sh
php -S 127.0.0.1:8765 dev/preview.php        # preview at http://127.0.0.1:8765/
php dev/smoke.php                            # cron run, warnings and Unraid file parsing, end to end
php -d memory_limit=1G dev/backtest.php      # forecast accuracy against fake histories (fits, ranges also)
python dev/build.py --version 2026.09.28     # builds archive/*.txz and plugin/diskforecast.plg
```

The preview runs the real plugin code against a made-up server (six disks, two pools, four
shares) with fake histories covering every state. `/` is the Forecast page,
`/Settings/DiskForecastSettings` the settings and `/Dashboard` the tile;
`?theme=white|black|azure|gray` switches Unraid theme and `/reset` rebuilds the data.

## Installing

1. **Check first (read-only).** Copy `dev/check-server.sh` to the server and run
   `bash check-server.sh > diskforecast-check.txt`. It prints the files and figures the
   plugin reads and changes nothing.
2. **Build:** `python dev/build.py`.
3. **Install from GitHub:** push `plugin/` and `archive/`, then in Unraid go to Plugins ›
   Install Plugin and paste the raw URL of `plugin/diskforecast.plg`
   (`--git-url` sets the base URL; it defaults to `Brad-Clarke/UnraidDiskForecast` on `main`).
   **Or offline:** copy `archive/diskforecast-<version>-noarch-1.txz` to
   `/boot/config/plugins/diskforecast/` and the `.plg` anywhere on the server, then run
   `plugin install /path/to/diskforecast.plg`. The package's MD5 matches, so nothing is downloaded.
4. Open Tools › Disk Forecast (settings are under Settings › Disk Forecast). Readings begin
   within 15 minutes, in the pool's appdata folder. The first forecast
   appears after 7 days; the range is calibrated once there is window + 30 days of history.

Uninstalling removes the plugin's files and cron line, and keeps settings and readings.
