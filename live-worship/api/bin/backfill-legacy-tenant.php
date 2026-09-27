<?php
declare(strict_types=1);

/**
 * Backfill the original single-instance Live Worship data into one UUID tenant.
 *
 * This is intentionally an explicit migration command, not part of ordinary
 * provisioning. It copies legacy data and leaves the source schema untouched
 * so the local development cutover is recoverable and repeatable.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;
use LiveWorship\TenantMembershipDirectory;
use LiveWorship\TenantNames;
use LiveWorship\TenantProvisioner;

$options = getopt('', ['owner-actor-id::', 'name::']);
$migrator = Database::migratorConnection();
$systemActor = '00000000-0000-0000-0000-000000000001';
$legacySettings = $migrator->query('SELECT display_name, logo_path, logo_mime_type, logo_file_size_bytes, updated_at FROM live_worship.settings WHERE id=true')->fetch();
$displayName = trim((string) ($options['name'] ?? $legacySettings['display_name'] ?? 'Live Worship')) ?: 'Live Worship';
$requestedOwner = trim((string) ($options['owner-actor-id'] ?? ''));

function deterministicUuid(string $key): string
{
    $bytes = md5($key, true);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50); // UUID version 5 shape.
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant.
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
}

function activeActor(PDO $db, string $provider, string $subject): ?string
{
    $stmt = $db->prepare(
        'SELECT id FROM lw_control.actors
          WHERE identity_provider=:provider AND external_subject=:subject
            AND inactivated_on IS NULL AND inactivated_by IS NULL
          LIMIT 1'
    );
    $stmt->execute(['provider' => $provider, 'subject' => $subject]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (string) $id;
}

function ensureActor(PDO $db, string $provider, string $subject, string $displayName, string $activatedBy): string
{
    $existing = activeActor($db, $provider, $subject);
    if ($existing !== null) return $existing;
    $id = deterministicUuid("legacy-actor:{$provider}:{$subject}");
    $insert = $db->prepare(
        'INSERT INTO lw_control.actors
            (id, identity_provider, external_subject, display_name, activated_on, activated_by)
         VALUES (:id, :provider, :subject, :display_name, now(), :activated_by)
         ON CONFLICT (id) DO NOTHING'
    );
    $insert->execute([
        'id' => $id,
        'provider' => $provider,
        'subject' => $subject,
        'display_name' => $displayName,
        'activated_by' => $activatedBy,
    ]);
    $existing = activeActor($db, $provider, $subject);
    if ($existing === null) throw new RuntimeException("Could not establish legacy actor {$provider}:{$subject}.");
    return $existing;
}

function quoteSchema(string $schema): string
{
    if (!preg_match('/^lw_t_[0-9a-f]+$/', $schema)) throw new RuntimeException('Invalid tenant schema.');
    return '"' . $schema . '"';
}

function jsonText(mixed $value): string
{
    $decoded = is_array($value) ? $value : json_decode((string) $value, true);
    return json_encode(is_array($decoded) ? $decoded : [], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}

function actorForLegacyMember(PDO $db, array $member, array $accounts, string $ownerActor, string $systemActor): string
{
    if ($member['identity_id'] !== null) {
        $subject = (string) $member['identity_id'];
        $existing = activeActor($db, 'mainzworld', $subject);
        if ($existing !== null) return $existing;
        $user = $db->prepare('SELECT username FROM auth.users WHERE id=:id');
        $user->execute(['id' => $member['identity_id']]);
        return ensureActor($db, 'mainzworld', $subject, (string) ($user->fetchColumn() ?: "MainzWare user {$subject}"), $systemActor);
    }

    $accountId = (string) ($member['standalone_account_id'] ?? '');
    $account = $accounts[$accountId] ?? null;
    $username = trim((string) ($account['username'] ?? "Legacy member {$accountId}"));
    // The original standalone account and MainzWare account are the same local
    // developer identity in this database. Preserve one actor for both paths.
    $mainz = activeActor($db, 'mainzworld', '1');
    if ($mainz !== null && strtolower($username) === 'mainsrm') return $mainz;
    return ensureActor($db, 'live_worship_legacy', "standalone:{$accountId}", $username, $systemActor);
}

if ($requestedOwner !== '' && !TenantNames::validUuid($requestedOwner)) {
    throw new InvalidArgumentException('--owner-actor-id must be a UUID.');
}

$accounts = [];
foreach ($migrator->query('SELECT id, username FROM live_worship.standalone_accounts')->fetchAll() as $account) {
    $accounts[(string) $account['id']] = $account;
}

$ownerActor = $requestedOwner;
if ($ownerActor === '') {
    $ownerActor = activeActor($migrator, 'mainzworld', '1');
    if ($ownerActor === null) {
        $ownerActor = ensureActor($migrator, 'mainzworld', '1', 'mainsrm', $systemActor);
    }
}

$slug = TenantNames::slugFromDisplayName($displayName);
$existingTenant = $migrator->prepare(
    'SELECT id, slug, schema_name, provisioning_state
       FROM lw_control.tenants
      WHERE lower(slug)=lower(:slug)
        AND inactivated_on IS NULL AND inactivated_by IS NULL'
);
$existingTenant->execute(['slug' => $slug]);
$tenant = $existingTenant->fetch();
if (!$tenant) {
    $tenant = TenantProvisioner::provision($migrator, $displayName, $ownerActor, $slug);
    $tenant['provisioning_state'] = 'active';
} elseif ($tenant['provisioning_state'] !== 'active') {
    throw new RuntimeException("Tenant {$slug} exists but is not active; resolve provisioning state before backfill.");
}

$tenantId = (string) $tenant['id'];
$schemaName = (string) $tenant['schema_name'];
$schema = quoteSchema($schemaName);
if ($schemaName !== TenantNames::schemaName($tenantId)) throw new RuntimeException('Tenant schema does not match its immutable tenant ID.');

$legacyMembers = $migrator->query(
    'SELECT id, identity_id, role, standalone_account_id, view_mode, theme,
            activated_on, inactivated_on
       FROM live_worship.members
      ORDER BY id'
)->fetchAll();
$memberActors = [];
$memberTargets = [];
$memberByActor = $migrator->prepare(
    "SELECT id FROM {$schema}.members
      WHERE actor_id=:actor_id
        AND inactivated_on IS NULL AND inactivated_by IS NULL
      LIMIT 1"
);
$insertMember = $migrator->prepare(
    "INSERT INTO {$schema}.members
        (id, actor_id, role, view_mode, theme, activated_on, activated_by)
     VALUES (:id, :actor_id, :role, :view_mode, :theme, :activated_on, :activated_by)
     ON CONFLICT (id) DO NOTHING"
);
$updateMember = $migrator->prepare(
    "UPDATE {$schema}.members
        SET role=:role, view_mode=:view_mode, theme=:theme,
            inactivated_on=NULL, inactivated_by=NULL
      WHERE id=:id"
);

foreach ($legacyMembers as $member) {
    $legacyId = (string) $member['id'];
    $actorId = actorForLegacyMember($migrator, $member, $accounts, $ownerActor, $systemActor);
    $memberActors[$legacyId] = $actorId;
    $memberByActor->execute(['actor_id' => $actorId]);
    $targetId = $memberByActor->fetchColumn();
    if ($targetId === false) {
        $targetId = deterministicUuid("legacy-tenant:{$tenantId}:member:{$actorId}");
        $role = $actorId === $ownerActor ? 'owner' : ((string) $member['role'] === 'owner' ? 'leader' : (string) $member['role']);
        $insertMember->execute([
            'id' => $targetId,
            'actor_id' => $actorId,
            'role' => $role,
            'view_mode' => in_array($member['view_mode'], ['choir', 'musician'], true) ? $member['view_mode'] : 'choir',
            'theme' => in_array($member['theme'], ['light', 'dark'], true) ? $member['theme'] : 'light',
            'activated_on' => $member['activated_on'] ?? gmdate('c'),
            'activated_by' => $actorId,
        ]);
    }
    $targetId = (string) $targetId;
    if ($actorId === $ownerActor) {
        $updateMember->execute([
            'id' => $targetId,
            'role' => 'owner',
            'view_mode' => in_array($member['view_mode'], ['choir', 'musician'], true) ? $member['view_mode'] : 'choir',
            'theme' => in_array($member['theme'], ['light', 'dark'], true) ? $member['theme'] : 'light',
        ]);
    }
    $memberTargets[$legacyId] = $targetId;
    TenantMembershipDirectory::sync($migrator, $tenantId, $actorId, $targetId, $actorId === $ownerActor ? 'owner' : ((string) $member['role'] === 'owner' ? 'leader' : (string) $member['role']), $ownerActor);
}

// Ensure the provisioned owner remains available even if the legacy member
// row was soft-deleted during the lifecycle migration.
$ownerMember = $memberByActor;
$ownerMember->execute(['actor_id' => $ownerActor]);
$ownerMemberId = $ownerMember->fetchColumn();
if ($ownerMemberId === false) throw new RuntimeException('Backfill owner membership is unavailable.');

$legacySettingsRow = $legacySettings ?: ['display_name' => $displayName, 'logo_path' => null, 'logo_mime_type' => null, 'logo_file_size_bytes' => null, 'updated_at' => null];
$newLogoPath = null;
$legacyLogo = trim((string) ($legacySettingsRow['logo_path'] ?? ''));
if ($legacyLogo !== '') {
    $storageRoot = rtrim((string) (getenv('LIVE_WORSHIP_STORAGE_DIR') ?: dirname(__DIR__) . '/storage'), '/');
    $sourceLogo = $storageRoot . '/' . ltrim($legacyLogo, '/');
    if (is_file($sourceLogo)) {
        $newLogoPath = TenantNames::storagePrefix($tenantId) . '/branding/migrated/' . basename($legacyLogo);
        $destination = $storageRoot . '/' . $newLogoPath;
        if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0770, true);
        if (!is_file($destination) && !copy($sourceLogo, $destination)) throw new RuntimeException('Could not copy the existing team logo.');
    }
}

$migrator->beginTransaction();
try {
    $settings = $migrator->prepare(
        "UPDATE {$schema}.settings
            SET display_name=:display_name, logo_path=:logo_path,
                logo_mime_type=:logo_mime_type, logo_file_size_bytes=:logo_file_size_bytes,
                updated_on=COALESCE(:updated_on, updated_on),
                updated_at=COALESCE(:updated_at, updated_at)
          WHERE id=true"
    );
    $settings->execute([
        'display_name' => $displayName,
        'logo_path' => $newLogoPath,
        'logo_mime_type' => $newLogoPath ? $legacySettingsRow['logo_mime_type'] : null,
        'logo_file_size_bytes' => $newLogoPath ? $legacySettingsRow['logo_file_size_bytes'] : null,
        'updated_on' => $legacySettingsRow['updated_at'],
        'updated_at' => $legacySettingsRow['updated_at'],
    ]);

    $legacySongs = $migrator->query(
        'SELECT id, title, writer, default_key, original_key, lyrics, sections, ocr_text,
                import_source, import_path, import_sha256, created_by, created_at, updated_at,
                activated_on, inactivated_on
           FROM live_worship.songs
          ORDER BY id'
    )->fetchAll();
    $insertSong = $migrator->prepare(
        "INSERT INTO {$schema}.library_songs
            (id, title, writer, original_key, default_key, lyrics, sections, ocr_text,
             origin, source_content_hash, created_by, activated_on, activated_by,
             inactivated_on, inactivated_by, updated_on)
         VALUES (:id, :title, :writer, :original_key, :default_key, :lyrics,
                 CAST(:sections AS jsonb), :ocr_text, :origin, :hash, :created_by,
                 :activated_on, :activated_by, :inactivated_on, :inactivated_by, :updated_on)
         ON CONFLICT (id) DO NOTHING"
    );
    $insertRevision = $migrator->prepare(
        "INSERT INTO {$schema}.song_revisions
            (id, song_id, source_key, lyrics, sections, content_hash, created_by,
             activated_on, activated_by, inactivated_on, inactivated_by)
         VALUES (:id, :song_id, :source_key, :lyrics, CAST(:sections AS jsonb), :hash,
                 :created_by, :activated_on, :activated_by, :inactivated_on, :inactivated_by)
         ON CONFLICT (id) DO NOTHING"
    );
    $songIds = [];
    foreach ($legacySongs as $song) {
        $legacyId = (string) $song['id'];
        $id = deterministicUuid("legacy-tenant:{$tenantId}:song:{$legacyId}");
        $createdBy = $memberActors[(string) $song['created_by']] ?? $ownerActor;
        $inactivatedBy = $song['inactivated_on'] === null ? null : ($memberActors[(string) ($song['created_by'] ?? '')] ?? $ownerActor);
        $sections = jsonText($song['sections']);
        $hash = trim((string) ($song['import_sha256'] ?? '')) ?: hash('sha256', implode("\n", [(string) $song['title'], (string) $song['writer'], (string) $song['original_key'], (string) $song['default_key'], (string) $song['lyrics'], $sections]));
        $origin = ((string) ($song['import_source'] ?? '') !== '' || (string) ($song['import_path'] ?? '') !== '') ? 'tenant_imported_external' : 'tenant_created';
        $insertSong->execute([
            'id' => $id,
            'title' => $song['title'],
            'writer' => $song['writer'],
            'original_key' => $song['original_key'] ?: $song['default_key'],
            'default_key' => $song['default_key'],
            'lyrics' => $song['lyrics'],
            'sections' => $sections,
            'ocr_text' => $song['ocr_text'],
            'origin' => $origin,
            'hash' => $hash,
            'created_by' => $createdBy,
            'activated_on' => $song['activated_on'] ?: $song['created_at'],
            'activated_by' => $createdBy,
            'inactivated_on' => $song['inactivated_on'],
            'inactivated_by' => $inactivatedBy,
            'updated_on' => $song['updated_at'] ?: $song['created_at'],
        ]);
        $insertRevision->execute([
            'id' => deterministicUuid("legacy-tenant:{$tenantId}:song-revision:{$legacyId}"),
            'song_id' => $id,
            'source_key' => $song['default_key'],
            'lyrics' => $song['lyrics'],
            'sections' => $sections,
            'hash' => $hash,
            'created_by' => $createdBy,
            'activated_on' => $song['activated_on'] ?: $song['created_at'],
            'activated_by' => $createdBy,
            'inactivated_on' => $song['inactivated_on'],
            'inactivated_by' => $inactivatedBy,
        ]);
        $songIds[$legacyId] = $id;
    }

    $legacySetlists = $migrator->query(
        'SELECT id, name, service_at, created_by, created_at, activated_on, inactivated_on
           FROM live_worship.setlists
          ORDER BY id'
    )->fetchAll();
    $insertSetlist = $migrator->prepare(
        "INSERT INTO {$schema}.setlists
            (id, name, service_at, created_by, activated_on, activated_by,
             inactivated_on, inactivated_by)
         VALUES (:id, :name, :service_at, :created_by, :activated_on, :activated_by,
                 :inactivated_on, :inactivated_by)
         ON CONFLICT (id) DO NOTHING"
    );
    $setlistIds = [];
    foreach ($legacySetlists as $setlist) {
        $legacyId = (string) $setlist['id'];
        $id = deterministicUuid("legacy-tenant:{$tenantId}:setlist:{$legacyId}");
        $createdBy = $memberActors[(string) $setlist['created_by']] ?? $ownerActor;
        $setlistIds[$legacyId] = $id;
        $insertSetlist->execute([
            'id' => $id,
            'name' => $setlist['name'],
            'service_at' => $setlist['service_at'],
            'created_by' => $createdBy,
            'activated_on' => $setlist['activated_on'] ?: $setlist['created_at'],
            'activated_by' => $createdBy,
            'inactivated_on' => $setlist['inactivated_on'],
            'inactivated_by' => $setlist['inactivated_on'] === null ? null : $createdBy,
        ]);
    }

    $legacySetlistSongs = $migrator->query(
        'SELECT setlist_id, song_id, position, activated_on, activated_by, inactivated_on
           FROM live_worship.setlist_songs
          ORDER BY setlist_id, position'
    )->fetchAll();
    $insertSetlistSong = $migrator->prepare(
        "INSERT INTO {$schema}.setlist_songs
            (id, setlist_id, song_id, position, activated_on, activated_by,
             inactivated_on, inactivated_by)
         VALUES (:id, :setlist_id, :song_id, :position, :activated_on, :activated_by,
                 :inactivated_on, :inactivated_by)
         ON CONFLICT (id) DO NOTHING"
    );
    foreach ($legacySetlistSongs as $setlistSong) {
        $setlistId = $setlistIds[(string) $setlistSong['setlist_id']] ?? null;
        $songId = $songIds[(string) $setlistSong['song_id']] ?? null;
        if ($setlistId === null || $songId === null) continue;
        $by = $memberActors[(string) $setlistSong['activated_by']] ?? $ownerActor;
        $insertSetlistSong->execute([
            'id' => deterministicUuid("legacy-tenant:{$tenantId}:setlist-song:{$setlistSong['setlist_id']}:{$setlistSong['song_id']}:{$setlistSong['position']}"),
            'setlist_id' => $setlistId,
            'song_id' => $songId,
            'position' => $setlistSong['position'],
            'activated_on' => $setlistSong['activated_on'],
            'activated_by' => $by,
            'inactivated_on' => $setlistSong['inactivated_on'],
            'inactivated_by' => $setlistSong['inactivated_on'] === null ? null : $by,
        ]);
    }

    $legacyState = $migrator->query('SELECT * FROM live_worship.live_state ORDER BY setlist_id')->fetchAll();
    $insertState = $migrator->prepare(
        "INSERT INTO {$schema}.live_state
            (id, setlist_id, song_id, section_id, control_mode, controller_member_id,
             controller_token, is_live, revision, updated_on, activated_on, activated_by,
             inactivated_on, inactivated_by)
         VALUES (:id, :setlist_id, :song_id, :section_id, :control_mode, :controller_member_id,
                 :controller_token, :is_live, :revision, :updated_on, :activated_on, :activated_by,
                 :inactivated_on, :inactivated_by)
         ON CONFLICT (id) DO NOTHING"
    );
    foreach ($legacyState as $state) {
        $setlistId = $setlistIds[(string) $state['setlist_id']] ?? null;
        if ($setlistId === null) continue;
        $insertState->execute([
            'id' => deterministicUuid("legacy-tenant:{$tenantId}:live-state:{$state['setlist_id']}"),
            'setlist_id' => $setlistId,
            'song_id' => $songIds[(string) $state['song_id']] ?? null,
            'section_id' => $state['section_id'],
            'control_mode' => in_array($state['control_mode'], ['manual', 'automatic'], true) ? $state['control_mode'] : 'manual',
            'controller_member_id' => $memberTargets[(string) ($state['controller_member_id'] ?? '')] ?? null,
            'controller_token' => $state['controller_token'],
            'is_live' => $state['is_live'],
            'revision' => max(1, (int) $state['revision']),
            'updated_on' => $state['updated_at'],
            'activated_on' => $state['activated_on'],
            'activated_by' => $ownerActor,
            'inactivated_on' => $state['inactivated_on'],
            'inactivated_by' => $state['inactivated_on'] === null ? null : $ownerActor,
        ]);
    }

    $migrator->prepare(
        'INSERT INTO lw_control.audit_events
            (id, tenant_id, actor_id, event_type, payload, activated_on, activated_by)
         SELECT :id, :tenant_id, :actor_id, \'tenant.legacy_backfill_completed\', CAST(:payload AS jsonb), now(), :activated_by
          WHERE NOT EXISTS (
              SELECT 1 FROM lw_control.audit_events
               WHERE tenant_id=:tenant_id_check AND event_type=\'tenant.legacy_backfill_completed\'
          )'
    )->execute([
        'id' => deterministicUuid("legacy-tenant:{$tenantId}:backfill"),
        'tenant_id' => $tenantId,
        'actor_id' => $ownerActor,
        'payload' => json_encode(['source_schema' => 'live_worship', 'song_count' => count($legacySongs), 'setlist_count' => count($legacySetlists)], JSON_THROW_ON_ERROR),
        'activated_by' => $ownerActor,
        'tenant_id_check' => $tenantId,
    ]);
    $migrator->commit();
} catch (Throwable $error) {
    if ($migrator->inTransaction()) $migrator->rollBack();
    throw $error;
}

echo json_encode([
    'tenant_id' => $tenantId,
    'slug' => $slug,
    'url_path' => '/live-worship/' . $slug,
    'schema_name' => $schemaName,
    'source_schema' => 'live_worship',
    'source_preserved' => true,
    'message' => 'Legacy Live Worship data was copied into the UUID tenant. Scan-page assets were not copied.',
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
