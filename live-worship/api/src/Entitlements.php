<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;

/** Resolves the effective feature set for a tenant at request time. */
final class Entitlements
{
    private const PLAN_STATES = ['free', 'trialing', 'active'];

    public static function resolve(PDO $db, string $tenantId): array
    {
        if (!TenantNames::validUuid($tenantId)) throw new \InvalidArgumentException('Tenant ID must be a UUID.');

        $subscription = $db->prepare(
            'SELECT ts.id AS subscription_id, ts.plan_id, ts.subscription_state, ts.expires_on,
                    p.code AS plan_code, p.display_name AS plan_name
               FROM lw_control.tenant_subscriptions ts
               JOIN lw_control.plans p ON p.id=ts.plan_id
                                      AND p.inactivated_on IS NULL AND p.inactivated_by IS NULL
              WHERE ts.tenant_id=:tenant_id
                AND ts.inactivated_on IS NULL AND ts.inactivated_by IS NULL
              ORDER BY ts.activated_on DESC, ts.id DESC
              LIMIT 1'
        );
        $subscription->execute(['tenant_id' => $tenantId]);
        $subscriptionRow = $subscription->fetch() ?: null;
        $planEligible = $subscriptionRow !== null
            && in_array((string) $subscriptionRow['subscription_state'], self::PLAN_STATES, true)
            && ($subscriptionRow['expires_on'] === null || strtotime((string) $subscriptionRow['expires_on']) > time());

        $details = [];
        $planDetailCount = 0;
        if ($subscriptionRow !== null) {
            $entitlements = $db->prepare(
                'SELECT f.code, f.display_name, te.source, te.expires_on
                   FROM lw_control.tenant_entitlements te
                   JOIN lw_control.features f ON f.id=te.feature_id
                                             AND f.inactivated_on IS NULL AND f.inactivated_by IS NULL
                  WHERE te.tenant_id=:tenant_id
                    AND te.inactivated_on IS NULL AND te.inactivated_by IS NULL
                    AND (te.expires_on IS NULL OR te.expires_on > now())
                    AND (
                        te.source <> \'plan\'
                        OR (te.source_plan_id=:plan_id AND te.source_subscription_id=:subscription_id)
                    )
                  ORDER BY f.code, te.activated_on DESC'
            );
            $entitlements->execute([
                'tenant_id' => $tenantId,
                'plan_id' => $subscriptionRow['plan_id'],
                'subscription_id' => $subscriptionRow['subscription_id'],
            ]);
            foreach ($entitlements->fetchAll() as $row) {
                $source = (string) $row['source'];
                if ($source === 'plan') {
                    if (!$planEligible) continue;
                    $planDetailCount++;
                }
                self::addDetail($details, (string) $row['code'], (string) $row['display_name'], $source, $row['expires_on']);
            }
        } else {
            $entitlements = $db->prepare(
                'SELECT f.code, f.display_name, te.source, te.expires_on
                   FROM lw_control.tenant_entitlements te
                   JOIN lw_control.features f ON f.id=te.feature_id
                                             AND f.inactivated_on IS NULL AND f.inactivated_by IS NULL
                  WHERE te.tenant_id=:tenant_id
                    AND te.source <> \'plan\'
                    AND te.inactivated_on IS NULL AND te.inactivated_by IS NULL
                    AND (te.expires_on IS NULL OR te.expires_on > now())
                  ORDER BY f.code, te.activated_on DESC'
            );
            $entitlements->execute(['tenant_id' => $tenantId]);
            foreach ($entitlements->fetchAll() as $row) {
                self::addDetail($details, (string) $row['code'], (string) $row['display_name'], (string) $row['source'], $row['expires_on']);
            }
        }

        // Older tenants may have a subscription but no seeded plan rows. Use
        // the current plan as a compatibility baseline until provisioning or
        // a billing transition has materialized its provenance rows.
        if ($planEligible && $planDetailCount === 0) {
            $planFeatures = $db->prepare(
                'SELECT f.code, f.display_name
                   FROM lw_control.plan_features pf
                   JOIN lw_control.features f ON f.id=pf.feature_id
                                             AND f.inactivated_on IS NULL AND f.inactivated_by IS NULL
                  WHERE pf.plan_id=:plan_id
                    AND pf.inactivated_on IS NULL AND pf.inactivated_by IS NULL
                  ORDER BY f.code'
            );
            $planFeatures->execute(['plan_id' => $subscriptionRow['plan_id']]);
            foreach ($planFeatures->fetchAll() as $row) {
                self::addDetail($details, (string) $row['code'], (string) $row['display_name'], 'plan', null);
            }
        }

        return [
            'plan_code' => $subscriptionRow['plan_code'] ?? null,
            'plan_name' => $subscriptionRow['plan_name'] ?? null,
            'subscription_state' => $subscriptionRow['subscription_state'] ?? null,
            'subscription_expires_on' => $subscriptionRow['expires_on'] ?? null,
            'features' => array_values(array_keys($details)),
            'feature_details' => array_values($details),
        ];
    }

    /** Materialize the current plan's feature rows for a subscription. */
    public static function seedPlan(PDO $db, string $tenantId, string $subscriptionId, string $planId, string $actorId): int
    {
        if (!TenantNames::validUuid($tenantId) || !self::databaseUuid($subscriptionId) || !self::databaseUuid($planId) || !self::databaseUuid($actorId)) {
            throw new \InvalidArgumentException('Entitlement provenance IDs must be UUIDs.');
        }
        $insert = $db->prepare(
            'INSERT INTO lw_control.tenant_entitlements
                (id, tenant_id, feature_id, source, source_plan_id, source_subscription_id,
                 activated_on, activated_by)
             SELECT md5(\'lw-entitlement:\' || :subscription_id || \':\' || pf.feature_id::text)::uuid,
                    :tenant_id, pf.feature_id, \'plan\', :plan_id, :subscription_id_2,
                    now(), :activated_by
               FROM lw_control.plan_features pf
              WHERE pf.plan_id=:plan_id_2
                AND pf.inactivated_on IS NULL AND pf.inactivated_by IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM lw_control.tenant_entitlements te
                     WHERE te.tenant_id=:tenant_id_check
                       AND te.feature_id=pf.feature_id
                       AND te.inactivated_on IS NULL AND te.inactivated_by IS NULL
                )
             ON CONFLICT DO NOTHING'
        );
        $insert->execute([
            'subscription_id' => $subscriptionId,
            'tenant_id' => $tenantId,
            'plan_id' => $planId,
            'subscription_id_2' => $subscriptionId,
            'activated_by' => $actorId,
            'plan_id_2' => $planId,
            'tenant_id_check' => $tenantId,
        ]);
        return $insert->rowCount();
    }

    private static function addDetail(array &$details, string $code, string $displayName, string $source, mixed $expiresOn): void
    {
        if (isset($details[$code])) return;
        $details[$code] = [
            'code' => $code,
            'display_name' => $displayName,
            'source' => $source,
            'expires_on' => $expiresOn,
        ];
    }

    private static function databaseUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab0-9][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
