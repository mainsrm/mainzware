<?php
declare(strict_types=1);

namespace MainzWorld\Config;

use PDO;

final class Database
{
    // PDO PostgreSQL connection, configured via environment variables (never hardcode credentials).
    public static function connection(): PDO
    {
        return self::connect(getenv('MAINZWORLD_DB_USER') ?: null, getenv('MAINZWORLD_DB_PASSWORD') ?: null);
    }

    /**
     * Migrations need the owner/migrator role (ALTER/DROP/CREATE); the runtime
     * app credential is being reduced to DML-only (Stage 7b). Falls back to
     * connection()'s credential so this keeps working before the migrator
     * role/credential exist on a given host -- see
     * db_migrations/manual/runtime_role.sql.
     */
    public static function migratorConnection(): PDO
    {
        $user = getenv('MAINZWORLD_MIGRATOR_DB_USER') ?: '';
        if ($user === '') {
            return self::connection();
        }
        return self::connect($user, getenv('MAINZWORLD_MIGRATOR_DB_PASSWORD') ?: null);
    }

    private static function connect(?string $user, ?string $password): PDO
    {
        $host = getenv('MAINZWORLD_DB_HOST') ?: 'localhost';
        $port = getenv('MAINZWORLD_DB_PORT') ?: '5432';
        $dbname = getenv('MAINZWORLD_DB_NAME') ?: 'mainzworld';
        // Managed hosts (DigitalOcean, AWS, etc.) require TLS; local dev leaves this unset.
        $sslmode = getenv('MAINZWORLD_DB_SSLMODE') ?: null;

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        if ($sslmode !== null && $sslmode !== '') {
            $dsn .= ";sslmode={$sslmode}";
        }

        return new PDO($dsn, $user ?: null, $password ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
