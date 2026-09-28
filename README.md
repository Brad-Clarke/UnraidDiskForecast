# Disk Forecast for Unraid

Tracks how fast your array, pools, disks or shares fill up and projects when each one runs
out of space, with an estimate, a slow-to-fast range and a graph, so you can budget for new
drives before you need them.

Status: in development. Nothing here is ready to install.

## Layout

- `source/usr/local/emhttp/plugins/diskforecast/` — what gets installed on the server.
  - `include/` — readings, history files and the forecast maths.
- `dev/` — tools that run on a development machine only.
  - `Synthetic/` — reproducible fake histories with known fill dates.
  - `Baselines/` — forecast methods that lost the backtest, kept for comparison.
  - `backtest.php` — scores forecast methods against the fake histories.
- `tests/` — PHPUnit tests for the maths.
- `docs/decisions.md` — why things are the way they are.

## Development

Needs PHP 8.1 or later (Unraid 6.12 ships 8.2).

```sh
php -d memory_limit=1G dev/backtest.php            # the plugin's forecaster, per scenario
php -d memory_limit=1G dev/backtest.php fits       # trend fits and windows compared
php -d memory_limit=1G dev/backtest.php ranges     # range methods compared
```
