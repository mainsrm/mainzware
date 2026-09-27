<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;
use Throwable;

/** Central MainzWare-admin support surface for Live Worship. */
final class AdminApi
{
    private function __construct(private PDO $db, private array $admin, private string $actorId)
    {
    }

    public static function dispatch(PDO $db, string $method, string $path): void
    {
        $admin = \MainzWorld\Support\Auth::requireAdmin();
        if ($admin === null) return;
        $actorId = ActorIdentity::ensureMainzWareActor($db, $admin);
        (new self($db, $admin, $actorId))->route($method, $path);
    }

    private function route(string $method, string $path): void
    {
        $path = rtrim(preg_replace('#^/api/v1/live-worship#', '', parse_url($path, PHP_URL_PATH) ?: '/'), '/') ?: '/';
        if ($method === 'GET' && $path === '/admin/overview') { $this->overview(); return; }
        if ($method === 'GET' && $path === '/admin/tenants') { $this->tenants(); return; }
        if ($method === 'GET' && preg_match('#^/admin/tenants/([0-9a-f-]{36})$#i', $path, $m)) { $this->tenant(strtolower($m[1])); return; }
        if ($method === 'POST' && $path === '/admin/support-sessions') { $this->startSupportSession(); return; }
        if ($method === 'GET' && $path === '/admin/support-sessions/current') { $this->currentSupportSession(); return; }
        if ($method === 'DELETE' && preg_match('#^/admin/support-sessions/([0-9a-f-]{36})$#i', $path, $m)) { $this->endSupportSession(strtolower($m[1])); return; }
        if ($method === 'GET' && $path === '/admin/login-activity') { $this->loginActivity(); return; }
        if ($method === 'GET' && $path === '/admin/catalog-review') { $this->catalogReview(); return; }
        if ($method === 'POST' && preg_match('#^/admin/catalog-review/([0-9a-f-]{36})/(approve|reject|duplicate)$#i', $path, $m)) {
            $this->reviewAction(strtolower($m[1]), $m[2]);
            return;
        }
        http_response_code(404);
        echo json_encode(['error' => 'Live Worship admin endpoint not found.']);
    }

    private function startSupportSession(): void
    {
        $body = $this->body();
        try {
            $session = SupportSessions::start(
                $this->db,
                $this->actorId,
                $this->uuid((string) ($body['tenant_id'] ?? '')),
                (int) ($body['duration_minutes'] ?? 15),
                (string) ($body['reason'] ?? '')
            );
        } catch (SupportSessionError $error) {
            throw new ApiError($error->status, $error->getMessage());
        }
        $_SESSION['live_worship_support_session_id'] = $session['id'];
        $_SESSION['live_worship_tenant_id'] = $session['tenant_id'];
        $this->respond($session, 201);
    }

    private function currentSupportSession(): void
    {
        try {
            $session = SupportSessions::current($this->db, $this->actorId);
        } catch (SupportSessionError $error) {
            throw new ApiError($error->status, $error->getMessage());
        }
        $this->respond($session);
    }

