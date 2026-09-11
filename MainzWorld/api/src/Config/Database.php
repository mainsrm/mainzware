<?php
declare(strict_types=1);

namespace MainzWorld\Config;

use PDO;

final class Database
{
    // PDO PostgreSQL connection, configured via environment variables (never hardcode credentials).
    public static function connection(): PDO
    {
        $host = getenv('MAINZWORLD_DB_HOST') ?: 'localhost';
        $port = getenv('MAINZWORLD_DB_PORT') ?: '5432';
        $dbname = getenv('MAINZWORLD_DB_NAME') ?: 'mainzworld';
        $user = getenv('MAINZWORLD_DB_USER');
        $password = getenv('MAINZWORLD_DB_PASSWORD');

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

        return new PDO($dsn, $user ?: null, $password ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
