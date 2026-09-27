<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;

final class Database
{
    public static function connection(): PDO
    {
        return self::connect(getenv('LIVE_WORSHIP_DB_USER') ?: getenv('MAINZWORLD_DB_USER') ?: null, getenv('LIVE_WORSHIP_DB_PASSWORD') ?: getenv('MAINZWORLD_DB_PASSWORD') ?: null);
    }

    public static function migratorConnection(): PDO
    {
        $user = trim((string) (getenv('LIVE_WORSHIP_MIGRATOR_DB_USER') ?: getenv('MAINZWORLD_MIGRATOR_DB_USER') ?: ''));
        $password = getenv('LIVE_WORSHIP_MIGRATOR_DB_PASSWORD') ?: getenv('MAINZWORLD_MIGRATOR_DB_PASSWORD') ?: null;
        if ($user === '') {
            // An entirely unconfigured local checkout may use the developer's
            // local PostgreSQL identity. Once an application credential is
            // configured, migrations must name their separate credential;
            // never silently reuse the runtime role.
            $runtimeUser = trim((string) (getenv('LIVE_WORSHIP_DB_USER') ?: getenv('MAINZWORLD_DB_USER') ?: ''));
            if ($runtimeUser !== '') throw new \RuntimeException('Explicit migrator database credentials are required; refusing to reuse the runtime database user.');
            return self::connect(null, null);
        }
        return self::connect($user, $password);
    }

    private static function connect(?string $user, ?string $password): PDO
    {
        $host = getenv('LIVE_WORSHIP_DB_HOST') ?: getenv('MAINZWORLD_DB_HOST') ?: 'localhost';
        $port = getenv('LIVE_WORSHIP_DB_PORT') ?: getenv('MAINZWORLD_DB_PORT') ?: '5432';
        $name = getenv('LIVE_WORSHIP_DB_NAME') ?: getenv('MAINZWORLD_DB_NAME') ?: 'mainzworld';
        $sslmode = getenv('LIVE_WORSHIP_DB_SSLMODE') ?: getenv('MAINZWORLD_DB_SSLMODE') ?: '';
        $dsn = "pgsql:host={$host};port={$port};dbname={$name};gssencmode=disable";
        if ($sslmode !== '') $dsn .= ";sslmode={$sslmode}";
        return new PDO($dsn, $user ?: null, $password ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
