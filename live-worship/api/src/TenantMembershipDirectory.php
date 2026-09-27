<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;

/**
 * Control-plane index for fast team discovery and switching.
 * Tenant schema members remain authoritative; this projection is repaired by
 * the platform migration and updated transactionally with member mutations.
 */
final class TenantMembershipDirectory
{
    public static function sync(PDO $db, string $tenantId, string $actorId, string $memberId, string $role, string $changedBy): void
    {
        foreach ([$tenantId => 'Tenant ID', $actorId => 'Actor ID', $memberId => 'Member ID', $changedBy => 'Changed-by actor ID'] as $value => $label) {
            if (!TenantNames::validUuid($value)) throw new \InvalidArgumentException("{$label} must be a UUID.");
        }
        if (!in_array($role, ['owner', 'leader', 'choir', 'musician'], true)) throw new \InvalidArgumentException('Invalid tenant membership role.');

        $find = $db->prepare(
            'SELECT id
               FROM lw_control.tenant_memberships
              WHERE tenant_id=:tenant_id AND actor_id=:actor_id
              ORDER BY activated_on DESC
              LIMIT 1'
        );
        $find->execute(['tenant_id' => $tenantId, 'actor_id' => $actorId]);
        $id = $find->fetchColumn();
        if ($id !== false) {
            $update = $db->prepare(
                'UPDATE lw_control.tenant_memberships
                    SET member_id=:member_id, role=:role,
                        activated_on=now(), activated_by=:changed_by,
                        inactivated_on=NULL, inactivated_by=NULL
                  WHERE id=:id'
            );
            $update->execute(['id' => $id, 'member_id' => $memberId, 'role' => $role, 'changed_by' => $changedBy]);
            return;
        }

        $insert = $db->prepare(
            'INSERT INTO lw_control.tenant_memberships
                (id, tenant_id, actor_id, member_id, role, activated_on, activated_by)
             VALUES (:id, :tenant_id, :actor_id, :member_id, :role, now(), :changed_by)'
        );
        $insert->execute([
            'id' => TenantNames::uuid(),
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'member_id' => $memberId,
            'role' => $role,
            'changed_by' => $changedBy,
        ]);
    }

    public static function updateRole(PDO $db, string $tenantId, string $memberId, string $role): void
    {
        if (!TenantNames::validUuid($tenantId) || !TenantNames::validUuid($memberId)) throw new \InvalidArgumentException('Tenant and member IDs must be UUIDs.');
        if (!in_array($role, ['owner', 'leader', 'choir', 'musician'], true)) throw new \InvalidArgumentException('Invalid tenant membership role.');
        $stmt = $db->prepare(
            'UPDATE lw_control.tenant_memberships
                SET role=:role
              WHERE tenant_id=:tenant_id AND member_id=:member_id
                AND inactivated_on IS NULL AND inactivated_by IS NULL'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'member_id' => $memberId, 'role' => $role]);
    }

    public static function deactivate(PDO $db, string $tenantId, string $memberId, string $changedBy): void
    {
        foreach ([$tenantId => 'Tenant ID', $memberId => 'Member ID', $changedBy => 'Changed-by actor ID'] as $value => $label) {
            if (!TenantNames::validUuid($value)) throw new \InvalidArgumentException("{$label} must be a UUID.");
        }
        $stmt = $db->prepare(
            'UPDATE lw_control.tenant_memberships
                SET inactivated_on=now(), inactivated_by=:changed_by
              WHERE tenant_id=:tenant_id AND member_id=:member_id
                AND inactivated_on IS NULL AND inactivated_by IS NULL'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'member_id' => $memberId, 'changed_by' => $changedBy]);
    }

    public static function deactivateTenant(PDO $db, string $tenantId, string $changedBy): void
    {
        if (!TenantNames::validUuid($tenantId) || !TenantNames::validUuid($changedBy)) throw new \InvalidArgumentException('Tenant and actor IDs must be UUIDs.');
        $stmt = $db->prepare(
            'UPDATE lw_control.tenant_memberships
                SET inactivated_on=now(), inactivated_by=:changed_by
              WHERE tenant_id=:tenant_id
                AND inactivated_on IS NULL AND inactivated_by IS NULL'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'changed_by' => $changedBy]);
    }

    public static function forActor(PDO $db, string $actorId): array
    {
        if (!TenantNames::validUuid($actorId)) throw new \InvalidArgumentException('Actor ID must be a UUID.');
        $stmt = $db->prepare(
            'SELECT t.id AS tenant_id, t.slug, t.display_name, tm.member_id,
                    tm.role, t.provisioning_state
               FROM lw_control.tenant_memberships tm
               JOIN lw_control.tenants t ON t.id=tm.tenant_id
              WHERE tm.actor_id=:actor_id
                AND tm.inactivated_on IS NULL AND tm.inactivated_by IS NULL
                AND t.provisioning_state=\'active\'
                AND t.inactivated_on IS NULL AND t.inactivated_by IS NULL
              ORDER BY lower(t.display_name), t.id'
        );
        $stmt->execute(['actor_id' => $actorId]);
        return $stmt->fetchAll();
    }

    public static function rebuild(PDO $db): int
    {
        $tenants = $db->query(
            "SELECT id, schema_name
               FROM lw_control.tenants
              WHERE provisioning_state='active'
                AND inactivated_on IS NULL AND inactivated_by IS NULL
              ORDER BY id"
        )->fetchAll();
        $count = 0;
        foreach ($tenants as $tenant) {
            $schema = self::identifier((string) $tenant['schema_name']);
            $exists = $db->query("SELECT to_regclass('" . trim($schema, '"') . ".members')")->fetchColumn();
            if ($exists === null) continue;
            $members = $db->query("SELECT id, actor_id, role FROM {$schema}.members WHERE inactivated_on IS NULL AND inactivated_by IS NULL")->fetchAll();
            foreach ($members as $member) {
                self::sync($db, (string) $tenant['id'], (string) $member['actor_id'], (string) $member['id'], (string) $member['role'], (string) $member['actor_id']);
                $count++;
            }
        }
        return $count;
    }

    private static function identifier(string $schemaName): string
    {
        if (!preg_match('/^lw_t_[0-9a-f]+$/', $schemaName)) throw new \RuntimeException('Tenant schema is invalid.');
        return '"' . $schemaName . '"';
    }
}
