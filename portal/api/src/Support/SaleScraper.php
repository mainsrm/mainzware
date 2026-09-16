<?php
declare(strict_types=1);

namespace MainzWorld\Support;

use MainzWorld\Config\Database;

/**
 * Bridges the /properties admin UI to the SaleAddressMapper Python scraper.
 *
 * Scraping is decoupled from the HTTP request: the API marks sources as
 * 'queued' and hands off to a detached background worker (this same class,
 * run via bin/refresh-properties.php) so the request returns immediately.
 * The worker walks each source through running -> idle/error, and the
 * frontend polls scrape_sources to reflect progress.
 */
final class SaleScraper
{
    private const LOCK_FILE = 'mainzworld_sale_scrape.lock';
    private const TIMEOUT_SECONDS = 120;

    /**
     * Mark the given SRI source URLs as queued and kick off a detached worker.
     * Returns how many sources were queued. Never blocks on the scrape itself.
     */
    public static function queueAndDispatch(array $urls): int
    {
        $urls = array_values(array_unique(array_filter($urls, 'is_string')));
        if ($urls === []) {
            return 0;
        }

        $stmt = Database::connection()->prepare(
            "UPDATE scrape_sources
             SET scrape_state = 'queued', scrape_queued_at = now()
             WHERE url = :url AND vendor = 'SRI' AND is_active = TRUE"
        );
        $queued = 0;
        foreach ($urls as $url) {
            $stmt->execute(['url' => $url]);
            $queued += $stmt->rowCount();
        }

        self::dispatchWorker();
        return $queued;
    }

