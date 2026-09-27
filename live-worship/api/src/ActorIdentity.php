<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;
use PDOException;

final class ActorIdentity
{
    private const SYSTEM_ACTOR_ID = '00000000-0000-0000-0000-000000000001';

    public static function ensureMainzWareActor(PDO $db, array $identity): string
    {
        $userId = (int) ($identity['id'] ?? 0);
        if ($userId < 1) throw new \InvalidArgumentException('A valid MainzWare identity is required.');

        $provider = 'mainzworld';
        $subject = (string) $userId;
        $find = $db->prepare(
            'SELECT id
               FROM lw_control.actors
              WHERE identity_provider = :provider
                AND external_subject = :subject
                AND inactivated_on IS NULL AND inactivated_by IS NULL
              LIMIT 1'
        );
        $find->execute(['provider' => $provider, 'subject' => $subject]);
        $activeId = $find->fetchColumn();
        if ($activeId !== false) return (string) $activeId;

        // Preserve the actor UUID if an identity was previously inactivated.
        $historical = $db->prepare(
            'SELECT id
               FROM lw_control.actors
              WHERE identity_provider = :provider
                AND external_subject = :subject
              ORDER BY activated_on DESC
              LIMIT 1'
        );
        $historical->execute(['provider' => $provider, 'subject' => $subject]);
        $actorId = $historical->fetchColumn();
        $displayName = trim((string) ($identity['username'] ?? "MainzWare user {$userId}"));
        if ($displayName === '') $displayName = "MainzWare user {$userId}";

        if ($actorId !== false) {
            $reactivate = $db->prepare(
                'UPDATE lw_control.actors
                    SET display_name = :display_name,
                        activated_on = now(), activated_by = :activated_by,
                        inactivated_on = NULL, inactivated_by = NULL
                  WHERE id = :id'
            );
            $reactivate->execute([
                'display_name' => $displayName,
                'activated_by' => self::SYSTEM_ACTOR_ID,
                'id' => $actorId,
            ]);
            return (string) $actorId;
        }

        $actorId = TenantNames::uuid();
        try {
            $insert = $db->prepare(
                'INSERT INTO lw_control.actors
                    (id, identity_provider, external_subject, display_name, activated_on, activated_by)
                 VALUES (:id, :provider, :subject, :display_name, now(), :activated_by)'
            );
            $insert->execute([
                'id' => $actorId,
                'provider' => $provider,
                'subject' => $subject,
                'display_name' => $displayName,
                'activated_by' => self::SYSTEM_ACTOR_ID,
            ]);
            return $actorId;
        } catch (PDOException $error) {
            // Another request may have created the actor between the lookup and
            // insert. The active identity index makes the retry safe.
            $find->execute(['provider' => $provider, 'subject' => $subject]);
            $activeId = $find->fetchColumn();
            if ($activeId !== false) return (string) $activeId;
            throw $error;
        }
    }
}
