<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;
use Throwable;

final class SupportSessions
{
    private const DEFAULT_MINUTES = 15;
    private const MAX_MINUTES = 30;

    public static function start(PDO $db, string $adminActorId, string $tenantId, int $minutes, string $reason): array
    {
        self::uuid($adminActorId, 'Admin actor ID');
        self::uuid($tenantId, 'Tenant ID');
        $minutes = $minutes > 0 ? $minutes : self::DEFAULT_MINUTES;
        if ($minutes < 5 || $minutes > self::MAX_MINUTES) throw new SupportSessionError(400, 'Support sessions must be between 5 and 30 minutes.');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) throw new SupportSessionError(400, 'Enter a support reason up to 500 characters.');

        $tenant = $db->prepare(
            "SELECT id, slug, display_name
               FROM lw_control.tenants
              WHERE id=:id AND provisioning_state='active'
                AND inactivated_on IS NULL AND inactivated_by IS NULL"
        );
        $tenant->execute(['id' => $tenantId]);
        $tenantRow = $tenant->fetch();
        if (!$tenantRow) throw new SupportSessionError(404, 'Tenant not found or unavailable.');

        $db->beginTransaction();
        try {
            self::endOpenSessions($db, $adminActorId, $adminActorId, 'support.session.replaced');
            $id = TenantNames::uuid();
            $started = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $expires = $started->modify("+{$minutes} minutes");
            $insert = $db->prepare(
                'INSERT INTO lw_control.support_sessions
                    (id, admin_actor_id, tenant_id, mode, reason, started_on, expires_on,
                     last_seen_on, activated_on, activated_by)
                 VALUES (:id, :admin_actor_id, :tenant_id, \'read_only\', :reason,
                         :started_on, :expires_on, :started_on, :started_on, :admin_actor_id)'
            );
            $insert->execute([
                'id' => $id,
                'admin_actor_id' => $adminActorId,
                'tenant_id' => $tenantId,
                'reason' => $reason,
                'started_on' => $started->format('c'),
                'expires_on' => $expires->format('c'),
            ]);
            self::audit($db, $tenantId, $adminActorId, 'support.session.started', [
                'support_session_id' => $id,
                'mode' => 'read_only',
                'expires_on' => $expires->format('c'),
                'reason' => $reason,
            ]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'slug' => (string) $tenantRow['slug'],
            'display_name' => (string) $tenantRow['display_name'],
            'mode' => 'read_only',
            'reason' => $reason,
            'started_on' => $started->format('c'),
            'expires_on' => $expires->format('c'),
            'launch_path' => '/live-worship/' . rawurlencode((string) $tenantRow['slug']),
        ];
    }

    public static function current(PDO $db, string $adminActorId): ?array
    {
        self::uuid($adminActorId, 'Admin actor ID');
        $sessionId = trim((string) ($_SESSION['live_worship_support_session_id'] ?? ''));
        if ($sessionId === '') return null;
        self::uuid($sessionId, 'Support session ID');
        $row = self::row($db, $sessionId, $adminActorId);
        if (!$row) {
            unset($_SESSION['live_worship_support_session_id'], $_SESSION['live_worship_tenant_id']);
            return null;
        }
        if (strtotime((string) $row['expires_on']) <= time()) {
            self::expire($db, $row, $adminActorId);
            unset($_SESSION['live_worship_support_session_id'], $_SESSION['live_worship_tenant_id']);
            return null;
        }
        self::touch($db, $row['id']);
        return self::publicRow($row);
    }

    public static function context(PDO $db, array $identity, string $tenantId): ?array
    {
        $sessionId = trim((string) ($_SESSION['live_worship_support_session_id'] ?? ''));
        if ($sessionId === '') return null;
        if (($identity['role'] ?? '') !== 'admin') throw new SupportSessionError(403, 'Support sessions require a MainzWare administrator.');
        self::uuid($tenantId, 'Tenant ID');
        $adminActorId = ActorIdentity::ensureMainzWareActor($db, $identity);
        self::uuid($sessionId, 'Support session ID');
        $row = self::row($db, $sessionId, $adminActorId);
        if (!$row) throw new SupportSessionError(403, 'The support session is no longer active.');
        if ((string) $row['tenant_id'] !== $tenantId) throw new SupportSessionError(403, 'The active support session is bound to another team.');
        if (strtotime((string) $row['expires_on']) <= time()) {
            self::expire($db, $row, $adminActorId);
            unset($_SESSION['live_worship_support_session_id'], $_SESSION['live_worship_tenant_id']);
            throw new SupportSessionError(403, 'The support session has expired.');
        }
        self::touch($db, $row['id']);
        return $row;
    }

