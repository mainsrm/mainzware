<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;

final class LoginTracking
{
    public static function record(
        PDO $db,
        ?string $actorId,
        ?string $tenantId,
        string $eventType,
        string $outcome,
        string $authMode,
        string $clientKind = 'web',
        ?string $usernameHint = null,
        ?string $failureCode = null
    ): void {
        foreach ([[$actorId, 'Actor ID'], [$tenantId, 'Tenant ID']] as [$value, $label]) {
            if ($value !== null && !TenantNames::validUuid($value)) throw new \InvalidArgumentException("{$label} must be a UUID.");
        }
        if (!in_array($eventType, ['login', 'logout', 'tenant_context'], true)) throw new \InvalidArgumentException('Invalid login event type.');
        if (!in_array($outcome, ['success', 'failure'], true)) throw new \InvalidArgumentException('Invalid login event outcome.');
        if (!in_array($authMode, ['mainzworld', 'standalone', 'mobile'], true)) throw new \InvalidArgumentException('Invalid login authentication mode.');
        if (!in_array($clientKind, ['web', 'mobile', 'unknown'], true)) $clientKind = 'unknown';

        $stmt = $db->prepare(
            'INSERT INTO lw_control.login_activity
                (id, actor_id, tenant_id, event_type, outcome, auth_mode, client_kind,
                 username_hint, failure_code, occurred_on, activated_on, activated_by)
             VALUES (:id, :actor_id, :tenant_id, :event_type, :outcome, :auth_mode,
                     :client_kind, :username_hint, :failure_code, now(), now(), :activated_by)'
        );
        $systemActor = '00000000-0000-0000-0000-000000000001';
        $stmt->execute([
            'id' => TenantNames::uuid(),
            'actor_id' => $actorId,
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'outcome' => $outcome,
            'auth_mode' => $authMode,
            'client_kind' => $clientKind,
            'username_hint' => $usernameHint !== null ? mb_substr(trim($usernameHint), 0, 120) : null,
            'failure_code' => $failureCode !== null ? mb_substr(trim($failureCode), 0, 80) : null,
            'activated_by' => $actorId ?? $systemActor,
        ]);
    }

    public static function recent(PDO $db, ?string $tenantId = null, ?string $outcome = null, int $limit = 100): array
    {
        if ($tenantId !== null && !TenantNames::validUuid($tenantId)) throw new \InvalidArgumentException('Tenant ID must be a UUID.');
        if ($outcome !== null && !in_array($outcome, ['success', 'failure'], true)) throw new \InvalidArgumentException('Invalid login event outcome.');
        $limit = min(200, max(1, $limit));
        $sql =
            'SELECT la.id, la.actor_id, la.tenant_id,
                    COALESCE(NULLIF(trim(a.display_name), \'\'), a.external_subject, la.username_hint) AS username,
                    t.slug, t.display_name AS tenant_name,
                    la.event_type, la.outcome, la.auth_mode, la.client_kind,
                    la.failure_code, la.occurred_on
               FROM lw_control.login_activity la
               LEFT JOIN lw_control.actors a ON a.id=la.actor_id
               LEFT JOIN lw_control.tenants t ON t.id=la.tenant_id
              WHERE la.inactivated_on IS NULL AND la.inactivated_by IS NULL';
        $params = [];
        if ($tenantId !== null) { $sql .= ' AND la.tenant_id=:tenant_id'; $params['tenant_id'] = $tenantId; }
        if ($outcome !== null) { $sql .= ' AND la.outcome=:outcome'; $params['outcome'] = $outcome; }
        $sql .= ' ORDER BY la.occurred_on DESC, la.id DESC LIMIT :limit';
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function clientKind(): string
    {
        $value = strtolower(trim((string) ($_SERVER['HTTP_X_MAINZWARE_CLIENT'] ?? '')));
        return in_array($value, ['web', 'mobile'], true) ? $value : 'web';
    }
}
