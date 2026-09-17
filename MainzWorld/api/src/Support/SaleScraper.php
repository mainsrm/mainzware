<?php
declare(strict_types=1);

namespace MainzWorld\Support;

use MainzWorld\Config\Database;

/**
 * Triggers the SaleAddressMapper Python scraper for any configured source
 * that hasn't been refreshed yet today, so the /properties endpoint serves a
 * cached scrape instead of hitting the sale site on every request.
 */
final class SaleScraper
{
    private const LOCK_FILE = 'mainzworld_sale_scrape.lock';
    private const TIMEOUT_SECONDS = 120;

    /** Scrape any configured source that hasn't been refreshed today. Safe to call on every request. */
    public static function refreshStaleSources(): void
    {
        $sources = self::configuredSources();
        $stale = array_filter($sources, static fn (string $url) => !self::scrapedToday($url));
        if ($stale === []) {
            return;
        }

        $lockHandle = fopen(sys_get_temp_dir() . '/' . self::LOCK_FILE, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            // Another request is already scraping (or the lock file can't be opened);
            // serve whatever is already cached rather than piling up scrapers.
            return;
        }

        try {
            foreach ($stale as $url) {
                // Re-check now that we hold the lock, in case a concurrent request just finished.
                if (!self::scrapedToday($url)) {
                    self::scrape($url);
                }
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /** @return string[] */
    private static function configuredSources(): array
    {
        $stmt = Database::connection()->query(
            "SELECT url FROM scrape_sources WHERE vendor = 'SRI' AND is_active = TRUE ORDER BY id"
        );
        $urls = array_values(array_unique(array_map(
            [self::class, 'normalizeSriUrl'],
            $stmt->fetchAll(\PDO::FETCH_COLUMN)
        )));
        if ($urls !== []) {
            return $urls;
        }

        $raw = getenv('MAINZWORLD_SALE_SOURCES') ?: self::DEFAULT_SOURCE;
        return array_values(array_unique(array_filter(array_map(
            [self::class, 'normalizeSriUrl'],
            array_map('trim', explode(',', $raw))
        ))));
    }

    private const DEFAULT_SOURCE =
        'https://sriservices.com/properties?saleId=1356&state=IN&county=Fayette&saleType=tax';

    private static function scrapedToday(string $url): bool
    {
        $url = self::normalizeSriUrl($url);
        $stmt = Database::connection()->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM sale_properties
                WHERE source_url = :url AND is_active = TRUE AND scraped_at::date = CURRENT_DATE
            )'
        );
        $stmt->execute(['url' => $url]);
        return (bool) $stmt->fetchColumn();
    }

    private static function scrape(string $url): void
    {
        $url = self::normalizeSriUrl($url);
        $scraperDir = getenv('MAINZWORLD_SCRAPER_DIR') ?: dirname(__DIR__, 4) . '/SaleAddressMapper';
        $python = $scraperDir . '/.venv/bin/python';
        if (!is_file($python)) {
            error_log("SaleScraper: python venv not found at {$python}");
            return;
        }

        $command = [$python, '-m', 'saleaddressmapper.cli', $url, '--db', '-o', 'output/auto-scrape.html'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $scraperDir);
        if (!is_resource($process)) {
            error_log("SaleScraper: failed to start scraper for {$url}");
            return;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = time() + self::TIMEOUT_SECONDS;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(200_000);
        } while (time() < $deadline);

        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process, 9);
            error_log('SaleScraper: killed scraper for ' . $url . ' after ' . self::TIMEOUT_SECONDS . 's timeout');
        } elseif ($status['exitcode'] !== 0) {
            $stderr = stream_get_contents($pipes[2]);
            error_log("SaleScraper: scrape failed for {$url} (exit {$status['exitcode']}): {$stderr}");
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    private static function normalizeSriUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        $saleId = trim((string) ($query['saleId'] ?? ''));
        $state = strtoupper(trim((string) ($query['state'] ?? '')));
        $county = trim((string) ($query['county'] ?? ''));
        if ($saleId === '' || $state === '' || $county === '') {
            return $url;
        }

        return 'https://sriservices.com/properties?' . http_build_query([
            'saleId' => $saleId,
            'state' => $state,
            'county' => $county,
            'saleType' => 'tax',
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
