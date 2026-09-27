<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;

final class CatalogReview
{
    public static function snapshotTenantSongs(PDO $db, string $tenantId, string $schemaName, string $actorId): int
    {
        if (!TenantNames::validUuid($tenantId) || !TenantNames::validUuid($actorId)) {
            throw new \InvalidArgumentException('Tenant and actor IDs must be UUIDs.');
        }
        $expectedSchema = TenantNames::schemaName($tenantId);
        if ($schemaName !== $expectedSchema) throw new \InvalidArgumentException('Tenant schema does not match its immutable tenant ID.');
        $schema = self::identifier($schemaName);
        $tableExists = $db->query("SELECT to_regclass('{$schema}.library_songs')")->fetchColumn();

        if ($tableExists !== null) {
            $songs = $db->query(
                "SELECT id, title, writer, original_key, default_key, lyrics, sections,
                        ocr_text, origin, source_content_hash, created_by, activated_on,
                        inactivated_on, inactivated_by, updated_on
                   FROM {$schema}.library_songs
                  WHERE origin IN ('tenant_created', 'tenant_imported_external')
                    AND master_song_id IS NULL"
            )->fetchAll();
            foreach ($songs as $song) self::upsertSnapshot($db, $tenantId, $song, $actorId);
        }

        $pending = $db->prepare(
            'SELECT id, content_snapshot
               FROM lw_control.catalog_review_queue
              WHERE tenant_id=:tenant_id
                AND review_state=\'pending\'
                AND inactivated_on IS NULL AND inactivated_by IS NULL'
        );
        $pending->execute(['tenant_id' => $tenantId]);
        foreach ($pending->fetchAll() as $row) {
            $snapshot = json_decode((string) $row['content_snapshot'], true);
            if (!is_array($snapshot) || $snapshot === []) {
                throw new \RuntimeException('A pending tenant song has no durable review snapshot; deprovisioning is paused for support review.');
            }
        }

        $mark = $db->prepare(
            'UPDATE lw_control.catalog_review_queue
                SET source_state=\'tenant_deprovisioning\', source_deleted_on=NULL
              WHERE tenant_id=:tenant_id
                AND review_state=\'pending\'
                AND inactivated_on IS NULL AND inactivated_by IS NULL'
        );
        $mark->execute(['tenant_id' => $tenantId]);
        return $mark->rowCount();
    }

    public static function markTenantDeprovisioned(PDO $db, string $tenantId): void
    {
        $stmt = $db->prepare(
            'UPDATE lw_control.catalog_review_queue
                SET source_state=\'tenant_deprovisioned\', source_deleted_on=now()
              WHERE tenant_id=:tenant_id
                AND review_state=\'pending\'
                AND inactivated_on IS NULL AND inactivated_by IS NULL'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
    }

    private static function upsertSnapshot(PDO $db, string $tenantId, array $song, string $actorId): void
    {
        $songId = (string) $song['id'];
        if (!TenantNames::validUuid($songId)) throw new \RuntimeException('A tenant song has an invalid UUID.');
        $snapshot = [
            'title' => (string) $song['title'],
            'writer' => $song['writer'],
            'original_key' => $song['original_key'],
            'default_key' => $song['default_key'],
            'lyrics' => (string) $song['lyrics'],
            'sections' => is_string($song['sections']) ? json_decode($song['sections'], true, 512, JSON_THROW_ON_ERROR) : $song['sections'],
            'ocr_text' => (string) $song['ocr_text'],
            'origin' => (string) $song['origin'],
            'source_content_hash' => $song['source_content_hash'],
            'created_by' => $song['created_by'],
            'activated_on' => $song['activated_on'],
            'inactivated_on' => $song['inactivated_on'],
            'inactivated_by' => $song['inactivated_by'],
            'updated_on' => $song['updated_on'],
        ];
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $hash = trim((string) ($song['source_content_hash'] ?? '')) ?: hash('sha256', $encoded);
        $existing = $db->prepare(
            'SELECT id, review_state
               FROM lw_control.catalog_review_queue
              WHERE tenant_id=:tenant_id AND tenant_song_id=:tenant_song_id
                AND inactivated_on IS NULL AND inactivated_by IS NULL'
        );
        $existing->execute(['tenant_id' => $tenantId, 'tenant_song_id' => $songId]);
        $row = $existing->fetch();
        if (!$row) {
            $insert = $db->prepare(
                'INSERT INTO lw_control.catalog_review_queue
                    (id, tenant_id, tenant_song_id, title, writer, content_hash,
                     content_snapshot, review_state, submitted_on, submitted_by,
                     source_state, activated_on, activated_by)
                 VALUES (:id, :tenant_id, :tenant_song_id, :title, :writer, :content_hash,
                         CAST(:content_snapshot AS jsonb), \'pending\', now(), :submitted_by,
                         \'tenant_deprovisioning\', now(), :activated_by)'
            );
            $insert->execute([
                'id' => TenantNames::uuid(),
                'tenant_id' => $tenantId,
                'tenant_song_id' => $songId,
                'title' => $snapshot['title'],
                'writer' => $snapshot['writer'],
                'content_hash' => $hash,
                'content_snapshot' => $encoded,
                'submitted_by' => $snapshot['created_by'] ?: null,
                'activated_by' => $actorId,
            ]);
            return;
        }
        if ($row['review_state'] !== 'pending') return;
        $update = $db->prepare(
            'UPDATE lw_control.catalog_review_queue
                SET title=:title, writer=:writer, content_hash=:content_hash,
                    content_snapshot=CAST(:content_snapshot AS jsonb),
                    source_state=\'tenant_deprovisioning\', submitted_by=COALESCE(submitted_by, :submitted_by)
              WHERE id=:id'
        );
        $update->execute([
            'title' => $snapshot['title'],
            'writer' => $snapshot['writer'],
            'content_hash' => $hash,
            'content_snapshot' => $encoded,
            'submitted_by' => $snapshot['created_by'] ?: null,
            'id' => $row['id'],
        ]);
    }

    private static function identifier(string $schemaName): string
    {
        if (!preg_match('/^lw_t_[0-9a-f]+$/', $schemaName)) throw new \InvalidArgumentException('Invalid tenant schema identifier.');
        return '"' . $schemaName . '"';
    }
}