    public static function end(PDO $db, string $adminActorId, string $sessionId): void
    {
        self::uuid($adminActorId, 'Admin actor ID');
        self::uuid($sessionId, 'Support session ID');
        $db->beginTransaction();
        try {
            $row = self::row($db, $sessionId, $adminActorId, true);
            if (!$row) throw new SupportSessionError(404, 'Support session not found.');
            $update = $db->prepare(
                'UPDATE lw_control.support_sessions
                    SET ended_on=now(), ended_by=:admin_actor_id,
                        inactivated_on=now(), inactivated_by=:admin_actor_id
                  WHERE id=:id'
            );
            $update->execute(['id' => $sessionId, 'admin_actor_id' => $adminActorId]);
            self::audit($db, $row['tenant_id'], $adminActorId, 'support.session.ended', ['support_session_id' => $sessionId]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        if ((string) ($_SESSION['live_worship_support_session_id'] ?? '') === $sessionId) {
            unset($_SESSION['live_worship_support_session_id'], $_SESSION['live_worship_tenant_id']);
        }
    }

    private static function endOpenSessions(PDO $db, string $adminActorId, string $endedBy, string $eventType): void
    {
        $rows = $db->prepare(
            'SELECT id, tenant_id
               FROM lw_control.support_sessions
              WHERE admin_actor_id=:admin_actor_id
                AND inactivated_on IS NULL AND inactivated_by IS NULL
              FOR UPDATE'
        );
        $rows->execute(['admin_actor_id' => $adminActorId]);
        foreach ($rows->fetchAll() as $row) {
            $update = $db->prepare(
                'UPDATE lw_control.support_sessions
                    SET ended_on=now(), ended_by=:ended_by,
                        inactivated_on=now(), inactivated_by=:ended_by
                  WHERE id=:id'
            );
            $update->execute(['id' => $row['id'], 'ended_by' => $endedBy]);
            self::audit($db, $row['tenant_id'], $endedBy, $eventType, ['support_session_id' => $row['id']]);
        }
    }

    private static function row(PDO $db, string $sessionId, string $adminActorId, bool $forUpdate = false): ?array
    {
        $sql =
            'SELECT s.id, s.admin_actor_id, s.tenant_id, s.mode, s.reason,
                    s.started_on, s.expires_on, s.last_seen_on,
                    t.slug, t.display_name
               FROM lw_control.support_sessions s
               JOIN lw_control.tenants t ON t.id=s.tenant_id
              WHERE s.id=:id AND s.admin_actor_id=:admin_actor_id
                AND s.inactivated_on IS NULL AND s.inactivated_by IS NULL
                AND s.ended_on IS NULL AND s.ended_by IS NULL';
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $stmt = $db->prepare($sql);
        $stmt->execute(['id' => $sessionId, 'admin_actor_id' => $adminActorId]);
        return $stmt->fetch() ?: null;
    }

    private static function touch(PDO $db, string $sessionId): void
    {
        $stmt = $db->prepare('UPDATE lw_control.support_sessions SET last_seen_on=now() WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL');
        $stmt->execute(['id' => $sessionId]);
    }

    private static function expire(PDO $db, array $row, string $actorId): void
    {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'UPDATE lw_control.support_sessions
                    SET ended_on=expires_on, ended_by=:actor_id,
                        inactivated_on=now(), inactivated_by=:actor_id
                  WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL'
            );
            $stmt->execute(['id' => $row['id'], 'actor_id' => $actorId]);
            self::audit($db, $row['tenant_id'], $actorId, 'support.session.expired', ['support_session_id' => $row['id']]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
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

    private static function publicRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'tenant_id' => (string) $row['tenant_id'],
            'slug' => (string) $row['slug'],
            'display_name' => (string) $row['display_name'],
            'mode' => (string) $row['mode'],
            'reason' => (string) $row['reason'],
            'started_on' => $row['started_on'],
            'expires_on' => $row['expires_on'],
            'last_seen_on' => $row['last_seen_on'],
            'launch_path' => '/live-worship/' . rawurlencode((string) $row['slug']),
        ];
    }

    private static function uuid(string $value, string $label): void
    {
        if (!TenantNames::validUuid($value)) throw new SupportSessionError(400, "{$label} must be a UUID.");
    }
}
