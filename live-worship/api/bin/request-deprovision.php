<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;
use LiveWorship\TenantProvisioner;

$options = getopt('', ['tenant-id:', 'requested-by:', 'retention-days::', 'reason::']);
$tenantId = trim((string) ($options['tenant-id'] ?? ''));
$requestedBy = trim((string) ($options['requested-by'] ?? ''));
$retentionDays = isset($options['retention-days']) ? (int) $options['retention-days'] : 30;
$reason = trim((string) ($options['reason'] ?? ''));

if ($tenantId === '' || $requestedBy === '') {
    fwrite(STDERR, "Usage: php request-deprovision.php --tenant-id=UUID --requested-by=ACTOR_UUID [--retention-days=30] [--reason=... ]\n");
    exit(2);
}

try {
    $result = TenantProvisioner::requestDeprovision(Database::migratorConnection(), $tenantId, $requestedBy, $retentionDays, $reason);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Live Worship tenant deprovision request failed: {$error->getMessage()}\n");
    exit(1);
}