    private function loginActivity(): void
    {
        $tenantId = trim((string) ($_GET['tenant_id'] ?? '')) ?: null;
        $outcome = trim((string) ($_GET['outcome'] ?? '')) ?: null;
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));
        try {
            $rows = LoginTracking::recent($this->db, $tenantId, $outcome, $limit);
        } catch (\InvalidArgumentException $error) {
            throw new ApiError(400, $error->getMessage());
        }
        $this->respond($rows);
    }

    private function endSupportSession(string $sessionId): void
    {
        try {
            SupportSessions::end($this->db, $this->actorId, $this->uuid($sessionId));
        } catch (SupportSessionError $error) {
            throw new ApiError($error->status, $error->getMessage());
        }
        $this->respond(['ok' => true]);
    }

    private function overview(): void
    {
        $counts = $this->db->query(
            "SELECT
                count(*) FILTER (WHERE provisioning_state='active' AND inactivated_on IS NULL AND inactivated_by IS NULL) AS active_tenants,
                count(*) FILTER (WHERE provisioning_state='provisioning' AND inactivated_on IS NULL AND inactivated_by IS NULL) AS provisioning_tenants,
                count(*) FILTER (WHERE provisioning_state='failed' AND inactivated_on IS NULL AND inactivated_by IS NULL) AS failed_tenants,
                count(*) FILTER (WHERE provisioning_state='deprovisioning' AND inactivated_on IS NULL AND inactivated_by IS NULL) AS deprovisioning_tenants,
                count(*) FILTER (WHERE provisioning_state='suspended' AND inactivated_on IS NULL AND inactivated_by IS NULL) AS suspended_tenants
             FROM lw_control.tenants"
        )->fetch() ?: [];
        $pending = (int) $this->db->query("SELECT count(*) FROM lw_control.catalog_review_queue WHERE review_state='pending' AND inactivated_on IS NULL AND inactivated_by IS NULL")->fetchColumn();
        $jobs = $this->db->query(
            "SELECT
                count(*) FILTER (WHERE operation='provision' AND job_state IN ('queued','running') AND inactivated_on IS NULL AND inactivated_by IS NULL) AS provisioning_jobs,
                count(*) FILTER (WHERE operation='deprovision' AND job_state IN ('queued','running') AND inactivated_on IS NULL AND inactivated_by IS NULL) AS deprovisioning_jobs,
                count(*) FILTER (WHERE job_state='failed' AND inactivated_on IS NULL AND inactivated_by IS NULL) AS failed_jobs
             FROM lw_control.provisioning_jobs"
        )->fetch() ?: [];
        $plans = $this->db->query(
            "SELECT p.id, p.code, p.display_name, count(ts.id) FILTER (WHERE ts.inactivated_on IS NULL AND ts.inactivated_by IS NULL) AS tenant_count
               FROM lw_control.plans p
               LEFT JOIN lw_control.tenant_subscriptions ts ON ts.plan_id=p.id
                AND ts.inactivated_on IS NULL AND ts.inactivated_by IS NULL
              WHERE p.inactivated_on IS NULL AND p.inactivated_by IS NULL
               GROUP BY p.id, p.code, p.display_name
               ORDER BY p.code"
        )->fetchAll();
        $planFeatures = $this->db->query(
            "SELECT p.code AS plan_code, f.code, f.display_name
               FROM lw_control.plan_features pf
               JOIN lw_control.plans p ON p.id=pf.plan_id
               JOIN lw_control.features f ON f.id=pf.feature_id
              WHERE pf.inactivated_on IS NULL AND pf.inactivated_by IS NULL
                AND p.inactivated_on IS NULL AND p.inactivated_by IS NULL
                AND f.inactivated_on IS NULL AND f.inactivated_by IS NULL
              ORDER BY p.code, f.code"
        )->fetchAll();
        $featuresByPlan = [];
        foreach ($planFeatures as $feature) {
            $featuresByPlan[(string) $feature['plan_code']][] = [
                'code' => (string) $feature['code'],
                'display_name' => (string) $feature['display_name'],
            ];
        }
        $this->respond([
            'tenant_counts' => $this->integers($counts),
            'job_counts' => $this->integers($jobs),
            'pending_catalog_review_count' => $pending,
            'plans' => array_map(static function (array $row): array {
                $row['id'] = (string) $row['id'];
                $row['tenant_count'] = (int) $row['tenant_count'];
                return $row;
            }, $plans),
            'plan_features' => $featuresByPlan,
        ]);
    }

    private function tenants(): void
    {
        $state = trim((string) ($_GET['state'] ?? ''));
        $allowed = ['provisioning', 'active', 'suspended', 'failed', 'deprovisioning', 'deprovisioned'];
        if ($state !== '' && !in_array($state, $allowed, true)) throw new ApiError(400, 'Invalid tenant state filter.');
        $sql = 'SELECT id, slug, display_name, schema_name, storage_prefix, provisioning_state, activated_on, updated_on, retention_until, deprovision_reason, inactivated_on, inactivated_by FROM lw_control.tenants';
        $params = [];
        if ($state !== '') { $sql .= ' WHERE provisioning_state=:state'; $params['state'] = $state; }
        $sql .= ' ORDER BY updated_on DESC, id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $tenant) $rows[] = $this->tenantSummary($tenant);
        $this->respond($rows);
    }

    private function tenant(string $tenantId): void
    {
        $tenant = $this->tenantRow($tenantId);
        $summary = $this->tenantSummary($tenant);
        $schema = $this->schema($tenantId);
        if ($this->schemaExists($schema)) {
            $members = $this->db->query(
                "SELECT m.id, m.actor_id, a.display_name, a.external_subject, m.role,
                        (m.inactivated_on IS NULL AND m.inactivated_by IS NULL) AS active,
                        m.activated_on, m.inactivated_on, m.inactivated_by
                   FROM {$schema}.members m
                   LEFT JOIN lw_control.actors a ON a.id=m.actor_id
                  ORDER BY (m.inactivated_on IS NULL AND m.inactivated_by IS NULL) DESC, a.display_name, m.id"
            )->fetchAll();
        } else {
            $members = [];
        }
        foreach ($members as &$member) $member['active'] = $this->truthy($member['active']);

        $effective = Entitlements::resolve($this->db, $tenantId);
        $effectiveByCode = [];
        foreach ($effective['feature_details'] as $detail) $effectiveByCode[(string) $detail['code']] = $detail;
        $features = $this->db->prepare(
            'SELECT f.code, f.display_name, te.source, te.expires_on,
                    te.source_plan_id, te.source_subscription_id,
                    te.inactivated_on, te.inactivated_by
               FROM lw_control.features f
               LEFT JOIN lw_control.tenant_entitlements te
                 ON te.feature_id=f.id
                AND te.tenant_id=:tenant_id
                AND te.inactivated_on IS NULL AND te.inactivated_by IS NULL
              WHERE f.inactivated_on IS NULL AND f.inactivated_by IS NULL
              ORDER BY f.code'
        );
        $features->execute(['tenant_id' => $tenantId]);
        $featureRows = $features->fetchAll();
        foreach ($featureRows as &$feature) {
            $code = (string) $feature['code'];
            $feature['entitled'] = isset($effectiveByCode[$code]);
            $feature['effective_source'] = $effectiveByCode[$code]['source'] ?? null;
            $feature['effective_expires_on'] = $effectiveByCode[$code]['expires_on'] ?? null;
        }
        unset($feature);

        $review = $this->db->prepare(
            "SELECT id, tenant_song_id, title, writer, review_state, source_state,
                    submitted_on, submitted_by, reviewed_on, reviewed_by, master_song_id,
                    review_note
               FROM lw_control.catalog_review_queue
              WHERE tenant_id=:tenant_id
                AND inactivated_on IS NULL AND inactivated_by IS NULL
              ORDER BY submitted_on DESC, id"
        );
        $review->execute(['tenant_id' => $tenantId]);

        $this->respond($summary + [
            'members' => $members,
            'features' => $featureRows,
            'catalog_reviews' => $review->fetchAll(),
        ]);
    }

    private function catalogReview(): void
    {
        $state = trim((string) ($_GET['review_state'] ?? 'pending'));
        $allowed = ['pending', 'approved', 'rejected', 'duplicate', 'ignored', 'all'];
        if (!in_array($state, $allowed, true)) throw new ApiError(400, 'Invalid catalog review state filter.');
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
        $sql = "SELECT q.id, q.tenant_id, t.slug, t.display_name AS tenant_name,
                       q.tenant_song_id, q.title, q.writer, q.content_hash,
                       q.content_snapshot, q.review_state, q.reviewed_by, q.reviewed_on,
                       q.submitted_by, q.submitted_on, q.source_state, q.source_deleted_on,
                       q.master_song_id, q.review_note
                  FROM lw_control.catalog_review_queue q
                  JOIN lw_control.tenants t ON t.id=q.tenant_id";
        $params = ['limit' => $limit];
        if ($state !== 'all') { $sql .= ' WHERE q.review_state=:review_state'; $params['review_state'] = $state; }
        else $sql .= ' WHERE 1=1';
        $sql .= ' ORDER BY CASE WHEN q.review_state=\'pending\' THEN 0 ELSE 1 END, q.submitted_on DESC, q.id LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        if ($state !== 'all') $stmt->bindValue(':review_state', $state);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['content_snapshot'] = $this->jsonObject($row['content_snapshot']);
        }
        $this->respond($rows);
    }

    private function reviewAction(string $reviewId, string $action): void
    {
        $reviewId = $this->uuid($reviewId);
        $body = $this->body();
        $note = trim((string) ($body['note'] ?? ''));
        if (mb_strlen($note) > 2000) throw new ApiError(400, 'Review notes must be 2000 characters or fewer.');
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT id, tenant_id, tenant_song_id, title, writer, content_hash,
                        content_snapshot, review_state, source_state
                   FROM lw_control.catalog_review_queue
                  WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL
                  FOR UPDATE'
            );
            $stmt->execute(['id' => $reviewId]);
            $review = $stmt->fetch();
            if (!$review) throw new ApiError(404, 'Catalog review item not found.');
            if ($review['review_state'] !== 'pending') throw new ApiError(409, 'This catalog review item has already been decided.');
            $snapshot = $this->jsonObject($review['content_snapshot']);
            if ($action === 'approve') {
                $masterId = $this->approveSnapshot($review, $snapshot, $note);
                $state = 'approved';
                $note = $note !== '' ? $note : 'Approved into the Live Worship master catalog.';
            } elseif ($action === 'duplicate') {
                $masterId = $this->duplicateMasterId($review, $body);
                $state = 'duplicate';
                $note = $note !== '' ? $note : 'Matched to an existing master catalog song.';
            } else {
                $masterId = null;
                $state = 'rejected';
                $note = $note !== '' ? $note : 'Rejected by Live Worship administrator.';
            }
            $update = $this->db->prepare(
                "UPDATE lw_control.catalog_review_queue
                    SET review_state=:state, master_song_id=:master_song_id,
                        review_note=:note, reviewed_by=:reviewed_by, reviewed_on=now()
                  WHERE id=:id"
            );
            $update->execute(['state' => $state, 'master_song_id' => $masterId, 'note' => $note, 'reviewed_by' => $this->actorId, 'id' => $reviewId]);
            $this->audit($review, 'song.review_' . $state, ['review_id' => $reviewId, 'master_song_id' => $masterId, 'note' => $note]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->respond(['id' => $reviewId, 'review_state' => $state, 'master_song_id' => $masterId, 'review_note' => $note]);
    }

    private function approveSnapshot(array $review, array $snapshot, string $note): string
    {
        $title = trim((string) ($snapshot['title'] ?? $review['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) throw new ApiError(422, 'The review snapshot has no valid song title.');
        $writer = trim((string) ($snapshot['writer'] ?? $review['writer'] ?? '')) ?: null;
        $sections = $snapshot['sections'] ?? [];
        if (is_string($sections)) $sections = json_decode($sections, true) ?: [];
        if (!is_array($sections) || $sections === []) throw new ApiError(422, 'The review snapshot has no structured song sections.');
        $existing = $this->db->prepare(
            "SELECT id FROM lw_master.songs
              WHERE lower(title)=lower(:title)
                AND lower(coalesce(writer, ''))=lower(coalesce(:writer, ''))
                AND inactivated_on IS NULL AND inactivated_by IS NULL
              LIMIT 1"
        );
        $existing->execute(['title' => $title, 'writer' => $writer]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) return (string) $existingId;

        $masterId = TenantNames::uuid();
        $versionId = TenantNames::uuid();
        $defaultKey = trim((string) ($snapshot['default_key'] ?? '')) ?: null;
        $originalKey = trim((string) ($snapshot['original_key'] ?? '')) ?: $defaultKey;
        $lyrics = (string) ($snapshot['lyrics'] ?? '');
        $hash = trim((string) ($snapshot['source_content_hash'] ?? $review['content_hash'] ?? '')) ?: hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $insertSong = $this->db->prepare(
            "INSERT INTO lw_master.songs
                (id, title, writer, original_key, source_state, activated_on, activated_by)
             VALUES (:id, :title, :writer, :original_key, 'curated', now(), :by)"
        );
        $insertSong->execute(['id' => $masterId, 'title' => $title, 'writer' => $writer, 'original_key' => $originalKey, 'by' => $this->actorId]);
        $insertVersion = $this->db->prepare(
            "INSERT INTO lw_master.song_versions
                (id, song_id, source_key, lyrics, sections, content_hash, source_version,
                 created_by, activated_on, activated_by)
             VALUES (:id, :song_id, :source_key, :lyrics, CAST(:sections AS jsonb), :hash,
                     :source_version, :by, now(), :by)"
        );
        $insertVersion->execute([
            'id' => $versionId,
            'song_id' => $masterId,
            'source_key' => $defaultKey,
            'lyrics' => $lyrics,
            'sections' => json_encode($sections, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'hash' => $hash,
            'source_version' => 'tenant-review:' . $review['tenant_id'] . ':' . $review['tenant_song_id'],
            'by' => $this->actorId,
        ]);
        $this->db->prepare('UPDATE lw_master.songs SET current_version_id=:version_id, updated_on=now() WHERE id=:id')->execute(['version_id' => $versionId, 'id' => $masterId]);
        $source = $this->db->prepare(
            "INSERT INTO lw_master.song_sources
                (id, song_id, source_path, source_sha256, source_type, activated_on, activated_by)
             VALUES (:id, :song_id, :source_path, :hash, 'tenant-review', now(), :by)"
        );
        $source->execute(['id' => TenantNames::uuid(), 'song_id' => $masterId, 'source_path' => 'tenant:' . $review['tenant_id'] . '/song:' . $review['tenant_song_id'], 'hash' => $hash, 'by' => $this->actorId]);
        return $masterId;
    }

    private function duplicateMasterId(array $review, array $body): string
    {
        $requested = trim((string) ($body['master_song_id'] ?? ''));
        if ($requested !== '') {
            $requested = $this->uuid($requested);
            $check = $this->db->prepare('SELECT id FROM lw_master.songs WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL');
            $check->execute(['id' => $requested]);
            if ($check->fetchColumn() === false) throw new ApiError(404, 'The selected master catalog song was not found.');
            return $requested;
        }
        $find = $this->db->prepare(
            "SELECT id FROM lw_master.songs
              WHERE lower(title)=lower(:title)
                AND lower(coalesce(writer, ''))=lower(coalesce(:writer, ''))
                AND inactivated_on IS NULL AND inactivated_by IS NULL
              LIMIT 1"
        );
        $find->execute(['title' => $review['title'], 'writer' => $review['writer']]);
        $masterId = $find->fetchColumn();
        if ($masterId === false) throw new ApiError(422, 'Select the matching master song before marking this item as a duplicate.');
        return (string) $masterId;
    }

    private function tenantSummary(array $tenant): array
    {
        $tenantId = (string) $tenant['id'];
        $schema = $this->schema($tenantId);
        $subscription = $this->db->prepare(
            'SELECT p.code AS plan_code, p.display_name AS plan_name, ts.subscription_state, ts.expires_on
               FROM lw_control.tenant_subscriptions ts
               JOIN lw_control.plans p ON p.id=ts.plan_id
              WHERE ts.tenant_id=:tenant_id
                AND ts.inactivated_on IS NULL AND ts.inactivated_by IS NULL
                AND p.inactivated_on IS NULL AND p.inactivated_by IS NULL
              ORDER BY ts.activated_on DESC LIMIT 1'
        );
        $subscription->execute(['tenant_id' => $tenantId]);
        $plan = $subscription->fetch() ?: null;
        $members = ['active' => 0, 'total' => 0];
        if ($this->schemaExists($schema)) {
            $memberCounts = $this->db->query("SELECT count(*) FILTER (WHERE inactivated_on IS NULL AND inactivated_by IS NULL) AS active, count(*) AS total FROM {$schema}.members")->fetch() ?: [];
            $members = ['active' => (int) ($memberCounts['active'] ?? 0), 'total' => (int) ($memberCounts['total'] ?? 0)];
        }
        $pending = $this->db->prepare("SELECT count(*) FROM lw_control.catalog_review_queue WHERE tenant_id=:tenant_id AND review_state='pending' AND inactivated_on IS NULL AND inactivated_by IS NULL");
        $pending->execute(['tenant_id' => $tenantId]);
        $job = $this->db->prepare(
            'SELECT operation, job_state, attempt_count, last_error, updated_on
               FROM lw_control.provisioning_jobs
              WHERE tenant_id=:tenant_id AND inactivated_on IS NULL AND inactivated_by IS NULL
              ORDER BY updated_on DESC LIMIT 1'
        );
        $job->execute(['tenant_id' => $tenantId]);
        return [
            'id' => $tenantId,
            'slug' => (string) $tenant['slug'],
            'display_name' => (string) $tenant['display_name'],
            'schema_name' => (string) $tenant['schema_name'],
            'storage_prefix' => (string) $tenant['storage_prefix'],
            'provisioning_state' => (string) $tenant['provisioning_state'],
            'active' => $tenant['inactivated_on'] === null && $tenant['inactivated_by'] === null,
            'activated_on' => $tenant['activated_on'],
            'updated_on' => $tenant['updated_on'],
            'retention_until' => $tenant['retention_until'],
            'deprovision_reason' => $tenant['deprovision_reason'],
            'subscription' => $plan ? ['plan_code' => $plan['plan_code'], 'plan_name' => $plan['plan_name'], 'subscription_state' => $plan['subscription_state'], 'expires_on' => $plan['expires_on']] : null,
            'members' => $members,
            'pending_catalog_reviews' => (int) $pending->fetchColumn(),
            'last_job' => $job->fetch() ?: null,
        ];
    }

    private function tenantRow(string $tenantId): array
    {
        $tenantId = $this->uuid($tenantId);
        $stmt = $this->db->prepare('SELECT id, slug, display_name, schema_name, storage_prefix, provisioning_state, activated_on, updated_on, retention_until, deprovision_reason, inactivated_on, inactivated_by FROM lw_control.tenants WHERE id=:id');
        $stmt->execute(['id' => $tenantId]);
        $tenant = $stmt->fetch();
        if (!$tenant) throw new ApiError(404, 'Tenant not found.');
        return $tenant;
    }

    private function audit(array $review, string $eventType, array $payload): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO lw_control.audit_events
                (id, tenant_id, actor_id, event_type, payload, activated_on, activated_by)
             VALUES (:id, :tenant_id, :actor_id, :event_type, CAST(:payload AS jsonb), now(), :activated_by)"
        );
        $stmt->execute(['id' => TenantNames::uuid(), 'tenant_id' => $review['tenant_id'], 'actor_id' => $this->actorId, 'event_type' => $eventType, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'activated_by' => $this->actorId]);
    }

    private function schema(string $tenantId): string
    {
        $schema = TenantNames::schemaName($this->uuid($tenantId));
        return '"' . $schema . '"';
    }

    private function schemaExists(string $schema): bool
    {
        $stmt = $this->db->prepare('SELECT to_regclass(:table_name)');
        $stmt->execute(['table_name' => trim($schema, '"') . '.members']);
        return $stmt->fetchColumn() !== null;
    }

    private function uuid(string $value): string
    {
        $value = strtolower(trim($value));
        if (!TenantNames::validUuid($value)) throw new ApiError(400, 'The requested Live Worship record ID is invalid.');
        return $value;
    }

    private function body(): array
    {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($body)) throw new ApiError(400, 'Request body must be a JSON object.');
        return $body;
    }

    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function integers(array $row): array
    {
        foreach ($row as $key => $value) $row[$key] = (int) $value;
        return $row;
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 't', '1', 1], true);
    }

    private function respond(mixed $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
