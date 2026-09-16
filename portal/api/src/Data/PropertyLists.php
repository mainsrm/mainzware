<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class PropertyLists
{
    public static function allForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT pl.id, pl.name, pl.updated_at,
                    COUNT(pli.property_id) AS property_count,
                COALESCE(json_agg(pli.property_id) FILTER (WHERE pli.property_id IS NOT NULL), '[]'::json) AS property_ids
             FROM property_lists pl
             LEFT JOIN property_list_items pli ON pli.list_id = pl.id
             WHERE pl.user_id = :user_id
             GROUP BY pl.id, pl.name, pl.updated_at
             ORDER BY pl.updated_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);
        $lists = $stmt->fetchAll();
        foreach ($lists as &$list) {
            $list['property_ids'] = json_decode($list['property_ids'], true) ?: [];
        }
        return $lists;
    }

    public static function itemsForUser(int $userId, int $listId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT pli.property_id AS id,
                    COALESCE(CASE WHEN p.is_active THEN p.address END, pli.address) AS address,
                    COALESCE(CASE WHEN p.is_active THEN p.county END, pli.county) AS county,
                    COALESCE(CASE WHEN p.is_active THEN p.state END, pli.state) AS state,
                    COALESCE(CASE WHEN p.is_active THEN p.sale_status END, pli.sale_status) AS sale_status,
                    COALESCE(CASE WHEN p.is_active THEN p.sale_group END, pli.sale_group) AS sale_group,
                    COALESCE(CASE WHEN p.is_active THEN p.parcel END, pli.parcel) AS parcel,
                    COALESCE(CASE WHEN p.is_active THEN p.map_url END, pli.map_url) AS map_url,
                    COALESCE(CASE WHEN p.is_active THEN p.source_url END, pli.source_url) AS source_url,
                    COALESCE(CASE WHEN p.is_active THEN p.sri_id END, pli.sri_id) AS sri_id,
                    COALESCE(CASE WHEN p.is_active THEN p.sri_property_id END, pli.sri_property_id) AS sri_property_id,
                    (p.id IS NULL OR p.is_active = FALSE) AS archived
             FROM property_list_items pli
             JOIN property_lists pl ON pl.id = pli.list_id
             LEFT JOIN sale_properties p ON p.id = pli.property_id
             WHERE pl.id = :list_id AND pl.user_id = :user_id
             ORDER BY pli.created_at'
        );
        $stmt->execute(['list_id' => $listId, 'user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public static function create(int $userId, string $name, array $propertyIds): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO property_lists (user_id, name) VALUES (:user_id, :name) RETURNING id'
            );
            $stmt->execute(['user_id' => $userId, 'name' => $name]);
            $listId = (int) $stmt->fetchColumn();

            $item = $pdo->prepare(
                'INSERT INTO property_list_items
                    (list_id, property_id, address, county, state, sale_status, sale_group, parcel, map_url, source_url, sri_id, sri_property_id)
                 SELECT :list_id, id, address, county, state, sale_status, sale_group, parcel, map_url, source_url, sri_id, sri_property_id
                 FROM sale_properties WHERE id = :property_id
                 ON CONFLICT DO NOTHING'
            );
            foreach ($propertyIds as $propertyId) {
                $item->execute(['list_id' => $listId, 'property_id' => (int) $propertyId]);
            }

            $pdo->commit();
            return $listId;
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    public static function delete(int $userId, int $listId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM property_lists WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $listId, 'user_id' => $userId]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Property list not found.');
        }
    }
}
