<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;
use LiveWorship\LoginTracking;
use LiveWorship\TenantNames;
use LiveWorship\TenantProvisioner;

$migrator = Database::migratorConnection();
$runtime = Database::connection();
$actorId = TenantNames::uuid();
$tenant = null;

$insertActor = $migrator->prepare(
    'INSERT INTO lw_control.actors
        (id, identity_provider, external_subject, display_name, activated_on, activated_by)
     VALUES (:id, \'login-test\', :subject, :name, now(), :by)'
);
$insertActor->execute([
    'id' => $actorId,
    'subject' => $actorId,
    'name' => 'Login activity test user',
    'by' => '00000000-0000-0000-0000-000000000001',
]);

try {
    $tenant = TenantProvisioner::provision($migrator, 'Login Activity Test ' . substr($actorId, 0, 8), $actorId);
    $failureCode = 'test_invalid_' . substr($actorId, 0, 8);
    LoginTracking::record($runtime, $actorId, $tenant['id'], 'tenant_context', 'success', 'mainzworld', 'web');
    LoginTracking::record($runtime, $actorId, null, 'login', 'success', 'mainzworld', 'web', 'login-test-user');
    LoginTracking::record($runtime, null, null, 'login', 'failure', 'mainzworld', 'web', null, $failureCode);

    $teamRows = LoginTracking::recent($runtime, $tenant['id'], 'success', 10);
    if (count($teamRows) !== 1 || $teamRows[0]['tenant_id'] !== $tenant['id'] || $teamRows[0]['outcome'] !== 'success') {
        throw new RuntimeException('Tenant login activity filtering failed.');
    }
    $allRows = LoginTracking::recent($runtime, null, null, 10);
    if (count($allRows) < 3) throw new RuntimeException('Login activity was not recorded.');
    $failure = array_values(array_filter($allRows, static fn(array $row): bool => $row['failure_code'] === $failureCode));
    if (count($failure) !== 1 || $failure[0]['outcome'] !== 'failure') throw new RuntimeException('Failed login activity was not recorded safely.');
    echo "Login activity tracking checks passed.\n";
} finally {
    if ($tenant) {
        TenantProvisioner::requestDeprovision($migrator, $tenant['id'], $actorId, 0, 'Login activity test cleanup');
        TenantProvisioner::processDeprovisionQueued($migrator, 1, true);
    }
    $migrator->prepare('DELETE FROM lw_control.login_activity WHERE actor_id=:actor_id OR username_hint=\'login-test-user\' OR failure_code=:failure_code')->execute(['actor_id' => $actorId, 'failure_code' => 'test_invalid_' . substr($actorId, 0, 8)]);
    $migrator->prepare('DELETE FROM lw_control.actors WHERE id=:id')->execute(['id' => $actorId]);
}
