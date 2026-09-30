# Changelog

## 2026.09.30

- First release.
- Forecasts when the array, any mix of disks and pools, or a share will run out of space, with an estimate, a likely range and the expected growth.
- Tools > Disk Forecast: every target with its time to full, a graph of usage and forecast, a projected-usage table, and a "What if I add a drive" card.
- Settings > Disk Forecast: choose what to track, how often to take a reading, how much history the trend uses, and when to be warned.
- Dashboard tile showing each target's time to full; choose which targets appear, and the tile disappears when none do.
- Warnings through Unraid notifications when a target is due to fill within your chosen window.
- Dates and numbers follow the date and number formats set in Unraid.
- Readings come from free-space figures only: no folders are scanned and no disks are woken.
- Readings are kept on the flash drive in the plugin's own folder, saved once a day and when the array stops.
