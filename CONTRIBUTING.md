# Contributing

## Layout

- `src/` is the package root and mirrors the Unraid filesystem:
  `src/usr/local/emhttp/plugins/diskforecast/` holds the pages (`*.page`), the JSON endpoint
  (`api.php`), the cron script (`scripts/sample.php`), array event hooks (`event/`), the
  PHP code (`include/`), styles and scripts (`assets/`, with Chart.js 4.4.7 bundled) and
  `default.cfg`.
- `plugin/` holds the manifest template (`diskforecast.plg.in`), `plugin.conf` (repository,
  support URL, minimum Unraid version) and the Community Apps icon.
- `scripts/` holds the build, the checks and the release gate. `.github/workflows/` only
  calls them.
- `dev/` (preview server, smoke test, backtest, fixtures) and `tests/` are never installed.
- `build/` is output only and never committed.
- Why things are the way they are: [docs/decisions.md](docs/decisions.md).

## Develop

Needs bash, GNU tar, xz, PHP 8.1+ and Python 3; `shellcheck` and `xmllint` for the checks.

```sh
php -S 127.0.0.1:8765 dev/preview.php   # the real pages against a fake server
php dev/smoke.php                       # cron run, saving readings, warnings, settings, Unraid parsing
php -d memory_limit=1G dev/backtest.php # forecast accuracy against fake histories
```

The preview serves the Forecast page at `/`, settings at `/Settings/DiskForecastSettings`
and the dashboard tile at `/Dashboard`. `?theme=white|black|azure|gray` switches Unraid
theme and `/reset` rebuilds the fake data.

## Build and check

```sh
scripts/build.sh --version 2026.09.30   # build/diskforecast-<version>-noarch-1.txz and build/diskforecast.plg
scripts/ci-checks.sh                    # XML, shellcheck, php -l, package audit, MD5
scripts/test-install-scripts.sh         # install/remove scripts are idempotent and offline
scripts/test-release-checks.sh          # the release gate refuses what it should
```

The same commit always builds byte-identical output. CI runs all of this on every pull
request and push to `main`, and uploads the build so it can be installed by hand.

## Release

1. Add a `## <version>` section to `CHANGELOG.md`. Versions are `YYYY.MM.DD`, or
   `YYYY.MM.DD.N` for a second release that day, and are never reused.
2. Tag and push: `git tag v<version> && git push origin v<version>`. The Release workflow
   checks the tag, the changelog and the support URL, builds, runs every check, publishes the
   `.plg` and `.txz` to a GitHub release, and confirms the install URL serves the new
   version.
3. For a beta or a rehearsal, run the **Release** workflow by hand: tick *prerelease* for a
   beta (stable users aren't offered it), or leave *dry run* ticked to check everything and
   publish nothing.

The Community Apps listing lives in
[Brad-Clarke/unraid-templates](https://github.com/Brad-Clarke/unraid-templates); it points
at the latest release, so it doesn't change per release.