    /**
     * Launch bin/refresh-properties.php as a fully detached process and return
     * at once. stdio goes to /dev/null and the child handle is never waited on,
     * so the HTTP request is not blocked by the scrape.
     */
    public static function dispatchWorker(): void
    {
        $worker = dirname(__DIR__, 2) . '/bin/refresh-properties.php';
        if (!is_file($worker)) {
            error_log("SaleScraper: worker script not found at {$worker}");
            return;
        }

        // Under PHP-FPM, PHP_BINARY is php-fpm and cannot run a CLI script, so
        // prefer a configured CLI binary; the dev server (cli-server) is fine.
        $configured = getenv('MAINZWORLD_PHP_BINARY') ?: '';
        $php = ($configured !== '' && is_executable($configured))
            ? $configured
            : ((PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') ? PHP_BINARY : '/usr/bin/php');

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        // Fixed argv (no shell, no user input) — nothing to inject.
        $process = @proc_open([$php, $worker], $descriptors, $pipes, dirname($worker), self::childEnvironment());
        if (!is_resource($process)) {
            error_log('SaleScraper: failed to dispatch background scrape worker');
            return;
        }
        // Intentionally no proc_close(): that would block until the child exits.
        // Releasing the resource frees our handles without waiting; the child is
        // reparented to init and finishes on its own.
    }

    /**
     * Worker entry point. Drains queued sources and refreshes stale ones,
     * moving each through running -> idle/error. Invoked by the detached
     * dispatch above, bin/refresh-properties.php, and the hourly systemd timer.
     * Serialized by an exclusive non-blocking lock so runs never stack.
     */
    public static function refreshStaleSources(): void
    {
        $lockHandle = fopen(sys_get_temp_dir() . '/' . self::LOCK_FILE, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            // Another worker holds the lock and will drain the queue.
            return;
        }

        try {
            foreach (self::sourcesNeedingScrape() as $source) {
                self::runSource((int) $source['id'], (string) $source['url']);
            }
            self::scrapeEnvFallback();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private static function runSource(int $id, string $url): void
    {
        self::setState($id, 'running');
        $result = self::scrape($url);
        self::recordResult($id, $result['success'], $result['success'] ? null : $result['status']);
    }

    /** @return array<int, array{id: int, url: string}> DB sources queued or not yet scraped today. */
    private static function sourcesNeedingScrape(): array
    {
        $stmt = Database::connection()->query(
            "SELECT id, url FROM scrape_sources
             WHERE vendor = 'SRI' AND is_active = TRUE
               AND (
                 scrape_state = 'queued'
                 OR NOT EXISTS (
                   SELECT 1 FROM sale_properties
                   WHERE source_url = scrape_sources.url AND is_active = TRUE
                     AND scraped_at::date = CURRENT_DATE
                 )
               )
             ORDER BY id"
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Fall back to the env-configured default only when no SRI sources exist in the DB. */
    private static function scrapeEnvFallback(): void
    {
        $configured = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM scrape_sources WHERE vendor = 'SRI' AND is_active = TRUE")
            ->fetchColumn();
        if ($configured > 0) {
            return;
        }

        $raw = getenv('MAINZWORLD_SALE_SOURCES') ?: self::DEFAULT_SOURCE;
        foreach (array_values(array_filter(array_map('trim', explode(',', $raw)))) as $url) {
            if (!self::scrapedToday($url)) {
                self::scrape($url);
            }
        }
    }

    private static function setState(int $id, string $state): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE scrape_sources SET scrape_state = :state WHERE id = :id'
        );
        $stmt->execute(['state' => $state, 'id' => $id]);
    }

    private static function recordResult(int $id, bool $success, ?string $error): void
    {
        if ($success) {
            $stmt = Database::connection()->prepare(
                "UPDATE scrape_sources
                 SET scrape_state = 'idle', last_scraped_at = now(), last_scrape_error = NULL
                 WHERE id = :id"
            );
            $stmt->execute(['id' => $id]);
            return;
        }

        $stmt = Database::connection()->prepare(
            "UPDATE scrape_sources
             SET scrape_state = 'error', last_scrape_error = :error
             WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'error' => $error]);
    }

    private const DEFAULT_SOURCE =
        'https://sriservices.com/properties?saleId=1356&state=IN&county=Fayette&saleType=Tax%20Sale&timeFrame=All%20Future%20Sale%20Dates';

    private static function scrapedToday(string $url): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM sale_properties
                WHERE source_url = :url AND is_active = TRUE AND scraped_at::date = CURRENT_DATE
            )'
        );
        $stmt->execute(['url' => $url]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Config for spawned children. Apache SetEnv values are per-request FastCGI
     * params that a child process does not reliably inherit, so pass the values
     * this process resolved explicitly rather than relying on ambient env.
     *
     * @return array<string, string>
     */
    private static function childEnvironment(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'LANG', 'PLAYWRIGHT_BROWSERS_PATH'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $env[$key] = $value;
            }
        }

        $passthrough = [
            'MAINZWORLD_DB_HOST', 'MAINZWORLD_DB_PORT', 'MAINZWORLD_DB_NAME',
            'MAINZWORLD_DB_USER', 'MAINZWORLD_DB_PASSWORD', 'MAINZWORLD_DB_SSLMODE',
            'MAINZWORLD_SCRAPER_DIR', 'MAINZWORLD_PHP_BINARY', 'MAINZWORLD_SALE_SOURCES',
        ];
        foreach ($passthrough as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /** @return array{url: string, success: bool, status: string, exit_code?: int} */
    private static function scrape(string $url): array
    {
        $scraperDir = getenv('MAINZWORLD_SCRAPER_DIR')
            ?: dirname(__DIR__, 4) . '/services/property-scraper';
        $python = $scraperDir . '/.venv/bin/python';
        if (!is_file($python)) {
            error_log("SaleScraper: python venv not found at {$python}");
            return ['url' => $url, 'success' => false, 'status' => 'python_unavailable'];
        }

        $command = [$python, '-m', 'saleaddressmapper.cli', $url, '--db', '-o', 'output/auto-scrape.html'];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $scraperDir,
            self::childEnvironment()
        );
        if (!is_resource($process)) {
            error_log("SaleScraper: failed to start scraper for {$url}");
            return ['url' => $url, 'success' => false, 'status' => 'start_failed'];
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
        $timedOut = false;
        if ($status['running']) {
            proc_terminate($process, 9);
            $timedOut = true;
            error_log('SaleScraper: killed scraper for ' . $url . ' after ' . self::TIMEOUT_SECONDS . 's timeout');
        } elseif ($status['exitcode'] !== 0) {
            $stderr = stream_get_contents($pipes[2]);
            error_log("SaleScraper: scrape failed for {$url} (exit {$status['exitcode']}): {$stderr}");
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [
            'url' => $url,
            'success' => !$timedOut && $status['exitcode'] === 0,
            'status' => $timedOut ? 'timeout' : ($status['exitcode'] === 0 ? 'completed' : 'failed'),
            'exit_code' => (int) $status['exitcode'],
        ];
    }
}
