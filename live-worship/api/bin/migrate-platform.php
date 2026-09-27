<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;
use LiveWorship\PlatformMigrations;

try {
    PlatformMigrations::applyPlatform(Database::migratorConnection());
    echo "Live Worship control and master schemas are up to date.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Live Worship platform migration failed: {$error->getMessage()}\n");
    exit(1);
}
