<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;
use LiveWorship\SupportSessions;
use LiveWorship\TenantContext;
use LiveWorship\TenantNames;
use LiveWorship\TenantProvisioner;
use LiveWorship\TenantRuntime;

$migrator = Database::migratorConnection();
$runtime = Database::connection();
$identityId = random_int(700000, 799999);
$adminActor = TenantNames::uuid();
$tenant = null;
$identity = ['id' => $identityId, 'username' => 'support-test-admin', 'role' => 'admin'];
$_SESSION = [];

$insertActor = $migrator->prepare(
    'INSERT INTO lw_control.actors
        (id, identity_provider, external_subject, display_name, activated_on, activated_by)
     VALUES (:id, :provider, :subject, :name, now(), :by)'
);
$insertActor->execute([
    'id' => $adminActor,
    'provider' => 'mainzworld',
    'subject' => (string) $identityId,
    'name' => 'Support test administrator',
    'by' => '00000000-0000-0000-0000-000000000001',
]);

try {
    $tenant = TenantProvisioner::provision($migrator, 'Support Session Test ' . substr($adminActor, 0, 8), $adminActor);
    $support = SupportSessions::start($runtime, $adminActor, $tenant['id'], 15, 'Read-only support boundary test');
    $_SESSION['live_worship_support_session_id'] = $support['id'];
    $_SESSION['live_worship_tenant_id'] = $tenant['id'];

    $context = TenantContext::current($runtime, $identity);
    if (($context['support_mode'] ?? false) !== true || $context['role'] !== 'support') throw new RuntimeException('Support context was not created.');
    if (!in_array('master-catalog', $context['features'], true)) throw new RuntimeException('Support context does not have diagnostic catalog access.');

    ob_start();
    TenantRuntime::dispatch($runtime, $context, 'GET', '/api/v1/live-worship/members');
    $readResponse = json_decode((string) ob_get_clean(), true);
    if (!is_array($readResponse) || count($readResponse) < 1) throw new RuntimeException('Support read-only access could not read tenant members.');

    try {
        TenantRuntime::dispatch($runtime, $context, 'POST', '/api/v1/live-worship/songs');
        throw new RuntimeException('Support mode allowed a tenant mutation.');
    } catch (\LiveWorship\ApiError $error) {
        if ($error->status !== 403) throw $error;
    }

    $second = SupportSessions::start($runtime, $adminActor, $tenant['id'], 15, 'Deprovision boundary test');
    $_SESSION['live_worship_support_session_id'] = $second['id'];
    TenantProvisioner::requestDeprovision($migrator, $tenant['id'], $adminActor, 0, 'Support session test cleanup');
    if (SupportSessions::current($runtime, $adminActor) !== null) throw new RuntimeException('Tenant deprovisioning did not end the support session.');
    $result = TenantProvisioner::processDeprovisionQueued($migrator, 1, true);
    if (($result[0]['state'] ?? null) !== 'deprovisioned') throw new RuntimeException('Support test tenant did not deprovision cleanly.');
    $audit = $runtime->prepare("SELECT count(*) FROM lw_control.audit_events WHERE tenant_id=:tenant_id AND event_type='support.session.ended' AND payload->>'reason'='tenant.deprovisioning'");
    $audit->execute(['tenant_id' => $tenant['id']]);
    if ((int) $audit->fetchColumn() < 1) throw new RuntimeException('Tenant deprovisioning did not audit support-session termination.');
    $tenant = null;
    echo "Read-only support session and deprovision boundary checks passed.\n";
} finally {
    $_SESSION = [];
    if ($tenant) {
        TenantProvisioner::requestDeprovision($migrator, $tenant['id'], $adminActor, 0, 'Support API test cleanup');
        TenantProvisioner::processDeprovisionQueued($migrator, 1, true);
    }
    $migrator->prepare('DELETE FROM lw_control.actors WHERE id=:id')->execute(['id' => $adminActor]);
}
