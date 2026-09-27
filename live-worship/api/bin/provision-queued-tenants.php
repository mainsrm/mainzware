<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;
use LiveWorship\TenantProvisioner;

$options = getopt('', ['limit:', 'retry-failed']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 10;
$retryFailed = array_key_exists('retry-failed', $options);

try {
    $results = TenantProvisioner::processQueued(Database::migratorConnection(), $limit, $retryFailed);
    echo json_encode([
        'processed' => count($results),
        'results' => $results,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Live Worship queued provisioning failed: {$error->getMessage()}\n");
    exit(1);
}
