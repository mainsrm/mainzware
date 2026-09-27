<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;
use LiveWorship\Entitlements;
use LiveWorship\TenantNames;
use LiveWorship\TenantProvisioner;

$migrator = Database::migratorConnection();
$runtime = Database::connection();
$actorId = TenantNames::uuid();
$tenants = [];
$systemActor = '00000000-0000-0000-0000-000000000001';

$migrator->prepare(
    'INSERT INTO lw_control.actors
        (id, identity_provider, external_subject, display_name, activated_on, activated_by)
     VALUES (:id, \'entitlement-test\', :subject, :name, now(), :by)'
)->execute([
    'id' => $actorId,
    'subject' => $actorId,
    'name' => 'Entitlement test user',
    'by' => $systemActor,
]);

function featureCodes(array $resolved): array
{
    $codes = $resolved['features'] ?? [];
    sort($codes);
    return $codes;
}

function switchPlan(PDO $db, string $tenantId, string $planCode, string $actorId): void
{
    $current = $db->prepare(
        'SELECT id FROM lw_control.tenant_subscriptions
          WHERE tenant_id=:tenant_id
            AND inactivated_on IS NULL AND inactivated_by IS NULL'
    );
    $current->execute(['tenant_id' => $tenantId]);
    $oldSubscriptionId = (string) $current->fetchColumn();
    $plan = $db->prepare('SELECT id FROM lw_control.plans WHERE code=:code AND inactivated_on IS NULL AND inactivated_by IS NULL');
    $plan->execute(['code' => $planCode]);
    $planId = (string) $plan->fetchColumn();
    if ($oldSubscriptionId === '' || $planId === '') throw new RuntimeException('Plan switch fixture could not find its subscription or plan.');

    $newSubscriptionId = TenantNames::uuid();
    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE lw_control.tenant_entitlements
                SET inactivated_on=now(), inactivated_by=:actor
              WHERE tenant_id=:tenant_id
                AND inactivated_on IS NULL AND inactivated_by IS NULL'
        )->execute(['actor' => $actorId, 'tenant_id' => $tenantId]);
        $db->prepare(
            'UPDATE lw_control.tenant_subscriptions
                SET inactivated_on=now(), inactivated_by=:actor
              WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL'
        )->execute(['actor' => $actorId, 'id' => $oldSubscriptionId]);
        $db->prepare(
            'INSERT INTO lw_control.tenant_subscriptions
                (id, tenant_id, plan_id, subscription_state, activated_on, activated_by)
             VALUES (:id, :tenant_id, :plan_id, \'active\', now(), :actor)'
        )->execute(['id' => $newSubscriptionId, 'tenant_id' => $tenantId, 'plan_id' => $planId, 'actor' => $actorId]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    Entitlements::seedPlan($db, $tenantId, $newSubscriptionId, $planId, $actorId);
}

try {
    foreach (['Free', 'Pro', 'Three Sixty'] as $label) {
        $tenants[$label] = TenantProvisioner::provision($migrator, 'Entitlement ' . $label . ' ' . substr($actorId, 0, 8), $actorId);
    }

    $free = Entitlements::resolve($runtime, $tenants['Free']['id']);
    if (count($free['features']) !== 4 || in_array('advanced-import', $free['features'], true)) throw new RuntimeException('Free plan did not resolve to its four baseline features.');

    switchPlan($migrator, $tenants['Pro']['id'], 'live-worship-pro', $actorId);
    $pro = Entitlements::resolve($runtime, $tenants['Pro']['id']);
    if (count($pro['features']) !== 5 || !in_array('advanced-import', $pro['features'], true) || $pro['subscription_state'] !== 'active') throw new RuntimeException('Pro plan entitlement resolution failed.');

    switchPlan($migrator, $tenants['Three Sixty']['id'], 'live-worship-360', $actorId);
    $threeSixty = Entitlements::resolve($runtime, $tenants['Three Sixty']['id']);
    foreach (['messaging', 'team-rotations', 'scheduling', 'push-notifications'] as $feature) {
        if (!in_array($feature, $threeSixty['features'], true)) throw new RuntimeException("360 plan is missing {$feature}.");
    }
    if (count($threeSixty['features']) !== 9) throw new RuntimeException('360 plan did not resolve to nine features.');

    $messagingId = $runtime->query("SELECT id FROM lw_control.features WHERE code='messaging'")->fetchColumn();
    $grantId = TenantNames::uuid();
    $migrator->prepare(
        'INSERT INTO lw_control.tenant_entitlements
            (id, tenant_id, feature_id, source, expires_on, activated_on, activated_by)
         VALUES (:id, :tenant_id, :feature_id, \'grant\', now() + interval \'1 day\', now(), :by)'
    )->execute(['id' => $grantId, 'tenant_id' => $tenants['Free']['id'], 'feature_id' => $messagingId, 'by' => $actorId]);
    $granted = Entitlements::resolve($runtime, $tenants['Free']['id']);
    if (!in_array('messaging', $granted['features'], true)) throw new RuntimeException('An active tenant grant was not added to effective features.');

    $migrator->prepare('UPDATE lw_control.tenant_entitlements SET expires_on=now() - interval \'1 minute\' WHERE id=:id')->execute(['id' => $grantId]);
    $expired = Entitlements::resolve($runtime, $tenants['Free']['id']);
    if (in_array('messaging', $expired['features'], true)) throw new RuntimeException('An expired tenant grant remained effective.');

    $freeSubscription = $migrator->prepare(
        'SELECT id FROM lw_control.tenant_subscriptions
          WHERE tenant_id=:tenant_id AND inactivated_on IS NULL AND inactivated_by IS NULL'
    );
    $freeSubscription->execute(['tenant_id' => $tenants['Free']['id']]);
    $migrator->prepare('UPDATE lw_control.tenant_subscriptions SET subscription_state=\'past_due\' WHERE id=:id')->execute(['id' => $freeSubscription->fetchColumn()]);
    $pastDue = Entitlements::resolve($runtime, $tenants['Free']['id']);
    if ($pastDue['features'] !== []) throw new RuntimeException('Past-due plan access was granted before a billing grace policy was defined.');

    $provenance = $runtime->prepare(
        'SELECT count(*) FROM lw_control.tenant_entitlements
          WHERE tenant_id=:tenant_id AND source=\'plan\'
            AND source_subscription_id IS NOT NULL AND source_plan_id IS NOT NULL
            AND inactivated_on IS NULL AND inactivated_by IS NULL'
    );
    $provenance->execute(['tenant_id' => $tenants['Pro']['id']]);
    if ((int) $provenance->fetchColumn() !== 5) throw new RuntimeException('Pro plan entitlement provenance was not materialized.');

    echo "Free, Pro, 360, grant, expiration, past-due, and provenance checks passed.\n";
} finally {
    foreach ($tenants as $tenant) {
        try {
            TenantProvisioner::requestDeprovision($migrator, $tenant['id'], $actorId, 0, 'Entitlement test cleanup');
            TenantProvisioner::processDeprovisionQueued($migrator, 1, true);
        } catch (Throwable $error) {
            fwrite(STDERR, "Entitlement test cleanup warning: {$error->getMessage()}\n");
        }
    }
    $migrator->prepare('DELETE FROM lw_control.actors WHERE id=:id')->execute(['id' => $actorId]);
}
