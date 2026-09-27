<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;
use LiveWorship\TenantProvisioner;

$options = getopt('', ['slug:', 'name:', 'owner-actor-id:']);
$requestedSlug = array_key_exists('slug', $options) ? trim((string) $options['slug']) : null;
$name = trim((string) ($options['name'] ?? ''));
$ownerActorId = trim((string) ($options['owner-actor-id'] ?? ''));

if ($name === '' || $ownerActorId === '') {
    fwrite(STDERR, "Usage: php provision-tenant.php --name=\"Team Name\" --owner-actor-id=UUID [--slug=generated-slug]\n");
    exit(2);
}

try {
    $result = TenantProvisioner::provision(Database::migratorConnection(), $name, $ownerActorId, $requestedSlug);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Live Worship tenant provisioning failed: {$error->getMessage()}\n");
    exit(1);
}
