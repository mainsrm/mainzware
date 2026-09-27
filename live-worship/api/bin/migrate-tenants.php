<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;
use LiveWorship\TenantProvisioner;

try {
    $count = TenantProvisioner::migrateRegisteredTenants(Database::migratorConnection());
    echo "Live Worship tenant schemas are up to date ({$count} registered tenants).\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Live Worship tenant migration failed: {$error->getMessage()}\n");
    exit(1);
}
