# Property Scraper Schedule

## Mechanism: systemd timer, not cron

[`mainzware-property-scraper.timer`](../../portal/deploy/mainzware-property-scraper.timer)
+ [`.service`](../../portal/deploy/mainzware-property-scraper.service) —
systemd's timer unit is the modern equivalent of a cron job. Managed via
`systemctl`/`journalctl`, not `crontab`.

- `OnBootSec=10min` — also fires 10 minutes after the server boots, in case a
  run was missed while it was down.
- `OnUnitActiveSec=1h` — fires again 1 hour after each completed run.
- `Persistent=true` — a missed run (server was off) fires as soon as it's back,
  instead of being skipped.

**The exact time of day it fires is not fixed/anchored to midnight.** It drifts
based on when the server last rebooted, since it's boot-relative + hourly, not
`OnCalendar=`-based.

Check status:
```bash
systemctl is-enabled mainzware-property-scraper.timer
systemctl is-active mainzware-property-scraper.timer
systemctl list-timers mainzware-property-scraper.timer --no-pager
```

## What actually gets scraped

The service runs `api/bin/refresh-properties.php`, which calls
`SaleScraper::refreshStaleSources()`
([`api/src/Support/SaleScraper.php`](../../portal/api/src/Support/SaleScraper.php)).

- Pulls **every active row** from `scrape_sources` (`vendor = 'SRI' AND
  is_active = TRUE`) — i.e. every county/state source configured via
  "Manage Counties" → Save Sources in the admin UI. Not hardcoded to one county.
- For each source URL, skips it if `sale_properties.scraped_at::date =
  CURRENT_DATE` already for that URL — i.e. **each county only actually gets
  scraped once per calendar day**, even though the timer checks hourly.
- **Exception:** a source with `scrape_state = 'queued'` is always scraped,
  even if it already ran today. Saving a source in the admin UI queues it and
  dispatches a background worker, so a newly added county populates right away
  instead of waiting for the next day's tick.
- The first hourly tick after midnight (whenever that happens to land, per the
  drift above) is what triggers that day's actual scrape for each source.
- A file lock (`sys_get_temp_dir()/mainzworld_sale_scrape.lock`) prevents
  overlapping scrape runs if the timer fires while a previous run is still
  in-flight.

## Scrape state (added with the non-blocking scrape)

`scrape_sources.scrape_state` tracks one of `idle | queued | running | error`.
The API never scrapes inside the request: it marks sources `queued`, spawns a
detached worker, and returns immediately. The worker moves each source
`running` → `idle` (success, sets `last_scraped_at`) or `error` (sets
`last_scrape_error`). The admin UI polls `/scrape-sources` while anything is
`queued`/`running` and shows per-source status.

Spawned children get an explicit allow-listed environment (DB config +
`MAINZWORLD_SCRAPER_DIR`), because Apache `SetEnv` values are per-request
FastCGI params that a child process does not reliably inherit. The JWT secret
is deliberately **not** passed to children.

## Net effect

"Runs hourly" (the timer) and "scrapes once a day" (the actual per-county
behavior) are both true at the same time — they describe different layers of
the same system.
