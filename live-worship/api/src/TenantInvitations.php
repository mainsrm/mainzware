<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;
use Throwable;

final class TenantInvitations
{
    private const INVITATION_DAYS = 7;
    private const SYSTEM_ACTOR_ID = '00000000-0000-0000-0000-000000000001';

    public static function create(PDO $db, string $tenantId, string $actorId, string $role): array
    {
        self::validateUuid($tenantId, 'Tenant ID');
        self::validateUuid($actorId, 'Actor ID');
        self::validateRole($role);

        $tenant = self::tenant($db, $tenantId);
        if (!$tenant || $tenant['provisioning_state'] !== 'active') {
            throw new TenantInvitationError(404, 'This Live Worship team is not available for invitations.');
        }

        $code = self::newCode();
        $id = TenantNames::uuid();
        $expires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . self::INVITATION_DAYS . ' days')
            ->format('c');

        $db->beginTransaction();
        try {
            $insert = $db->prepare(
                'INSERT INTO lw_control.tenant_invitations
                    (id, tenant_id, role, token_hash, created_by, expires_on, activated_on, activated_by)
                 VALUES (:id, :tenant_id, :role, :token_hash, :created_by, :expires_on, now(), :activated_by)'
            );
            $insert->execute([
                'id' => $id,
                'tenant_id' => $tenantId,
                'role' => $role,
                'token_hash' => hash('sha256', $code),
                'created_by' => $actorId,
                'expires_on' => $expires,
                'activated_by' => $actorId,
            ]);
            self::audit($db, $tenantId, $actorId, 'tenant.invitation.created', [
                'invitation_id' => $id,
                'role' => $role,
                'expires_on' => $expires,
            ]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'role' => $role,
            'expires_on' => $expires,
            'code' => $code,
            'url_path' => '/live-worship/?invite=' . rawurlencode($code),
        ];
    }

