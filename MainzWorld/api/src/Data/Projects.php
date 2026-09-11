<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class Projects
{
    // Parameterized query — no user input here, but keep the pattern consistent for future filters.
    public static function all(): array
    {
        $stmt = Database::connection()->query(
            'SELECT name, description, url FROM projects ORDER BY sort_order, id'
        );

        return $stmt->fetchAll();
    }
}

