<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class GisSources
{
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT id, county, state, url FROM gis_sources ORDER BY county, state'
        )->fetchAll();
    }

    public static function create(string $county, string $state, string $url): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO gis_sources (county, state, url) VALUES (:county, :state, :url)
             ON CONFLICT (county, state) DO UPDATE SET url = EXCLUDED.url
             RETURNING id'
        );
        $stmt->execute(['county' => $county, 'state' => $state, 'url' => $url]);
        return (int) $stmt->fetchColumn();
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM gis_sources WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