    public static function forTenant(PDO $db, string $tenantId): array
    {
        self::validateUuid($tenantId, 'Tenant ID');
        $stmt = $db->prepare(
            "SELECT id, role, expires_on, created_by, accepted_by, accepted_on,
                    revoked_by, revoked_on,
                    CASE
                        WHEN accepted_on IS NOT NULL THEN 'accepted'
                        WHEN revoked_on IS NOT NULL THEN 'revoked'
                        WHEN expires_on <= now() THEN 'expired'
                        ELSE 'pending'
                    END AS invitation_state,
                    activated_on
               FROM lw_control.tenant_invitations
              WHERE tenant_id=:tenant_id
                AND inactivated_on IS NULL AND inactivated_by IS NULL
              ORDER BY activated_on DESC, id"
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll();
    }

    public static function revoke(PDO $db, string $tenantId, string $actorId, string $invitationId): void
    {
        self::validateUuid($tenantId, 'Tenant ID');
        self::validateUuid($actorId, 'Actor ID');
        self::validateUuid($invitationId, 'Invitation ID');
        $db->beginTransaction();
        try {
            $find = $db->prepare(
                "SELECT id, role, expires_on
                   FROM lw_control.tenant_invitations
                  WHERE id=:id AND tenant_id=:tenant_id
                    AND inactivated_on IS NULL AND inactivated_by IS NULL
                    AND accepted_on IS NULL AND revoked_on IS NULL
                  FOR UPDATE"
            );
            $find->execute(['id' => $invitationId, 'tenant_id' => $tenantId]);
            $row = $find->fetch();
            if (!$row) throw new TenantInvitationError(404, 'Invitation not found or already decided.');
            $update = $db->prepare(
                'UPDATE lw_control.tenant_invitations
                    SET revoked_by=:actor_id, revoked_on=now(),
                        inactivated_on=now(), inactivated_by=:actor_id
                  WHERE id=:id'
            );
            $update->execute(['id' => $invitationId, 'actor_id' => $actorId]);
            self::audit($db, $tenantId, $actorId, 'tenant.invitation.revoked', [
                'invitation_id' => $invitationId,
                'role' => $row['role'],
            ]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    public static function accept(PDO $db, string $code, string $actorId): array
    {
        self::validateUuid($actorId, 'Actor ID');
        $code = strtoupper(trim($code));
        if (!preg_match('/^LW-[A-Z0-9_-]{20,80}$/', $code)) {
            throw new TenantInvitationError(400, 'Enter a valid Live Worship invitation code.');
        }

        $db->beginTransaction();
        try {
            $find = $db->prepare(
                'SELECT i.id, i.tenant_id, i.role, i.expires_on,
                        i.accepted_by, i.accepted_on, i.revoked_by, i.revoked_on,
                        t.slug, t.display_name, t.schema_name, t.provisioning_state
                   FROM lw_control.tenant_invitations i
                   JOIN lw_control.tenants t ON t.id=i.tenant_id
                  WHERE i.token_hash=:token_hash
                    AND i.inactivated_on IS NULL AND i.inactivated_by IS NULL
                  FOR UPDATE'
            );
            $find->execute(['token_hash' => hash('sha256', $code)]);
            $invitation = $find->fetch();
            if (!$invitation) throw new TenantInvitationError(404, 'That invitation is invalid or has already been used.');
            if ($invitation['accepted_on'] !== null || $invitation['revoked_on'] !== null) {
                throw new TenantInvitationError(409, 'That invitation has already been decided.');
            }
            if ($invitation['provisioning_state'] !== 'active') {
                throw new TenantInvitationError(409, 'This Live Worship team is not available yet.');
            }
            if (strtotime((string) $invitation['expires_on']) <= time()) {
                self::expire($db, $invitation['id'], $actorId);
                self::audit($db, $invitation['tenant_id'], $actorId, 'tenant.invitation.expired', [
                    'invitation_id' => $invitation['id'],
                    'role' => $invitation['role'],
                ]);
                $db->commit();
                throw new TenantInvitationError(410, 'That invitation has expired. Ask the worship leader for a new invitation.');
            }

            $schema = self::identifier((string) $invitation['schema_name']);
            $active = $db->prepare("SELECT id, role FROM {$schema}.members WHERE actor_id=:actor_id AND inactivated_on IS NULL AND inactivated_by IS NULL FOR UPDATE");
            $active->execute(['actor_id' => $actorId]);
            if ($active->fetch()) throw new TenantInvitationError(409, 'You are already a member of this Live Worship team.');

            $historical = $db->prepare("SELECT id FROM {$schema}.members WHERE actor_id=:actor_id ORDER BY activated_on DESC LIMIT 1");
            $historical->execute(['actor_id' => $actorId]);
            $memberId = $historical->fetchColumn();
            if ($memberId !== false) {
                $save = $db->prepare("UPDATE {$schema}.members SET role=:role, activated_on=now(), activated_by=:by, inactivated_on=NULL, inactivated_by=NULL WHERE id=:id");
                $save->execute(['id' => $memberId, 'role' => $invitation['role'], 'by' => $actorId]);
            } else {
                $memberId = TenantNames::uuid();
                $save = $db->prepare("INSERT INTO {$schema}.members (id, actor_id, role, activated_on, activated_by) VALUES (:id, :actor_id, :role, now(), :by)");
                $save->execute(['id' => $memberId, 'actor_id' => $actorId, 'role' => $invitation['role'], 'by' => $actorId]);
            }
            TenantMembershipDirectory::sync($db, (string) $invitation['tenant_id'], $actorId, (string) $memberId, (string) $invitation['role'], $actorId);

            $consume = $db->prepare(
                'UPDATE lw_control.tenant_invitations
                    SET accepted_by=:actor_id, accepted_on=now(),
                        inactivated_on=now(), inactivated_by=:actor_id
                  WHERE id=:id'
            );
            $consume->execute(['id' => $invitation['id'], 'actor_id' => $actorId]);
            self::audit($db, $invitation['tenant_id'], $actorId, 'tenant.invitation.accepted', [
                'invitation_id' => $invitation['id'],
                'member_id' => $memberId,
                'role' => $invitation['role'],
            ]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        return [
            'tenant_id' => (string) $invitation['tenant_id'],
            'slug' => (string) $invitation['slug'],
            'display_name' => (string) $invitation['display_name'],
            'role' => (string) $invitation['role'],
            'member_id' => (string) $memberId,
        ];
    }

    private static function tenant(PDO $db, string $tenantId): ?array
    {
        $stmt = $db->prepare('SELECT id, slug, display_name, schema_name, provisioning_state FROM lw_control.tenants WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL');
        $stmt->execute(['id' => $tenantId]);
        return $stmt->fetch() ?: null;
    }

    private static function expire(PDO $db, string $invitationId, string $actorId): void
    {
        $stmt = $db->prepare(
            'UPDATE lw_control.tenant_invitations
                SET inactivated_on=now(), inactivated_by=:actor_id
              WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL'
        );
        $stmt->execute(['id' => $invitationId, 'actor_id' => $actorId]);
    }

    private static function audit(PDO $db, string $tenantId, string $actorId, string $eventType, array $payload): void
    {
        $stmt = $db->prepare(
            'INSERT INTO lw_control.audit_events
                (id, tenant_id, actor_id, event_type, payload, activated_on, activated_by)
             VALUES (:id, :tenant_id, :actor_id, :event_type, CAST(:payload AS jsonb), now(), :actor_id)'
        );
        $stmt->execute([
            'id' => TenantNames::uuid(),
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    private static function newCode(): string
    {
        return 'LW-' . strtoupper(rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='));
    }

    private static function validateRole(string $role): void
    {
        if (!in_array($role, ['leader', 'choir', 'musician'], true)) throw new TenantInvitationError(400, 'Choose leader, choir, or musician for the invitation.');
    }

    private static function validateUuid(string $value, string $label): void
    {
        if (!TenantNames::validUuid($value)) throw new \InvalidArgumentException("{$label} must be a UUID.");
    }

    private static function identifier(string $schemaName): string
    {
        if (!preg_match('/^lw_t_[0-9a-f]+$/', $schemaName)) throw new TenantInvitationError(500, 'The tenant schema is invalid.');
        return '"' . $schemaName . '"';
    }
}
