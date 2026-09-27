<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;
use PDOException;

final class TenantProvisioner
{
    public static function request(PDO $db, string $displayName, string $ownerActorId): array
    {
        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 120) throw new \InvalidArgumentException('Team name must be between 1 and 120 characters.');
        if (!TenantNames::validUuid($ownerActorId)) throw new \InvalidArgumentException('Owner actor ID must be a UUID.');
        $slug = TenantNames::slugFromDisplayName($displayName);
        $tenantId = TenantNames::uuid();
        $schemaName = TenantNames::schemaName($tenantId);
        $storagePrefix = TenantNames::storagePrefix($tenantId);
        $jobId = TenantNames::uuid();

        try {
            $db->beginTransaction();
            $existing = $db->prepare(
                'SELECT id
                   FROM lw_control.tenants
                  WHERE lower(slug) = lower(:slug)
                    AND inactivated_on IS NULL AND inactivated_by IS NULL
                  FOR UPDATE'
            );
            $existing->execute(['slug' => $slug]);
            if ($existing->fetchColumn() !== false) {
                throw new TenantConflict('That team name is already in use. Choose a more specific team name.');
            }

            $insertTenant = $db->prepare(
                'INSERT INTO lw_control.tenants
                    (id, slug, display_name, schema_name, storage_prefix, provisioning_state, activated_on, activated_by)
                 VALUES (:id, :slug, :display_name, :schema_name, :storage_prefix, \'provisioning\', now(), :activated_by)'
            );
            $insertTenant->execute([
                'id' => $tenantId,
                'slug' => $slug,
                'display_name' => $displayName,
                'schema_name' => $schemaName,
                'storage_prefix' => $storagePrefix,
                'activated_by' => $ownerActorId,
            ]);

            $insertJob = $db->prepare(
                'INSERT INTO lw_control.provisioning_jobs
                    (id, tenant_id, operation, job_state, attempt_count, activated_on, activated_by, updated_on)
                 VALUES (:id, :tenant_id, \'provision\', \'queued\', 0, now(), :activated_by, now())'
            );
            $insertJob->execute([
                'id' => $jobId,
                'tenant_id' => $tenantId,
                'activated_by' => $ownerActorId,
            ]);
            $db->commit();
        } catch (TenantConflict $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            $existing = $db->prepare(
                'SELECT id
                   FROM lw_control.tenants
                  WHERE lower(slug) = lower(:slug)
                    AND inactivated_on IS NULL AND inactivated_by IS NULL
                  LIMIT 1'
            );
            $existing->execute(['slug' => $slug]);
            if ($existing->fetchColumn() !== false) {
                throw new TenantConflict('That team name is already in use. Choose a more specific team name.');
            }
            throw $error;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        return [
            'id' => $tenantId,
            'slug' => $slug,
            'display_name' => $displayName,
            'schema_name' => $schemaName,
            'url_path' => '/live-worship/' . $slug,
            'provisioning_state' => 'provisioning',
            'job_state' => 'queued',
        ];
    }

    public static function provision(PDO $db, string $displayName, string $ownerActorId, ?string $requestedSlug = null): array
    {
        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 120) throw new \InvalidArgumentException('Team name must be between 1 and 120 characters.');
        if (!TenantNames::validUuid($ownerActorId)) throw new \InvalidArgumentException('Owner actor ID must be a UUID.');
        $slug = TenantNames::slugFromDisplayName($displayName);
        if ($requestedSlug !== null && trim(strtolower($requestedSlug)) !== $slug) {
            throw new \InvalidArgumentException("The supplied slug must match the formal slug generated from the team name: {$slug}.");
        }

        PlatformMigrations::applyPlatform($db);
        $existing = $db->prepare(
            'SELECT id, schema_name, provisioning_state FROM lw_control.tenants
              WHERE lower(slug) = lower(:slug)
                AND inactivated_on IS NULL AND inactivated_by IS NULL'
        );
        $existing->execute(['slug' => $slug]);
        $row = $existing->fetch();
        if ($row) {
            if ($row['provisioning_state'] === 'provisioning' || $row['provisioning_state'] === 'failed') {
                try {
                    self::finishExisting($db, $row, $displayName, $ownerActorId);
                } catch (\Throwable $error) {
                    self::markProvisioningFailed($db, $row['id'], $error->getMessage());
                    throw $error;
                }
                return ['id' => $row['id'], 'slug' => $slug, 'schema_name' => $row['schema_name'], 'resumed' => true];
            }
            throw new \RuntimeException('An active team already uses that slug.');
        }

        $tenantId = TenantNames::uuid();
        $schemaName = TenantNames::schemaName($tenantId);
        $storagePrefix = TenantNames::storagePrefix($tenantId);
        $db->beginTransaction();
        try {
            $insert = $db->prepare(
                'INSERT INTO lw_control.tenants
                    (id, slug, display_name, schema_name, storage_prefix, provisioning_state, activated_on, activated_by)
                 VALUES (:id, :slug, :display_name, :schema_name, :storage_prefix, \'provisioning\', now(), :activated_by)'
            );
            $insert->execute([
                'id' => $tenantId,
                'slug' => $slug,
                'display_name' => $displayName,
                'schema_name' => $schemaName,
                'storage_prefix' => $storagePrefix,
                'activated_by' => $ownerActorId,
            ]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        $row = ['id' => $tenantId, 'schema_name' => $schemaName, 'provisioning_state' => 'provisioning'];
        try {
            self::finishExisting($db, $row, $displayName, $ownerActorId);
        } catch (\Throwable $error) {
            self::markProvisioningFailed($db, $tenantId, $error->getMessage());
            throw $error;
        }
        return ['id' => $tenantId, 'slug' => $slug, 'schema_name' => $schemaName, 'resumed' => false];
    }

    public static function migrateRegisteredTenants(PDO $db): int
    {
        PlatformMigrations::applyPlatform($db);
        $rows = $db->query(
            'SELECT id, schema_name FROM lw_control.tenants
              WHERE provisioning_state = \'active\'
                AND inactivated_on IS NULL AND inactivated_by IS NULL
              ORDER BY schema_name'
        )->fetchAll();
        foreach ($rows as $row) PlatformMigrations::applyTenant($db, $row['schema_name']);
        return count($rows);
    }

    public static function processQueued(PDO $db, int $limit = 10, bool $retryFailed = false): array
    {
        if ($limit < 1 || $limit > 100) throw new \InvalidArgumentException('Provisioning limit must be between 1 and 100.');
        PlatformMigrations::applyPlatform($db);
        $results = [];
        $failedClause = $retryFailed ? " OR j.job_state = 'failed'" : '';

        for ($processed = 0; $processed < $limit; $processed++) {
            $db->beginTransaction();
            try {
                $claim = $db->query(
                    "SELECT j.id AS job_id, j.tenant_id, t.display_name, t.schema_name,
                            t.activated_by AS owner_actor_id
                       FROM lw_control.provisioning_jobs j
                       JOIN lw_control.tenants t ON t.id = j.tenant_id
                      WHERE j.inactivated_on IS NULL AND j.inactivated_by IS NULL
                        AND j.operation = 'provision'
                        AND t.inactivated_on IS NULL AND t.inactivated_by IS NULL
                        AND t.provisioning_state IN ('provisioning', 'failed')
                        AND (
                            j.job_state = 'queued'
                            {$failedClause}
                            OR (j.job_state = 'running' AND j.updated_on < now() - interval '15 minutes')
                        )
                      ORDER BY j.updated_on, j.activated_on
                      FOR UPDATE SKIP LOCKED
                      LIMIT 1"
                )->fetch();
                if (!$claim) {
                    $db->commit();
                    break;
                }

                $update = $db->prepare(
                    'UPDATE lw_control.provisioning_jobs
                        SET job_state=\'running\', attempt_count=attempt_count + 1,
                            last_error=NULL, updated_on=now()
                      WHERE id=:id'
                );
                $update->execute(['id' => $claim['job_id']]);
                $db->commit();
            } catch (\Throwable $error) {
                if ($db->inTransaction()) $db->rollBack();
                throw $error;
            }

            try {
                self::finishExisting($db, [
                    'id' => $claim['tenant_id'],
                    'schema_name' => $claim['schema_name'],
                    'provisioning_state' => 'provisioning',
                ], $claim['display_name'], $claim['owner_actor_id']);
                $results[] = [
                    'id' => $claim['tenant_id'],
                    'state' => 'active',
                    'schema_name' => $claim['schema_name'],
                ];
            } catch (\Throwable $error) {
                self::markProvisioningFailed($db, $claim['tenant_id'], $error->getMessage());
                $results[] = [
                    'id' => $claim['tenant_id'],
                    'state' => 'failed',
                    'error' => $error->getMessage(),
                ];
            }
        }
        return $results;
    }

    public static function requestDeprovision(PDO $db, string $tenantId, string $requestedBy, int $retentionDays = 30, string $reason = ''): array
    {
        if (!TenantNames::validUuid($tenantId)) throw new \InvalidArgumentException('Tenant ID must be a UUID.');
        if (!TenantNames::validUuid($requestedBy)) throw new \InvalidArgumentException('Requester actor ID must be a UUID.');
        if ($retentionDays < 0 || $retentionDays > 3650) throw new \InvalidArgumentException('Retention must be between 0 and 3650 days.');
        $reason = trim($reason);
        if (mb_strlen($reason) > 1000) throw new \InvalidArgumentException('The deprovision reason must be 1000 characters or fewer.');
        $retentionUntil = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("+{$retentionDays} days")
            ->format('c');

        try {
            $db->beginTransaction();
            $tenantQuery = $db->prepare(
                'SELECT id, slug, display_name, schema_name, provisioning_state, retention_until,
                        inactivated_on, inactivated_by
                   FROM lw_control.tenants
                  WHERE id=:id
                  FOR UPDATE'
            );
            $tenantQuery->execute(['id' => $tenantId]);
            $tenant = $tenantQuery->fetch();
            if (!$tenant) throw new TenantConflict('Tenant not found.');
            if ($tenant['provisioning_state'] === 'deprovisioned' || $tenant['inactivated_on'] !== null || $tenant['inactivated_by'] !== null) {
                throw new TenantConflict('That tenant has already been deprovisioned.');
            }

            $existingJob = $db->prepare(
                'SELECT id, job_state, activated_on, activated_by
                   FROM lw_control.provisioning_jobs
                  WHERE tenant_id=:tenant_id
                    AND operation=\'deprovision\'
                    AND inactivated_on IS NULL AND inactivated_by IS NULL
                  ORDER BY activated_on DESC
                  LIMIT 1'
            );
            $existingJob->execute(['tenant_id' => $tenantId]);
            $job = $existingJob->fetch();
            if ($tenant['provisioning_state'] === 'deprovisioning' && $job) {
                $db->commit();
                return self::deprovisionResponse($tenant, $job);
            }
            if (!in_array($tenant['provisioning_state'], ['provisioning', 'active', 'suspended', 'failed', 'deprovisioning'], true)) {
                throw new TenantConflict('That tenant is not eligible for deprovisioning.');
            }

            $updateTenant = $db->prepare(
                'UPDATE lw_control.tenants
                    SET provisioning_state=\'deprovisioning\', retention_until=:retention_until,
                        deprovision_reason=:reason, updated_on=now()
                  WHERE id=:id'
            );
            $updateTenant->execute([
                'retention_until' => $retentionUntil,
                'reason' => $reason !== '' ? $reason : null,
                'id' => $tenantId,
            ]);

            // A tenant leaving service cannot retain an administrator's support
            // session. End it in the same transaction so the support browser
            // loses access as soon as the tenant enters deprovisioning.
            $endedSupport = $db->prepare(
                'UPDATE lw_control.support_sessions
                    SET ended_on=now(), ended_by=:actor,
                        inactivated_on=now(), inactivated_by=:actor
                  WHERE tenant_id=:tenant_id
                    AND inactivated_on IS NULL AND inactivated_by IS NULL
                 RETURNING id'
            );
            $endedSupport->execute(['tenant_id' => $tenantId, 'actor' => $requestedBy]);
            foreach ($endedSupport->fetchAll() as $supportRow) {
                $db->prepare(
                    'INSERT INTO lw_control.audit_events
                        (id, tenant_id, actor_id, event_type, payload, activated_on, activated_by)
                     VALUES (:id, :tenant_id, :actor_id, \'support.session.ended\', CAST(:payload AS jsonb), now(), :activated_by)'
                )->execute([
                    'id' => TenantNames::uuid(),
                    'tenant_id' => $tenantId,
                    'actor_id' => $requestedBy,
                    'activated_by' => $requestedBy,
                    'payload' => json_encode([
                        'support_session_id' => $supportRow['id'],
                        'reason' => 'tenant.deprovisioning',
                    ], JSON_THROW_ON_ERROR),
                ]);
            }

            $db->prepare(
                'UPDATE lw_control.tenant_entitlements
                    SET inactivated_on=now(), inactivated_by=:actor
                  WHERE tenant_id=:tenant_id
                    AND inactivated_on IS NULL AND inactivated_by IS NULL'
            )->execute(['tenant_id' => $tenantId, 'actor' => $requestedBy]);

            $db->prepare(
                'UPDATE lw_control.provisioning_jobs
                    SET job_state=\'failed\', last_error=\'Superseded by tenant deprovisioning\',
                        updated_on=now(), inactivated_on=now(), inactivated_by=:actor
                  WHERE tenant_id=:tenant_id
                    AND operation=\'provision\'
                    AND inactivated_on IS NULL AND inactivated_by IS NULL
                    AND job_state IN (\'queued\', \'running\', \'failed\')'
            )->execute(['tenant_id' => $tenantId, 'actor' => $requestedBy]);

            $jobId = TenantNames::uuid();
            $insertJob = $db->prepare(
                'INSERT INTO lw_control.provisioning_jobs
                    (id, tenant_id, operation, job_state, attempt_count, activated_on, activated_by, updated_on)
                 VALUES (:id, :tenant_id, \'deprovision\', \'queued\', 0, now(), :activated_by, now())'
            );
            $insertJob->execute([
                'id' => $jobId,
                'tenant_id' => $tenantId,
                'activated_by' => $requestedBy,
            ]);
            $db->commit();
        } catch (TenantConflict $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        return [
            'id' => $tenantId,
            'slug' => $tenant['slug'],
            'display_name' => $tenant['display_name'],
            'schema_name' => $tenant['schema_name'],
            'provisioning_state' => 'deprovisioning',
            'job_id' => $jobId,
            'job_state' => 'queued',
            'retention_until' => $retentionUntil,
        ];
    }

    public static function processDeprovisionQueued(PDO $db, int $limit = 10, bool $force = false): array
    {
        if ($limit < 1 || $limit > 100) throw new \InvalidArgumentException('Deprovisioning limit must be between 1 and 100.');
        PlatformMigrations::applyPlatform($db);
        $results = [];
        $retentionClause = $force ? '' : "AND (t.retention_until IS NULL OR t.retention_until <= now())";

        for ($processed = 0; $processed < $limit; $processed++) {
            $db->beginTransaction();
            try {
                $claim = $db->query(
                    "SELECT j.id AS job_id, j.tenant_id, j.activated_by AS requested_by,
                            t.slug, t.display_name, t.schema_name, t.storage_prefix, t.retention_until
                       FROM lw_control.provisioning_jobs j
                       JOIN lw_control.tenants t ON t.id = j.tenant_id
                      WHERE j.operation = 'deprovision'
                        AND j.inactivated_on IS NULL AND j.inactivated_by IS NULL
                        AND j.job_state IN ('queued', 'running')
                        AND t.provisioning_state = 'deprovisioning'
                        AND t.inactivated_on IS NULL AND t.inactivated_by IS NULL
                        {$retentionClause}
                        AND (j.job_state = 'queued' OR j.updated_on < now() - interval '15 minutes')
                      ORDER BY t.retention_until NULLS FIRST, j.updated_on, j.activated_on
                      FOR UPDATE SKIP LOCKED
                      LIMIT 1"
                )->fetch();
                if (!$claim) {
                    $db->commit();
                    break;
                }
                $update = $db->prepare(
                    'UPDATE lw_control.provisioning_jobs
                        SET job_state=\'running\', attempt_count=attempt_count + 1,
                            last_error=NULL, updated_on=now()
                      WHERE id=:id'
                );
                $update->execute(['id' => $claim['job_id']]);
                $db->commit();
            } catch (\Throwable $error) {
                if ($db->inTransaction()) $db->rollBack();
                throw $error;
            }

            try {
                self::finishDeprovision($db, $claim);
                $results[] = [
                    'id' => $claim['tenant_id'],
                    'state' => 'deprovisioned',
                    'schema_name' => $claim['schema_name'],
                ];
            } catch (\Throwable $error) {
                self::markDeprovisionFailed($db, $claim['job_id'], $error->getMessage());
                $results[] = [
                    'id' => $claim['tenant_id'],
                    'state' => 'failed',
                    'error' => $error->getMessage(),
                ];
            }
        }
        return $results;
    }

    private static function deprovisionResponse(array $tenant, array $job): array
    {
        return [
            'id' => $tenant['id'],
            'slug' => $tenant['slug'],
            'display_name' => $tenant['display_name'],
            'schema_name' => $tenant['schema_name'],
            'provisioning_state' => $tenant['provisioning_state'],
            'job_id' => $job['id'],
            'job_state' => $job['job_state'],
            'retention_until' => $tenant['retention_until'],
        ];
    }

    private static function finishDeprovision(PDO $db, array $job): void
    {
        // The review snapshot is created before any schema or tenant storage is
        // removed. A failed snapshot pauses deletion rather than losing songs.
        CatalogReview::snapshotTenantSongs($db, $job['tenant_id'], $job['schema_name'], $job['requested_by']);
        Files::removeTenantStorage($job['tenant_id']);

        $db->beginTransaction();
        try {
            $tenant = $db->prepare(
                'SELECT id, schema_name, provisioning_state, inactivated_on, inactivated_by
                   FROM lw_control.tenants
                  WHERE id=:id
                  FOR UPDATE'
            );
            $tenant->execute(['id' => $job['tenant_id']]);
            $row = $tenant->fetch();
            if (!$row || $row['provisioning_state'] !== 'deprovisioning' || $row['inactivated_on'] !== null || $row['inactivated_by'] !== null) {
                throw new \RuntimeException('Tenant is no longer awaiting deprovisioning.');
            }
            if ($row['schema_name'] !== TenantNames::schemaName($job['tenant_id'])) {
                throw new \RuntimeException('Tenant schema does not match its immutable tenant ID.');
            }

            $db->exec('DROP SCHEMA IF EXISTS ' . self::identifier($row['schema_name']) . ' CASCADE');
            TenantMembershipDirectory::deactivateTenant($db, $job['tenant_id'], $job['requested_by']);
            CatalogReview::markTenantDeprovisioned($db, $job['tenant_id']);
            $db->prepare(
                'UPDATE lw_control.tenant_entitlements
                    SET inactivated_on=COALESCE(inactivated_on, now()),
                        inactivated_by=COALESCE(inactivated_by, :actor)
                  WHERE tenant_id=:tenant_id
                    AND inactivated_on IS NULL AND inactivated_by IS NULL'
            )->execute(['tenant_id' => $job['tenant_id'], 'actor' => $job['requested_by']]);
            $db->prepare(
                'UPDATE lw_control.tenant_subscriptions
                    SET subscription_state=\'canceled\', inactivated_on=now(), inactivated_by=:actor
                  WHERE tenant_id=:tenant_id
                    AND inactivated_on IS NULL AND inactivated_by IS NULL'
            )->execute(['tenant_id' => $job['tenant_id'], 'actor' => $job['requested_by']]);
            $db->prepare(
                'UPDATE lw_control.catalog_review_queue
                    SET inactivated_on=now(), inactivated_by=:actor
                  WHERE tenant_id=:tenant_id
                    AND review_state IN (\'approved\', \'rejected\', \'duplicate\', \'ignored\')
                    AND inactivated_on IS NULL AND inactivated_by IS NULL'
            )->execute(['tenant_id' => $job['tenant_id'], 'actor' => $job['requested_by']]);
            $db->prepare(
                'UPDATE lw_control.provisioning_jobs
                    SET inactivated_on=now(), inactivated_by=:actor, updated_on=now()
                  WHERE tenant_id=:tenant_id AND id<>:job_id
                    AND inactivated_on IS NULL AND inactivated_by IS NULL'
            )->execute([
                'tenant_id' => $job['tenant_id'],
                'job_id' => $job['job_id'],
                'actor' => $job['requested_by'],
            ]);
            $updateTenant = $db->prepare(
                'UPDATE lw_control.tenants
                    SET provisioning_state=\'deprovisioned\', retention_until=NULL,
                        inactivated_on=now(), inactivated_by=:actor, updated_on=now()
                  WHERE id=:id
                    AND provisioning_state=\'deprovisioning\'
                    AND inactivated_on IS NULL AND inactivated_by IS NULL'
                );
            $updateTenant->execute(['id' => $job['tenant_id'], 'actor' => $job['requested_by']]);
            if ($updateTenant->rowCount() !== 1) throw new \RuntimeException('Tenant deprovisioning state changed during deletion.');

            $history = $db->prepare(
                'INSERT INTO lw_control.row_status_history
                    (id, table_name, row_id, action, performed_by, performed_on,
                     reason, activated_on, activated_by, inactivated_on, inactivated_by)
                 VALUES (:id, \'lw_control.tenants\', :row_id, \'inactivated\', :performed_by, now(),
                         \'tenant deprovisioned\', now(), :activated_by, now(), :inactivated_by)'
            );
            $history->execute([
                'id' => TenantNames::uuid(),
                'row_id' => $job['tenant_id'],
                'performed_by' => $job['requested_by'],
                'activated_by' => $job['requested_by'],
                'inactivated_by' => $job['requested_by'],
            ]);
            $audit = $db->prepare(
                'INSERT INTO lw_control.audit_events
                    (id, tenant_id, actor_id, event_type, payload, activated_on, activated_by)
                 VALUES (:id, :tenant_id, :actor_id, \'tenant.deprovisioned\', CAST(:payload AS jsonb), now(), :activated_by)'
            );
            $audit->execute([
                'id' => TenantNames::uuid(),
                'tenant_id' => $job['tenant_id'],
                'actor_id' => $job['requested_by'],
                'payload' => json_encode(['schema_name' => $job['schema_name']], JSON_THROW_ON_ERROR),
                'activated_by' => $job['requested_by'],
            ]);
            $complete = $db->prepare(
                'UPDATE lw_control.provisioning_jobs
                    SET job_state=\'complete\', last_error=NULL, updated_on=now()
                  WHERE id=:id AND operation=\'deprovision\''
            );
            $complete->execute(['id' => $job['job_id']]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    private static function markDeprovisionFailed(PDO $db, string $jobId, string $message): void
    {
        try {
            $job = $db->prepare(
                'UPDATE lw_control.provisioning_jobs
                    SET job_state=\'failed\', last_error=:last_error, updated_on=now()
                  WHERE id=:id AND operation=\'deprovision\'
                    AND inactivated_on IS NULL AND inactivated_by IS NULL'
            );
            $job->execute(['id' => $jobId, 'last_error' => mb_substr($message, 0, 4000)]);
        } catch (\Throwable) {
            // Leave the tenant in deprovisioning for support inspection if the
            // failure record itself cannot be written.
        }
    }

    private static function finishExisting(PDO $db, array $tenant, string $displayName, string $ownerActorId): void
    {
        self::startProvisioningJob($db, $tenant['id'], $ownerActorId);
        PlatformMigrations::applyTenant($db, $tenant['schema_name']);
        $db->beginTransaction();
        try {
            $settings = $db->prepare(
                'INSERT INTO ' . self::identifier($tenant['schema_name']) . '.settings
                    (id, display_name, activated_on, activated_by)
                 VALUES (true, :display_name, now(), :activated_by)
                 ON CONFLICT (id) DO UPDATE
                    SET display_name=EXCLUDED.display_name, updated_on=now(),
                        activated_on=now(), activated_by=EXCLUDED.activated_by,
                        inactivated_on=NULL, inactivated_by=NULL'
            );
            $settings->execute(['display_name' => $displayName, 'activated_by' => $ownerActorId]);

            $member = $db->prepare(
                'INSERT INTO ' . self::identifier($tenant['schema_name']) . '.members
                    (id, actor_id, role, activated_on, activated_by)
                 SELECT :id, :actor_id, \'owner\', now(), :activated_by
                  WHERE NOT EXISTS (
                      SELECT 1 FROM ' . self::identifier($tenant['schema_name']) . '.members
                       WHERE actor_id = :actor_id_check
                         AND inactivated_on IS NULL AND inactivated_by IS NULL
                  )'
            );
            $member->execute([
                'id' => TenantNames::uuid(),
                'actor_id' => $ownerActorId,
                'actor_id_check' => $ownerActorId,
                'activated_by' => $ownerActorId,
            ]);
            $ownerMember = $db->prepare(
                'SELECT id, role
                   FROM ' . self::identifier($tenant['schema_name']) . '.members
                  WHERE actor_id=:actor_id
                    AND inactivated_on IS NULL AND inactivated_by IS NULL
                  LIMIT 1'
            );
            $ownerMember->execute(['actor_id' => $ownerActorId]);
            $ownerMemberRow = $ownerMember->fetch();
            if (!$ownerMemberRow) throw new \RuntimeException('Tenant owner membership could not be established.');
            TenantMembershipDirectory::sync($db, (string) $tenant['id'], $ownerActorId, (string) $ownerMemberRow['id'], (string) $ownerMemberRow['role'], $ownerActorId);

            $subscription = $db->prepare(
                'INSERT INTO lw_control.tenant_subscriptions
                    (id, tenant_id, plan_id, subscription_state, activated_on, activated_by)
                 SELECT :id, :tenant_id, p.id, \'free\', now(), :activated_by
                   FROM lw_control.plans p
                  WHERE p.code = \'live-worship\'
                    AND NOT EXISTS (
                        SELECT 1 FROM lw_control.tenant_subscriptions ts
                         WHERE ts.tenant_id = :tenant_id_check
                           AND ts.inactivated_on IS NULL AND ts.inactivated_by IS NULL
                    )'
            );
            $subscription->execute([
                'id' => TenantNames::uuid(),
                'tenant_id' => $tenant['id'],
                'tenant_id_check' => $tenant['id'],
                'activated_by' => $ownerActorId,
            ]);

            $currentSubscription = $db->prepare(
                'SELECT id, plan_id
                   FROM lw_control.tenant_subscriptions
                  WHERE tenant_id=:tenant_id
                    AND inactivated_on IS NULL AND inactivated_by IS NULL
                  ORDER BY activated_on DESC, id DESC
                  LIMIT 1'
            );
            $currentSubscription->execute(['tenant_id' => $tenant['id']]);
            $subscriptionRow = $currentSubscription->fetch();
            if (!$subscriptionRow) throw new \RuntimeException('Tenant subscription could not be established.');
            Entitlements::seedPlan($db, (string) $tenant['id'], (string) $subscriptionRow['id'], (string) $subscriptionRow['plan_id'], $ownerActorId);

            $update = $db->prepare(
                'UPDATE lw_control.tenants
                    SET display_name = :display_name, provisioning_state = \'active\', updated_on = now()
                  WHERE id = :id
                    AND provisioning_state IN (\'provisioning\', \'failed\')
                    AND inactivated_on IS NULL AND inactivated_by IS NULL'
            );
            $update->execute(['display_name' => $displayName, 'id' => $tenant['id']]);
            if ($update->rowCount() !== 1) throw new \RuntimeException('Tenant provisioning was superseded or is no longer active.');
            $job = $db->prepare(
                'UPDATE lw_control.provisioning_jobs
                    SET job_state=\'complete\', last_error=NULL, updated_on=now()
                 WHERE tenant_id=:tenant_id
                    AND operation=\'provision\'
                    AND inactivated_on IS NULL AND inactivated_by IS NULL
                    AND job_state IN (\'queued\', \'running\', \'failed\')'
            );
            $job->execute(['tenant_id' => $tenant['id']]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    private static function startProvisioningJob(PDO $db, string $tenantId, string $actorId): void
    {
            $existing = $db->prepare(
                'SELECT id FROM lw_control.provisioning_jobs
              WHERE tenant_id=:tenant_id
                AND operation=\'provision\'
                AND inactivated_on IS NULL AND inactivated_by IS NULL
              ORDER BY activated_on DESC LIMIT 1'
        );
        $existing->execute(['tenant_id' => $tenantId]);
        $jobId = $existing->fetchColumn();
        if ($jobId !== false) {
            $update = $db->prepare(
                'UPDATE lw_control.provisioning_jobs
                    SET job_state=\'running\',
                        attempt_count=attempt_count + CASE WHEN job_state = \'running\' THEN 0 ELSE 1 END,
                        last_error=NULL, updated_on=now()
                  WHERE id=:id'
            );
            $update->execute(['id' => $jobId]);
            return;
        }
        $insert = $db->prepare(
            'INSERT INTO lw_control.provisioning_jobs
                (id, tenant_id, operation, job_state, attempt_count, activated_on, activated_by, updated_on)
             VALUES (:id, :tenant_id, \'provision\', \'running\', 1, now(), :activated_by, now())'
        );
        $insert->execute([
            'id' => TenantNames::uuid(),
            'tenant_id' => $tenantId,
            'activated_by' => $actorId,
        ]);
    }

    private static function markProvisioningFailed(PDO $db, string $tenantId, string $message): void
    {
        try {
            $tenant = $db->prepare(
                'UPDATE lw_control.tenants
                    SET provisioning_state=\'failed\', updated_on=now()
                  WHERE id=:id
                    AND provisioning_state IN (\'provisioning\', \'failed\')
                    AND inactivated_on IS NULL AND inactivated_by IS NULL'
            );
            $tenant->execute(['id' => $tenantId]);
            $job = $db->prepare(
                'UPDATE lw_control.provisioning_jobs
                    SET job_state=\'failed\', last_error=:last_error, updated_on=now()
                  WHERE tenant_id=:tenant_id
                    AND operation=\'provision\'
                    AND inactivated_on IS NULL AND inactivated_by IS NULL
                    AND job_state=\'running\''
            );
            $job->execute(['tenant_id' => $tenantId, 'last_error' => mb_substr($message, 0, 4000)]);
        } catch (\Throwable) {
            // Preserve the original provisioning error; support can inspect the
            // tenant row and retry from the CLI if failure recording also fails.
        }
    }

    private static function identifier(string $schemaName): string
    {
        if (!preg_match('/^lw_t_[0-9a-f]+$/', $schemaName)) throw new \InvalidArgumentException('Invalid tenant schema identifier.');
        return '"' . $schemaName . '"';
    }
}
