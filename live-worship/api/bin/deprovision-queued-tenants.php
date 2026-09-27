<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;
use LiveWorship\TenantProvisioner;

$options = getopt('', ['limit:', 'force']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 10;
$force = array_key_exists('force', $options);

try {
    $results = TenantProvisioner::processDeprovisionQueued(Database::migratorConnection(), $limit, $force);
    echo json_encode([
        'processed' => count($results),
        'results' => $results,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Live Worship queued deprovisioning failed: {$error->getMessage()}\n");
    exit(1);
}
