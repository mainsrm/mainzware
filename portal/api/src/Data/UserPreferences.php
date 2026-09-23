<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class UserPreferences
{
    public static function findForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT color_mode FROM portal.user_preferences WHERE user_id = :user_id"
        );
        $stmt->execute(['user_id' => $userId]);
        $colorMode = $stmt->fetchColumn();

        return ['color_mode' => $colorMode ?: 'light'];
    }

    public static function setColorMode(int $userId, string $colorMode): array
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO portal.user_preferences (user_id, color_mode) VALUES (:user_id, :color_mode)
             ON CONFLICT (user_id) DO UPDATE
             SET color_mode = EXCLUDED.color_mode, updated_at = now()
             RETURNING color_mode'
        );
        $stmt->execute(['user_id' => $userId, 'color_mode' => $colorMode]);

        return ['color_mode' => $stmt->fetchColumn()];
    }
}
