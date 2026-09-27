<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;

final class TenantContext
{
    public static function select(PDO $db, array $identity, string $slug): array
    {
        $slug = trim(strtolower($slug));
        if (!TenantNames::validSlug($slug)) throw new TenantContextError(422, 'Choose a valid Live Worship team URL.');
        $actorId = ActorIdentity::ensureMainzWareActor($db, $identity);
        $tenant = self::tenantBySlug($db, $slug);
        return self::contextForTenant($db, $identity, $tenant, $actorId);
    }

    public static function current(PDO $db, array $identity): ?array
    {
        $tenantId = trim((string) ($_SERVER['HTTP_X_LIVE_WORSHIP_TENANT_ID'] ?? $_SESSION['live_worship_tenant_id'] ?? ''));
        if ($tenantId === '') return null;
        if (!TenantNames::validUuid($tenantId)) throw new TenantContextError(400, 'The selected Live Worship tenant is invalid.');
        $actorId = ActorIdentity::ensureMainzWareActor($db, $identity);
        $tenant = self::tenantById($db, $tenantId);
        return self::contextForTenant($db, $identity, $tenant, $actorId);
    }

    private static function tenantBySlug(PDO $db, string $slug): array
    {
        $stmt = $db->prepare(
            'SELECT id, slug, display_name, schema_name
               FROM lw_control.tenants
              WHERE lower(slug)=lower(:slug)
                AND provisioning_state=\'active\'
                AND inactivated_on IS NULL AND inactivated_by IS NULL'
        );
        $stmt->execute(['slug' => $slug]);
        $tenant = $stmt->fetch();
        if (!$tenant) throw new TenantContextError(404, 'Live Worship team not found or not yet available.');
        return $tenant;
    }

    private static function tenantById(PDO $db, string $tenantId): array
    {
        $stmt = $db->prepare(
            'SELECT id, slug, display_name, schema_name
               FROM lw_control.tenants
              WHERE id=:id
                AND provisioning_state=\'active\'
                AND inactivated_on IS NULL AND inactivated_by IS NULL'
        );
        $stmt->execute(['id' => $tenantId]);
        $tenant = $stmt->fetch();
        if (!$tenant) throw new TenantContextError(404, 'Live Worship team not found or not yet available.');
        return $tenant;
    }

    private static function memberContext(PDO $db, array $tenant, string $actorId): array
    {
        $schema = self::identifier((string) $tenant['schema_name']);
        $member = $db->prepare(
            "SELECT id, role, view_mode, theme
               FROM {$schema}.members
              WHERE actor_id=:actor_id
                AND inactivated_on IS NULL AND inactivated_by IS NULL"
        );
        $member->execute(['actor_id' => $actorId]);
        $memberRow = $member->fetch();
        if (!$memberRow) throw new TenantContextError(403, 'You are not a member of this Live Worship team.');

        $entitlements = Entitlements::resolve($db, (string) $tenant['id']);

        return [
            'tenant_id' => (string) $tenant['id'],
            'slug' => (string) $tenant['slug'],
            'display_name' => (string) $tenant['display_name'],
            'actor_id' => $actorId,
            'member_id' => (string) $memberRow['id'],
            'role' => (string) $memberRow['role'],
            'username' => self::actorName($db, $actorId),
            'auth_type' => 'mainzware',
            'view_mode' => (string) $memberRow['view_mode'],
            'theme' => (string) $memberRow['theme'],
            'plan_code' => $entitlements['plan_code'],
            'plan_name' => $entitlements['plan_name'],
            'subscription_state' => $entitlements['subscription_state'],
            'subscription_expires_on' => $entitlements['subscription_expires_on'],
            'features' => $entitlements['features'],
            'feature_details' => $entitlements['feature_details'],
            'support_mode' => false,
        ];
    }

    private static function contextForTenant(PDO $db, array $identity, array $tenant, string $actorId): array
    {
        try {
            $support = SupportSessions::context($db, $identity, (string) $tenant['id']);
        } catch (SupportSessionError $error) {
            throw new TenantContextError($error->status, $error->getMessage());
        }
        if ($support === null) return self::memberContext($db, $tenant, $actorId);

        $features = $db->query(
            'SELECT code FROM lw_control.features
              WHERE inactivated_on IS NULL AND inactivated_by IS NULL
              ORDER BY code'
        )->fetchAll(PDO::FETCH_COLUMN);

        return [
            'tenant_id' => (string) $tenant['id'],
            'slug' => (string) $tenant['slug'],
            'display_name' => (string) $tenant['display_name'],
            'actor_id' => $actorId,
            'member_id' => null,
            'role' => 'support',
            'username' => self::actorName($db, $actorId),
            'auth_type' => 'mainzware',
            'view_mode' => 'choir',
            'theme' => 'light',
            'plan_code' => 'support',
            'features' => array_values(array_map('strval', $features)),
            'support_mode' => true,
            'support_session_id' => (string) $support['id'],
            'support_expires_on' => $support['expires_on'],
            'support_reason' => (string) $support['reason'],
        ];
    }

    private static function actorName(PDO $db, string $actorId): string
    {
        $stmt = $db->prepare('SELECT COALESCE(NULLIF(trim(display_name), \'\'), external_subject) FROM lw_control.actors WHERE id=:id');
        $stmt->execute(['id' => $actorId]);
        return (string) ($stmt->fetchColumn() ?: 'MainzWare user');
    }

    private static function identifier(string $schemaName): string
    {
        if (!preg_match('/^lw_t_[0-9a-f]+$/', $schemaName)) throw new TenantContextError(500, 'Tenant schema is invalid.');
        return '"' . $schemaName . '"';
    }
}
