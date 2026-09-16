<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class ScrapeSources
{
    public static function all(): array
    {
        $stmt = Database::connection()->query(
                'SELECT ss.id, ss.vendor, ss.label, ss.url, ss.is_active, ss.scrape_state,
                    COALESCE(ss.last_scraped_at, MAX(sp.scraped_at)) AS last_scraped_at,
                    COALESCE(ss.last_scrape_count, COUNT(sp.id)) AS last_scrape_count,
                    ss.last_scrape_error
                 FROM scrape_sources ss
                 LEFT JOIN sale_properties sp ON sp.source_url = ss.url
                 GROUP BY ss.id, ss.vendor, ss.label, ss.url, ss.is_active, ss.scrape_state,
                      ss.last_scraped_at, ss.last_scrape_count, ss.last_scrape_error
             ORDER BY ss.id'
        );
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM scrape_sources WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $label, string $url, string $vendor = 'SRI'): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO scrape_sources (vendor, label, url) VALUES (:vendor, :label, :url) RETURNING id'
        );
        $stmt->execute(['vendor' => $vendor, 'label' => $label, 'url' => $url]);
        return (int) $stmt->fetchColumn();
    }

    public static function delete(int $id): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $source = self::find($id);
            if ($source === null) {
                throw new \InvalidArgumentException('Scrape source not found.');
            }

            // Keep rows for saved-list history, but remove this source's properties from the live feed.
            $deactivate = $pdo->prepare(
                'UPDATE sale_properties SET is_active = FALSE WHERE source_url = :source_url'
            );
            $deactivate->execute(['source_url' => $source['url']]);

            $remove = $pdo->prepare('DELETE FROM scrape_sources WHERE id = :id');
            $remove->execute(['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    public static function recordScrapeResult(int $id, ?int $count, ?string $error): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE scrape_sources
             SET last_scraped_at = now(), last_scrape_count = :count, last_scrape_error = :error
             WHERE id = :id'
        );
        $stmt->execute(['count' => $count, 'error' => $error, 'id' => $id]);
    }
}
