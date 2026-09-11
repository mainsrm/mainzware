<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class CmsContent
{
    public static function forPage(string $page): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, page, type, title, details, image_url, location, event_time, created_at
             FROM cms_content
             WHERE page = :page AND removed_at IS NULL
             ORDER BY COALESCE(event_time, created_at) DESC'
        );
        $stmt->execute(['page' => $page]);
        return $stmt->fetchAll();
    }

    public static function create(array $data, int $userId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO cms_content (page, type, title, details, image_url, location, event_time, created_by)
             VALUES (:page, :type, :title, :details, :image_url, :location, :event_time, :created_by)
             RETURNING id'
        );
        $stmt->execute([
            'page' => $data['page'],
            'type' => $data['type'] ?? 'article',
            'title' => $data['title'],
            'details' => $data['details'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'location' => $data['location'] ?? null,
            'event_time' => $data['event_time'] ?? null,
            'created_by' => $userId,
        ]);

        return (int) $stmt->fetchColumn();
    }
}
