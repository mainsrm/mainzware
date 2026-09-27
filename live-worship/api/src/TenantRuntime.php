<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;
use Throwable;

/**
 * Tenant-facing runtime for the UUID-based tenant schema model.
 *
 * The legacy API remains available for standalone accounts and the current
 * development workspace. MainzWare-authenticated requests with a selected
 * tenant are routed here so integer legacy IDs can never cross into a tenant
 * schema accidentally.
 */
final class TenantRuntime
{
    private function __construct(private PDO $db, private array $context)
    {
        $expected = TenantNames::schemaName((string) ($context['tenant_id'] ?? ''));
        $this->schema = $this->identifier($expected);
        $this->tenantId = (string) $context['tenant_id'];
        $this->actorId = (string) $context['actor_id'];
        $this->memberId = (string) $context['member_id'];
    }

    private string $schema;
    private string $tenantId;
    private string $actorId;
    private string $memberId;

    public static function dispatch(PDO $db, array $context, string $method, string $path): void
    {
        $runtime = new self($db, $context);
        $runtime->route($method, $path);
    }

    private function route(string $method, string $path): void
    {
        $query = (string) (parse_url($path, PHP_URL_QUERY) ?: '');
        $parsedPath = parse_url($path, PHP_URL_PATH) ?: '/';
        $path = rtrim(preg_replace('#^/api/v1/live-worship#', '', $parsedPath), '/') ?: '/';

        if (($this->context['support_mode'] ?? false) && $method !== 'GET') {
            throw new ApiError(403, 'This support session is read-only.');
        }

        if ($method === 'GET' && $path === '/me') { $this->me(); return; }
        if ($method === 'PATCH' && $path === '/me/view-mode') { $this->saveViewMode(); return; }
        if ($method === 'PATCH' && $path === '/me/theme') { $this->saveTheme(); return; }
        if ($method === 'GET' && $path === '/settings') { $this->settings(); return; }
        if ($method === 'PUT' && $path === '/settings') { $this->updateSettings(); return; }
        if ($method === 'POST' && $path === '/settings/logo') { $this->uploadLogo(); return; }
        if ($method === 'DELETE' && $path === '/settings/logo') { $this->deleteLogo(); return; }
        if ($method === 'GET' && $path === '/branding/logo') { $this->showLogo(); return; }
        if ($method === 'GET' && $path === '/storage') { $this->storage(); return; }
        if ($method === 'GET' && $path === '/live') { $this->currentLive(); return; }
        if ($method === 'GET' && $path === '/invitations') { $this->invitations(); return; }
        if ($method === 'POST' && $path === '/invitations') { $this->createInvitation(); return; }
        if ($method === 'DELETE' && preg_match('#^/invitations/([0-9a-f-]{36})$#i', $path, $m)) { $this->revokeInvitation(strtolower($m[1])); return; }
        if ($method === 'GET' && $path === '/members') { $this->members(); return; }
        if ($method === 'POST' && $path === '/members') { $this->addMember(); return; }
        if ($method === 'PUT' && preg_match('#^/members/([0-9a-f-]{36})$#i', $path, $m)) { $this->updateMember(strtolower($m[1])); return; }
        if ($method === 'DELETE' && preg_match('#^/members/([0-9a-f-]{36})$#i', $path, $m)) { $this->removeMember(strtolower($m[1])); return; }
        if ($method === 'POST' && $path === '/songs/ocr') { $this->recognizeSongPage(); return; }
        if ($method === 'GET' && $path === '/songs') { $this->songs(); return; }
        if ($method === 'POST' && $path === '/songs') { $this->createSong(); return; }
        if ($method === 'GET' && preg_match('#^/songs/([0-9a-f-]{36})$#i', $path, $m)) { $this->song(strtolower($m[1])); return; }
        if ($method === 'PATCH' && preg_match('#^/songs/([0-9a-f-]{36})/key$#i', $path, $m)) { $this->updateSong(strtolower($m[1]), true); return; }
        if ($method === 'PUT' && preg_match('#^/songs/([0-9a-f-]{36})$#i', $path, $m)) { $this->updateSong(strtolower($m[1]), false); return; }
        if ($method === 'DELETE' && preg_match('#^/songs/([0-9a-f-]{36})$#i', $path, $m)) { $this->deleteSong(strtolower($m[1])); return; }
        if ($method === 'GET' && $path === '/catalog/songs') { $this->catalogSongs(); return; }
        if ($method === 'POST' && $path === '/catalog/import-all') { $this->importAllCatalogSongs(); return; }
        if ($method === 'POST' && preg_match('#^/catalog/songs/([0-9a-f-]{36})/import$#i', $path, $m)) { $this->importCatalogSong(strtolower($m[1])); return; }
        if ($method === 'GET' && $path === '/setlists') { $this->setlists($query); return; }
        if ($method === 'POST' && $path === '/setlists') { $this->createSetlist(); return; }
        if ($method === 'PUT' && preg_match('#^/setlists/([0-9a-f-]{36})$#i', $path, $m)) { $this->updateSetlist(strtolower($m[1])); return; }
        if ($method === 'DELETE' && preg_match('#^/setlists/([0-9a-f-]{36})$#i', $path, $m)) { $this->deleteSetlist(strtolower($m[1])); return; }
        if ($method === 'POST' && preg_match('#^/setlists/([0-9a-f-]{36})/start$#i', $path, $m)) { $this->startSetlist(strtolower($m[1])); return; }
        if ($method === 'GET' && preg_match('#^/setlists/([0-9a-f-]{36})/state$#i', $path, $m)) { $this->liveState(strtolower($m[1])); return; }
        if ($method === 'PATCH' && preg_match('#^/setlists/([0-9a-f-]{36})/control$#i', $path, $m)) { $this->updateControlMode(strtolower($m[1])); return; }
        if ($method === 'PUT' && preg_match('#^/setlists/([0-9a-f-]{36})/state$#i', $path, $m)) { $this->updateLiveState(strtolower($m[1])); return; }

        http_response_code(404);
        echo json_encode(['error' => 'Live Worship endpoint not found.']);
    }

    private function me(): void
    {
        $response = $this->context;
        $response['tenant_role'] = $response['role'];
        // Keep the current web client contract while retaining owner semantics
        // in tenant_role for future role-aware administration screens.
        if ($response['role'] === 'owner') $response['role'] = 'leader';
        $this->respond($response);
    }

    private function saveViewMode(): void
    {
        $this->requireLeader();
        $mode = $this->body()['view_mode'] ?? null;
        if (!in_array($mode, ['choir', 'musician'], true)) throw new ApiError(400, 'Choose Choir or Musician view.');
        $stmt = $this->db->prepare("UPDATE {$this->schema}.members SET view_mode=:mode WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
        $stmt->execute(['mode' => $mode, 'id' => $this->memberId]);
        $this->respond(['view_mode' => $mode]);
    }

    private function saveTheme(): void
    {
        $theme = $this->body()['theme'] ?? null;
        if (!in_array($theme, ['light', 'dark'], true)) throw new ApiError(400, 'Choose light or dark mode.');
        $stmt = $this->db->prepare("UPDATE {$this->schema}.members SET theme=:theme WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
        $stmt->execute(['theme' => $theme, 'id' => $this->memberId]);
        $this->respond(['theme' => $theme]);
    }

    private function settings(): void { $this->respond($this->settingsData()); }

    private function updateSettings(): void
    {
        $this->requireLeader();
        $name = trim((string) ($this->body()['display_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) throw new ApiError(400, 'Choose a team name between 1 and 120 characters.');
        $stmt = $this->db->prepare("UPDATE {$this->schema}.settings SET display_name=:name, updated_on=now(), updated_at=now() WHERE id=true AND inactivated_on IS NULL AND inactivated_by IS NULL");
        $stmt->execute(['name' => $name]);
        $this->respond($this->settingsData());
    }

    private function uploadLogo(): void
    {
        $this->requireLeader();
        if (!isset($_FILES['logo'])) throw new ApiError(400, 'Choose an image to upload.');
        $stored = Files::saveTenant($_FILES['logo'], $this->tenantId, 'branding');
        try {
            $old = $this->db->query("SELECT logo_path FROM {$this->schema}.settings WHERE id=true AND inactivated_on IS NULL AND inactivated_by IS NULL")->fetchColumn() ?: null;
            $update = $this->db->prepare("UPDATE {$this->schema}.settings SET logo_path=:path, logo_mime_type=:mime, logo_file_size_bytes=:size, updated_on=now(), updated_at=now() WHERE id=true AND inactivated_on IS NULL AND inactivated_by IS NULL");
            $update->execute(['path' => $stored['path'], 'mime' => $stored['mime'], 'size' => $stored['size']]);
        } catch (Throwable $error) {
            Files::remove($stored['path']);
            throw $error;
        }
        if ($old) Files::remove($old);
        $this->respond($this->settingsData());
    }

    private function deleteLogo(): void
    {
        $this->requireLeader();
        $old = $this->db->query("SELECT logo_path FROM {$this->schema}.settings WHERE id=true AND inactivated_on IS NULL AND inactivated_by IS NULL")->fetchColumn() ?: null;
        $this->db->exec("UPDATE {$this->schema}.settings SET logo_path=NULL, logo_mime_type=NULL, logo_file_size_bytes=NULL, updated_on=now(), updated_at=now() WHERE id=true AND inactivated_on IS NULL AND inactivated_by IS NULL");
        if ($old) Files::remove($old);
        $this->respond($this->settingsData());
    }

    private function showLogo(): void
    {
        $row = $this->db->query("SELECT logo_path, logo_mime_type FROM {$this->schema}.settings WHERE id=true AND inactivated_on IS NULL AND inactivated_by IS NULL")->fetch();
        if (!$row || !$row['logo_path']) throw new ApiError(404, 'No team logo has been uploaded.');
        $path = Files::absolute((string) $row['logo_path']);
        header('Content-Type: ' . ($row['logo_mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        readfile($path);
    }

    private function settingsData(): array
    {
        $row = $this->db->query("SELECT display_name, logo_path, COALESCE(updated_at, updated_on) AS updated_at FROM {$this->schema}.settings WHERE id=true AND inactivated_on IS NULL AND inactivated_by IS NULL")->fetch();
        if (!$row) throw new ApiError(500, 'Tenant settings are unavailable.');
        $row['logo_url'] = $row['logo_path'] ? '/api/v1/live-worship/branding/logo?v=' . rawurlencode((string) $row['updated_at']) : null;
        unset($row['logo_path']);
        return $row;
    }

    private function storage(): void
    {
        if (!($this->context['support_mode'] ?? false)) $this->requireLeader();
        $this->respond(['page_count' => 0, 'total_bytes' => 0, 'total_megabytes' => 0]);
    }

    private function invitations(): void
    {
        $this->requireLeader();
        $this->respond(TenantInvitations::forTenant($this->db, $this->tenantId));
    }

    private function createInvitation(): void
    {
        $this->requireLeader();
        $role = trim((string) ($this->body()['role'] ?? 'choir'));
        try {
            $invitation = TenantInvitations::create($this->db, $this->tenantId, $this->actorId, $role);
        } catch (TenantInvitationError $error) {
            throw new ApiError($error->status, $error->getMessage());
        }
        $this->respond($invitation, 201);
    }

    private function revokeInvitation(string $id): void
    {
        $this->requireLeader();
        try {
            TenantInvitations::revoke($this->db, $this->tenantId, $this->actorId, $this->uuid($id));
        } catch (TenantInvitationError $error) {
            throw new ApiError($error->status, $error->getMessage());
        }
        $this->respond(['ok' => true]);
    }

    private function members(): void
    {
        $this->requireLeader();
        $rows = $this->db->query(
            "SELECT m.id, m.actor_id, a.external_subject AS identity_id, m.role,
                    (m.inactivated_on IS NULL AND m.inactivated_by IS NULL) AS active,
                    COALESCE(NULLIF(trim(a.display_name), ''), a.external_subject) AS username,
                    'mainzware' AS auth_type, m.activated_on AS created_at
               FROM {$this->schema}.members m
               JOIN lw_control.actors a ON a.id=m.actor_id
              ORDER BY (m.inactivated_on IS NULL AND m.inactivated_by IS NULL) DESC, username"
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['active'] = $this->truthy($row['active']);
            $row['id'] = (string) $row['id'];
        }
        $this->respond($rows);
    }

    private function addMember(): void
    {
        $this->requireLeader();
        $body = $this->body();
        $username = trim((string) ($body['username'] ?? ''));
        $role = (string) ($body['role'] ?? '');
        $authType = (string) ($body['auth_type'] ?? 'mainzware');
        if ($username === '' || mb_strlen($username) > 80 || !in_array($role, ['leader', 'choir', 'musician'], true) || $authType !== 'mainzware') {
            throw new ApiError(400, 'Enter a MainzWare username and choose a valid Live Worship role.');
        }
        $user = $this->db->prepare('SELECT id, username FROM auth.users WHERE username=:username AND is_active=true');
        $user->execute(['username' => $username]);
        $identity = $user->fetch();
        if (!$identity) throw new ApiError(404, 'No active MainzWare account has that username.');
        $actorId = ActorIdentity::ensureMainzWareActor($this->db, ['id' => (int) $identity['id'], 'username' => (string) $identity['username']]);

        $this->db->beginTransaction();
        try {
            $existing = $this->db->prepare("SELECT id FROM {$this->schema}.members WHERE actor_id=:actor_id ORDER BY activated_on DESC LIMIT 1");
            $existing->execute(['actor_id' => $actorId]);
            $memberId = $existing->fetchColumn();
            if ($memberId !== false) {
                $save = $this->db->prepare("UPDATE {$this->schema}.members SET role=:role, activated_on=now(), activated_by=:by, inactivated_on=NULL, inactivated_by=NULL WHERE id=:id");
                $save->execute(['role' => $role, 'by' => $this->actorId, 'id' => $memberId]);
            } else {
                $memberId = TenantNames::uuid();
                $save = $this->db->prepare("INSERT INTO {$this->schema}.members (id, actor_id, role, activated_on, activated_by) VALUES (:id, :actor_id, :role, now(), :by)");
                $save->execute(['id' => $memberId, 'actor_id' => $actorId, 'role' => $role, 'by' => $this->actorId]);
            }
            TenantMembershipDirectory::sync($this->db, $this->tenantId, $actorId, (string) $memberId, $role, $this->actorId);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->respond($this->memberById((string) $memberId), 201);
    }

    private function updateMember(string $id): void
    {
        $this->requireLeader();
        $role = (string) ($this->body()['role'] ?? '');
        if (!in_array($role, ['leader', 'choir', 'musician'], true)) throw new ApiError(400, 'Choose leader, choir, or musician.');
        $existing = $this->memberById($id);
        if ($existing['role'] === 'owner') throw new ApiError(400, 'The tenant owner membership is managed through team administration.');
        if ($existing['role'] === 'leader' && $role !== 'leader') $this->ensureAnotherLeader($id);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE {$this->schema}.members SET role=:role WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
            $stmt->execute(['role' => $role, 'id' => $id]);
            TenantMembershipDirectory::updateRole($this->db, $this->tenantId, $id, $role);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->respond($this->memberById($id));
    }

    private function removeMember(string $id): void
    {
        $this->requireLeader();
        $existing = $this->memberById($id);
        if ($id === $this->memberId) throw new ApiError(400, 'You cannot remove your own Live Worship access.');
        if ($existing['role'] === 'owner') throw new ApiError(400, 'The tenant owner membership cannot be removed.');
        if ($existing['role'] === 'leader') $this->ensureAnotherLeader($id);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE {$this->schema}.members SET inactivated_on=now(), inactivated_by=:by WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
            $stmt->execute(['by' => $this->actorId, 'id' => $id]);
            TenantMembershipDirectory::deactivate($this->db, $this->tenantId, $id, $this->actorId);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->respond(['ok' => true]);
    }

    private function recognizeSongPage(): void
    {
        $this->requireFeature('advanced-import');
        $this->requireLeader();
        if (!isset($_FILES['image'])) throw new ApiError(400, 'Choose a song page photo.');
        $image = $_FILES['image'];
        Files::inspect($image);
        $bytes = @file_get_contents($image['tmp_name']);
        if ($bytes === false) throw new ApiError(400, 'The uploaded photo could not be read.');
        $url = trim((string) getenv('LIVE_WORSHIP_OCR_URL')) ?: 'http://127.0.0.1:8765/ocr';
        if (!function_exists('curl_init')) throw new ApiError(503, 'Photo text recognition is unavailable. You can still enter the lyrics manually.');
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['image' => base64_encode($bytes)], JSON_THROW_ON_ERROR), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 180]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $result = is_string($response) ? json_decode($response, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($result) || !isset($result['lines']) || !is_array($result['lines'])) throw new ApiError(503, 'Photo text recognition could not read that page. You can still enter the lyrics manually.');
        $this->respond(['lines' => $result['lines']]);
    }

    private function songs(): void
    {
        $rows = $this->db->query("SELECT id FROM {$this->schema}.library_songs WHERE inactivated_on IS NULL AND inactivated_by IS NULL ORDER BY title, writer, id")->fetchAll(PDO::FETCH_COLUMN);
        $this->respond(array_map(fn($id) => $this->songData((string) $id), $rows));
    }

    private function createSong(): void
    {
        $this->requireLeader();
        $fields = $this->songFields($this->body());
        $id = TenantNames::uuid();
        $hash = hash('sha256', $fields['title'] . "\n" . $fields['writer'] . "\n" . $fields['song_key'] . "\n" . $fields['sections']);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO {$this->schema}.library_songs (id, title, writer, original_key, default_key, lyrics, sections, ocr_text, origin, source_content_hash, created_by, activated_on, activated_by) VALUES (:id, :title, :writer, :original_key, :default_key, :lyrics, CAST(:sections AS jsonb), :ocr_text, 'tenant_created', :hash, :created_by, now(), :activated_by)");
            $stmt->execute(['id' => $id, 'title' => $fields['title'], 'writer' => $fields['writer'], 'original_key' => $fields['song_key'], 'default_key' => $fields['song_key'], 'lyrics' => $fields['lyrics'], 'sections' => $fields['sections'], 'ocr_text' => $fields['ocr_text'], 'hash' => $hash, 'created_by' => $this->actorId, 'activated_by' => $this->actorId]);
            $this->upsertReview($id);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->respond($this->songData($id), 201);
    }

    private function song(string $id): void { $this->respond($this->songData($id)); }

    private function updateSong(string $id, bool $keyOnly): void
    {
        $this->requireLeader();
        $current = $this->rawSong($id);
        $body = $this->body();
        if ($keyOnly) {
            $nextKey = trim((string) ($body['default_key'] ?? ''));
            if ($nextKey === '') throw new ApiError(400, 'Choose a musical key.');
            $sections = Transpose::sections($this->decodeSections($current['sections']), (string) ($current['default_key'] ?? ''), $nextKey);
            $fields = ['title' => $current['title'], 'writer' => $current['writer'], 'song_key' => $nextKey, 'lyrics' => $current['lyrics'], 'sections' => json_encode($sections, JSON_THROW_ON_ERROR), 'ocr_text' => $current['ocr_text']];
        } else {
            $fields = $this->songFields($body);
            $sections = json_decode($fields['sections'], true, 512, JSON_THROW_ON_ERROR) ?: [];
            $sections = Transpose::sections($sections, (string) ($current['default_key'] ?? ''), $fields['song_key']);
            $fields['sections'] = json_encode($sections, JSON_THROW_ON_ERROR);
        }
        $hash = hash('sha256', $fields['title'] . "\n" . $fields['writer'] . "\n" . $fields['song_key'] . "\n" . $fields['sections']);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE {$this->schema}.library_songs SET title=:title, writer=:writer, default_key=:default_key, original_key=COALESCE(original_key, :original_key), lyrics=:lyrics, sections=CAST(:sections AS jsonb), ocr_text=:ocr_text, source_content_hash=:hash, updated_on=now() WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
            $stmt->execute(['id' => $id, 'title' => $fields['title'], 'writer' => $fields['writer'], 'default_key' => $fields['song_key'], 'original_key' => $current['original_key'] ?: $fields['song_key'], 'lyrics' => $fields['lyrics'], 'sections' => $fields['sections'], 'ocr_text' => $fields['ocr_text'], 'hash' => $hash]);
            if ($stmt->rowCount() !== 1) throw new ApiError(404, 'Song not found.');
            if (in_array($current['origin'], ['tenant_created', 'tenant_imported_external'], true) && !$current['master_song_id']) $this->upsertReview($id);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->respond($this->songData($id));
    }

    private function deleteSong(string $id): void
    {
        $this->requireLeader();
        $this->db->beginTransaction();
        try {
            $check = $this->db->prepare("SELECT id FROM {$this->schema}.library_songs WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL FOR UPDATE");
            $check->execute(['id' => $id]);
            if (!$check->fetchColumn()) throw new ApiError(404, 'Song not found.');
            $this->db->prepare("UPDATE {$this->schema}.setlist_songs SET inactivated_on=now(), inactivated_by=:by WHERE song_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL")->execute(['by' => $this->actorId, 'id' => $id]);
            $this->db->prepare("UPDATE {$this->schema}.live_state SET song_id=NULL, section_id=NULL, revision=revision+1, updated_on=now() WHERE song_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL")->execute(['id' => $id]);
            $this->db->prepare("UPDATE {$this->schema}.library_songs SET inactivated_on=now(), inactivated_by=:by, updated_on=now() WHERE id=:id")->execute(['by' => $this->actorId, 'id' => $id]);
            $this->db->prepare("UPDATE lw_control.catalog_review_queue SET review_state='ignored', inactivated_on=now(), inactivated_by=:by WHERE tenant_id=:tenant_id AND tenant_song_id=:song_id AND review_state='pending' AND inactivated_on IS NULL AND inactivated_by IS NULL")->execute(['by' => $this->actorId, 'tenant_id' => $this->tenantId, 'song_id' => $id]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->respond(['ok' => true]);
    }

    private function catalogSongs(): void
    {
        $this->requireFeature('master-catalog');
        $rows = $this->db->query(
            "SELECT s.id, s.title, s.writer, s.original_key, v.source_key AS default_key,
                    v.lyrics, v.sections, s.updated_on,
                    EXISTS (SELECT 1 FROM {$this->schema}.library_songs l WHERE l.master_song_id=s.id AND l.inactivated_on IS NULL AND l.inactivated_by IS NULL) AS imported
               FROM lw_master.songs s
               LEFT JOIN lw_master.song_versions v ON v.id=s.current_version_id
                AND v.inactivated_on IS NULL AND v.inactivated_by IS NULL
              WHERE s.inactivated_on IS NULL AND s.inactivated_by IS NULL
              ORDER BY s.title, s.writer, s.id"
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (string) $row['id'];
            $row['sections'] = $this->decodeSections($row['sections']);
            $row['lyrics'] = (string) ($row['lyrics'] ?? '');
            $row['pages'] = [];
            $row['updated_at'] = $row['updated_on'];
            unset($row['updated_on']);
            $row['imported'] = $this->truthy($row['imported']);
        }
        $this->respond($rows);
    }

    private function importCatalogSong(string $masterId): void
    {
        $this->requireFeature('master-catalog');
        $this->requireLeader();
        $id = $this->importMasterSong($masterId);
        $this->respond($this->songData($id), 201);
    }

    private function importMasterSong(string $masterId): string
    {
        $song = $this->masterSong($masterId);
        $existing = $this->db->prepare("SELECT id FROM {$this->schema}.library_songs WHERE master_song_id=:master_id AND inactivated_on IS NULL AND inactivated_by IS NULL LIMIT 1");
        $existing->execute(['master_id' => $masterId]);
        $id = $existing->fetchColumn();
        if ($id !== false) return (string) $id;
        $id = TenantNames::uuid();
        $stmt = $this->db->prepare("INSERT INTO {$this->schema}.library_songs (id, master_song_id, master_version_id, title, writer, original_key, default_key, lyrics, sections, origin, source_content_hash, created_by, activated_on, activated_by) VALUES (:id, :master_id, :version_id, :title, :writer, :original_key, :default_key, :lyrics, CAST(:sections AS jsonb), 'master_import', :hash, :by, now(), :by)");
        $stmt->execute(['id' => $id, 'master_id' => $masterId, 'version_id' => $song['master_version_id'], 'title' => $song['title'], 'writer' => $song['writer'], 'original_key' => $song['original_key'], 'default_key' => $song['default_key'], 'lyrics' => $song['lyrics'], 'sections' => json_encode($song['sections'], JSON_THROW_ON_ERROR), 'hash' => $song['content_hash'], 'by' => $this->actorId]);
        return $id;
    }

    private function importAllCatalogSongs(): void
    {
        $this->requireFeature('master-catalog');
        $this->requireLeader();
        $ids = $this->db->query('SELECT id FROM lw_master.songs WHERE inactivated_on IS NULL AND inactivated_by IS NULL ORDER BY title, id')->fetchAll(PDO::FETCH_COLUMN);
        $imported = 0;
        foreach ($ids as $id) {
            $before = $this->db->prepare("SELECT 1 FROM {$this->schema}.library_songs WHERE master_song_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
            $before->execute(['id' => $id]);
            if ($before->fetchColumn()) continue;
            $this->importMasterSong((string) $id);
            $imported++;
        }
        $this->respond(['imported' => $imported]);
    }

    private function setlists(string $query): void
    {
        $params = [];
        parse_str($query, $params);
        if (!isset($params['status']) && isset($_GET['status'])) $params['status'] = $_GET['status'];
        $where = (($params['status'] ?? '') === 'archived') ? 's.inactivated_on IS NOT NULL OR s.inactivated_by IS NOT NULL' : 's.inactivated_on IS NULL AND s.inactivated_by IS NULL';
        $stmt = $this->db->query("SELECT s.id FROM {$this->schema}.setlists s WHERE {$where} ORDER BY s.service_at DESC, s.id");
        $this->respond(array_map(fn($id) => $this->setlistData((string) $id), $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function createSetlist(): void
    {
        $this->requireLeader();
        $body = $this->body();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) throw new ApiError(400, 'Set list name is required and must be 200 characters or fewer.');
        $serviceAt = $this->date($body['service_at'] ?? null);
        $songIds = $this->songIds($body['song_ids'] ?? []);
        $id = TenantNames::uuid();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO {$this->schema}.setlists (id, name, service_at, created_by, activated_on, activated_by) VALUES (:id, :name, :service_at, :by, now(), :by)");
            $stmt->execute(['id' => $id, 'name' => $name, 'service_at' => $serviceAt, 'by' => $this->actorId]);
            $state = $this->db->prepare("INSERT INTO {$this->schema}.live_state (id, setlist_id, activated_on, activated_by) VALUES (:id, :setlist, now(), :by)");
            $state->execute(['id' => TenantNames::uuid(), 'setlist' => $id, 'by' => $this->actorId]);
            $this->replaceSetlistSongs($id, $songIds);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->respond($this->setlistData($id), 201);
    }

    private function updateSetlist(string $id): void
    {
        $this->requireLeader();
        $body = $this->body();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) throw new ApiError(400, 'Set list name is required and must be 200 characters or fewer.');
        $serviceAt = $this->date($body['service_at'] ?? null);
        $songIds = $this->songIds($body['song_ids'] ?? []);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE {$this->schema}.setlists SET name=:name, service_at=:service_at WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
            $stmt->execute(['id' => $id, 'name' => $name, 'service_at' => $serviceAt]);
            if ($stmt->rowCount() !== 1) throw new ApiError(404, 'Set list not found or archived.');
            $this->replaceSetlistSongs($id, $songIds);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->respond($this->setlistData($id));
    }

    private function deleteSetlist(string $id): void
    {
        $this->requireLeader();
        $this->db->beginTransaction();
        try {
            $params = ['id' => $id, 'by' => $this->actorId];
            $stmt = $this->db->prepare("UPDATE {$this->schema}.setlists SET inactivated_on=now(), inactivated_by=:by WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
            $stmt->execute($params);
            if ($stmt->rowCount() !== 1) throw new ApiError(404, 'Set list not found.');
            $this->db->prepare("UPDATE {$this->schema}.setlist_songs SET inactivated_on=now(), inactivated_by=:by WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL")->execute($params);
            $this->db->prepare("UPDATE {$this->schema}.live_state SET inactivated_on=now(), inactivated_by=:by, is_live=false, updated_on=now() WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL")->execute($params);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->respond(['ok' => true]);
    }

    private function startSetlist(string $id): void
    {
        $this->requireLeader();
        $setlist = $this->setlistData($id);
        if ($setlist['status'] !== 'active') throw new ApiError(409, 'Archived services cannot be started.');
        $this->db->beginTransaction();
        try {
            $this->db->exec("UPDATE {$this->schema}.live_state SET is_live=false, revision=revision+1, updated_on=now() WHERE is_live=true AND inactivated_on IS NULL AND inactivated_by IS NULL");
            $stmt = $this->db->prepare("UPDATE {$this->schema}.live_state SET is_live=true, revision=revision+1, updated_on=now() WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
            $stmt->execute(['id' => $id]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->respond($this->setlistData($id));
    }

    private function currentLive(): void
    {
        $this->archiveExpired();
        $row = $this->db->query("SELECT l.setlist_id, s.name AS setlist_name, l.song_id, l.section_id, l.control_mode, l.controller_member_id, l.revision, l.updated_on AS updated_at FROM {$this->schema}.live_state l JOIN {$this->schema}.setlists s ON s.id=l.setlist_id WHERE l.is_live=true AND l.inactivated_on IS NULL AND l.inactivated_by IS NULL AND s.inactivated_on IS NULL AND s.inactivated_by IS NULL LIMIT 1")->fetch();
        if (!$row) { $this->respond(null); return; }
        $row['setlist_id'] = (string) $row['setlist_id'];
        $row['song_id'] = $row['song_id'] === null ? null : (string) $row['song_id'];
        $row['controller_member_id'] = $row['controller_member_id'] === null ? null : (string) $row['controller_member_id'];
        $row['revision'] = (int) $row['revision'];
        $this->respond($row);
    }

    private function liveState(string $setlistId): void
    {
        $this->setlistData($setlistId);
        $stmt = $this->db->prepare("SELECT setlist_id, song_id, section_id, control_mode, controller_member_id, revision, updated_on AS updated_at FROM {$this->schema}.live_state WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
        $stmt->execute(['id' => $setlistId]);
        $row = $stmt->fetch();
        if (!$row) throw new ApiError(404, 'Live state not found.');
        $row['setlist_id'] = (string) $row['setlist_id'];
        $row['song_id'] = $row['song_id'] === null ? null : (string) $row['song_id'];
        $row['controller_member_id'] = $row['controller_member_id'] === null ? null : (string) $row['controller_member_id'];
        $row['revision'] = (int) $row['revision'];
        $this->respond($row);
    }

    private function updateControlMode(string $setlistId): void
    {
        $this->requireLeader();
        $mode = (string) ($this->body()['control_mode'] ?? '');
        if (!in_array($mode, ['manual', 'automatic'], true)) throw new ApiError(400, 'Choose manual or automatic control.');
        $token = trim((string) ($this->body()['controller_token'] ?? '')) ?: null;
        if ($mode === 'automatic' && $token === null) throw new ApiError(400, 'Automatic control requires a device token.');
        $setlist = $this->setlistData($setlistId);
        if ($setlist['status'] !== 'active') throw new ApiError(409, 'Archived services cannot be controlled live.');
        $stmt = $this->db->prepare("UPDATE {$this->schema}.live_state SET control_mode=:mode, controller_member_id=:member, controller_token=:token, revision=revision+1, updated_on=now() WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
        $stmt->execute(['mode' => $mode, 'member' => $mode === 'automatic' ? $this->memberId : null, 'token' => $mode === 'automatic' ? $token : null, 'id' => $setlistId]);
        $this->liveState($setlistId);
    }

    private function updateLiveState(string $setlistId): void
    {
        $this->requireLeader();
        $setlist = $this->setlistData($setlistId);
        if ($setlist['status'] !== 'active') throw new ApiError(409, 'Archived services cannot be controlled live.');
        $body = $this->body();
        $songId = $body['song_id'] ?? null;
        if ($songId !== null) {
            $songId = $this->uuid((string) $songId);
            if (!in_array($songId, $setlist['song_ids'], true)) throw new ApiError(400, 'Choose a song from this set list.');
        }
        $sectionId = isset($body['section_id']) && $body['section_id'] !== '' ? (string) $body['section_id'] : null;
        $mode = (string) ($body['control_mode'] ?? 'manual');
        if (!in_array($mode, ['manual', 'automatic'], true)) throw new ApiError(400, 'Choose manual or automatic control.');
        $requestedToken = trim((string) ($body['controller_token'] ?? '')) ?: null;
        if ($mode === 'automatic' && $requestedToken === null) throw new ApiError(400, 'Automatic control requires a device token.');
        $this->db->beginTransaction();
        try {
            $this->db->exec("UPDATE {$this->schema}.live_state SET is_live=false, revision=revision+1, updated_on=now() WHERE is_live=true AND setlist_id<>" . $this->quoteLiteral($setlistId) . " AND inactivated_on IS NULL AND inactivated_by IS NULL");
            $find = $this->db->prepare("SELECT id, controller_token FROM {$this->schema}.live_state WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL FOR UPDATE");
            $find->execute(['id' => $setlistId]);
            $existing = $find->fetch();
            if ($existing && $existing['controller_token'] && $requestedToken && $existing['controller_token'] !== $requestedToken && $mode === 'automatic') throw new ApiError(409, 'Another leader controls automatic guidance for this service.');
            if ($existing) {
                $stmt = $this->db->prepare("UPDATE {$this->schema}.live_state SET song_id=:song, section_id=:section, control_mode=:mode, controller_member_id=:member, controller_token=:token, is_live=true, revision=revision+1, updated_on=now() WHERE id=:id");
                $stmt->execute(['song' => $songId, 'section' => $sectionId, 'mode' => $mode, 'member' => $mode === 'automatic' ? $this->memberId : null, 'token' => $mode === 'automatic' ? ($existing['controller_token'] ?: $requestedToken) : null, 'id' => $existing['id']]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO {$this->schema}.live_state (id, setlist_id, song_id, section_id, control_mode, controller_member_id, controller_token, is_live, activated_on, activated_by) VALUES (:id, :setlist, :song, :section, :mode, :member, :token, true, now(), :by)");
                $stmt->execute(['id' => TenantNames::uuid(), 'setlist' => $setlistId, 'song' => $songId, 'section' => $sectionId, 'mode' => $mode, 'member' => $mode === 'automatic' ? $this->memberId : null, 'token' => $mode === 'automatic' ? $requestedToken : null, 'by' => $this->actorId]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->liveState($setlistId);
    }

    private function songData(string $id): array
    {
        $song = $this->rawSong($id);
        $song['id'] = (string) $song['id'];
        $song['sections'] = $this->decodeSections($song['sections']);
        $song['created_at'] = $song['activated_on'];
        $song['updated_at'] = $song['updated_on'];
        $song['pages'] = [];
        unset($song['activated_on'], $song['updated_on'], $song['master_song_id'], $song['master_version_id'], $song['origin'], $song['source_content_hash']);
        return $song;
    }

    private function rawSong(string $id): array
    {
        $id = $this->uuid($id);
        $stmt = $this->db->prepare("SELECT id, master_song_id, master_version_id, title, writer, original_key, default_key, lyrics, sections, ocr_text, origin, source_content_hash, created_by, activated_on, updated_on FROM {$this->schema}.library_songs WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
        $stmt->execute(['id' => $id]);
        $song = $stmt->fetch();
        if (!$song) throw new ApiError(404, 'Song not found.');
        return $song;
    }

    private function setlistData(string $id): array
    {
        $id = $this->uuid($id);
        $stmt = $this->db->prepare("SELECT s.id, s.name, s.service_at, CASE WHEN s.inactivated_on IS NULL AND s.inactivated_by IS NULL THEN 'active' ELSE 'archived' END AS status, s.activated_on AS created_at, COALESCE(l.is_live, false) AS current FROM {$this->schema}.setlists s LEFT JOIN {$this->schema}.live_state l ON l.setlist_id=s.id AND l.inactivated_on IS NULL AND l.inactivated_by IS NULL WHERE s.id=:id");
        $stmt->execute(['id' => $id]);
        $setlist = $stmt->fetch();
        if (!$setlist) throw new ApiError(404, 'Set list not found.');
        $setlist['id'] = (string) $setlist['id'];
        $setlist['current'] = $this->truthy($setlist['current']);
        $songs = $this->db->prepare("SELECT song_id FROM {$this->schema}.setlist_songs WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL ORDER BY position");
        $songs->execute(['id' => $id]);
        $ids = array_map('strval', $songs->fetchAll(PDO::FETCH_COLUMN));
        $setlist['song_ids'] = $ids;
        $setlist['songs'] = array_map(fn($songId) => $this->songData($songId), $ids);
        return $setlist;
    }

    private function replaceSetlistSongs(string $setlistId, array $songIds): void
    {
        $this->db->prepare("UPDATE {$this->schema}.setlist_songs SET inactivated_on=now(), inactivated_by=:by WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL")->execute(['by' => $this->actorId, 'id' => $setlistId]);
        $insert = $this->db->prepare("INSERT INTO {$this->schema}.setlist_songs (id, setlist_id, song_id, position, activated_on, activated_by) VALUES (:id, :setlist, :song, :position, now(), :by)");
        foreach ($songIds as $index => $songId) $insert->execute(['id' => TenantNames::uuid(), 'setlist' => $setlistId, 'song' => $songId, 'position' => $index + 1, 'by' => $this->actorId]);
    }

    private function songIds(mixed $value): array
    {
        if (!is_array($value) || count($value) > 200) throw new ApiError(400, 'Choose up to 200 songs for the set list.');
        $ids = [];
        foreach ($value as $id) $ids[] = $this->uuid((string) $id);
        $ids = array_values(array_unique($ids));
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT count(*) FROM {$this->schema}.library_songs WHERE id IN ({$marks}) AND inactivated_on IS NULL AND inactivated_by IS NULL");
        $stmt->execute($ids);
        if ((int) $stmt->fetchColumn() !== count($ids)) throw new ApiError(400, 'One or more selected songs no longer exist.');
        return $ids;
    }

    private function songFields(array $body): array
    {
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) throw new ApiError(400, 'Song title is required and must be 200 characters or fewer.');
        $sections = $body['sections'] ?? [];
        if (is_string($sections)) $sections = json_decode($sections, true) ?: [];
        if (!is_array($sections) || count($sections) < 1 || count($sections) > 100) throw new ApiError(400, 'A song needs between 1 and 100 parts.');
        foreach ($sections as &$section) {
            if (!is_array($section)) throw new ApiError(400, 'Each song section must have a name and lyrics.');
            $section['id'] = (string) ($section['id'] ?? bin2hex(random_bytes(8)));
            $section['name'] = trim((string) ($section['name'] ?? ''));
            $section['lyrics'] = (string) ($section['lyrics'] ?? '');
            $section['chords'] = (string) ($section['chords'] ?? '');
            if ($section['name'] === '' || mb_strlen($section['name']) > 80) throw new ApiError(400, 'Each song part needs a name up to 80 characters.');
            $marks = $section['chord_marks'] ?? [];
            if (!is_array($marks) || count($marks) > 500) throw new ApiError(400, 'A song part can have up to 500 positioned chords.');
            $section['chord_marks'] = [];
            foreach ($marks as $mark) {
                if (!is_array($mark) || filter_var($mark['at'] ?? null, FILTER_VALIDATE_INT) === false) throw new ApiError(400, 'Each chord needs a lyric position.');
                $chord = trim((string) ($mark['chord'] ?? ''));
                if ($chord === '' || mb_strlen($chord) > 16) throw new ApiError(400, 'Check the chord names and their lyric positions.');
                $section['chord_marks'][] = ['at' => max(0, min((int) $mark['at'], mb_strlen($section['lyrics']))), 'chord' => $chord];
            }
            usort($section['chord_marks'], static fn(array $a, array $b): int => $a['at'] <=> $b['at']);
        }
        unset($section);
        $key = trim((string) ($body['default_key'] ?? '')) ?: null;
        return ['title' => $title, 'writer' => trim((string) ($body['writer'] ?? '')) ?: null, 'song_key' => $key, 'lyrics' => (string) ($body['lyrics'] ?? implode("\n\n", array_map(static fn(array $section): string => $section['name'] . "\n" . $section['lyrics'], $sections))), 'sections' => json_encode(array_values($sections), JSON_THROW_ON_ERROR), 'ocr_text' => (string) ($body['ocr_text'] ?? '')];
    }

    private function upsertReview(string $songId): void
    {
        $song = $this->rawSong($songId);
        $snapshot = ['title' => $song['title'], 'writer' => $song['writer'], 'original_key' => $song['original_key'], 'default_key' => $song['default_key'], 'lyrics' => $song['lyrics'], 'sections' => $this->decodeSections($song['sections']), 'ocr_text' => $song['ocr_text'], 'origin' => $song['origin'], 'source_content_hash' => $song['source_content_hash'], 'created_by' => $song['created_by'] ?? $this->actorId, 'updated_on' => $song['updated_on']];
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $existing = $this->db->prepare('SELECT id FROM lw_control.catalog_review_queue WHERE tenant_id=:tenant_id AND tenant_song_id=:song_id AND inactivated_on IS NULL AND inactivated_by IS NULL');
        $existing->execute(['tenant_id' => $this->tenantId, 'song_id' => $songId]);
        $reviewId = $existing->fetchColumn();
        if ($reviewId !== false) {
            $stmt = $this->db->prepare("UPDATE lw_control.catalog_review_queue SET title=:title, writer=:writer, content_hash=:hash, content_snapshot=CAST(:snapshot AS jsonb), source_state='tenant_active', submitted_by=COALESCE(submitted_by, :by) WHERE id=:id AND review_state='pending'");
            $stmt->execute(['title' => $song['title'], 'writer' => $song['writer'], 'hash' => $song['source_content_hash'], 'snapshot' => $encoded, 'by' => $this->actorId, 'id' => $reviewId]);
            return;
        }
        $stmt = $this->db->prepare("INSERT INTO lw_control.catalog_review_queue (id, tenant_id, tenant_song_id, title, writer, content_hash, content_snapshot, review_state, submitted_on, submitted_by, source_state, activated_on, activated_by) VALUES (:id, :tenant_id, :song_id, :title, :writer, :hash, CAST(:snapshot AS jsonb), 'pending', now(), :by, 'tenant_active', now(), :by)");
        $stmt->execute(['id' => TenantNames::uuid(), 'tenant_id' => $this->tenantId, 'song_id' => $songId, 'title' => $song['title'], 'writer' => $song['writer'], 'hash' => $song['source_content_hash'], 'snapshot' => $encoded, 'by' => $this->actorId]);
    }

    private function masterSong(string $id): array
    {
        $id = $this->uuid($id);
        $stmt = $this->db->prepare("SELECT s.id, s.title, s.writer, s.original_key, v.id AS master_version_id, v.source_key AS default_key, v.lyrics, v.sections, v.content_hash FROM lw_master.songs s JOIN lw_master.song_versions v ON v.id=s.current_version_id AND v.inactivated_on IS NULL AND v.inactivated_by IS NULL WHERE s.id=:id AND s.inactivated_on IS NULL AND s.inactivated_by IS NULL");
        $stmt->execute(['id' => $id]);
        $song = $stmt->fetch();
        if (!$song) throw new ApiError(404, 'Master catalog song not found.');
        $song['sections'] = $this->decodeSections($song['sections']);
        return $song;
    }

    private function archiveExpired(): void
    {
        $this->db->prepare("UPDATE {$this->schema}.setlists SET inactivated_on=now(), inactivated_by=:by WHERE inactivated_on IS NULL AND inactivated_by IS NULL AND service_at + interval '6 hours' <= now()")->execute(['by' => $this->actorId]);
    }

    private function memberById(string $id): array
    {
        $id = $this->uuid($id);
        $stmt = $this->db->prepare("SELECT m.id, m.actor_id, a.external_subject AS identity_id, m.role, (m.inactivated_on IS NULL AND m.inactivated_by IS NULL) AS active, COALESCE(NULLIF(trim(a.display_name), ''), a.external_subject) AS username, 'mainzware' AS auth_type, m.activated_on AS created_at FROM {$this->schema}.members m JOIN lw_control.actors a ON a.id=m.actor_id WHERE m.id=:id");
        $stmt->execute(['id' => $id]);
        $member = $stmt->fetch();
        if (!$member) throw new ApiError(404, 'Live Worship member not found.');
        $member['active'] = $this->truthy($member['active']);
        return $member;
    }

    private function ensureAnotherLeader(string $excludingId): void
    {
        $stmt = $this->db->prepare("SELECT count(*) FROM {$this->schema}.members WHERE inactivated_on IS NULL AND inactivated_by IS NULL AND role IN ('owner', 'leader') AND id<>:id");
        $stmt->execute(['id' => $excludingId]);
        if ((int) $stmt->fetchColumn() === 0) throw new ApiError(400, 'Keep at least one active worship leader.');
    }

    private function requireLeader(): void
    {
        if ($this->context['support_mode'] ?? false) return;
        if (!in_array($this->context['role'] ?? '', ['owner', 'leader'], true)) throw new ApiError(403, 'Worship leader access is required for this action.');
    }

    private function requireFeature(string $feature): void
    {
        if (!in_array($feature, $this->context['features'] ?? [], true)) throw new ApiError(402, 'This Live Worship feature is not included in the team plan.');
    }

    private function decodeSections(mixed $sections): array
    {
        if (is_array($sections)) return $sections;
        $decoded = json_decode((string) $sections, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function date(mixed $value): string
    {
        if (trim((string) $value) === '') throw new ApiError(400, 'Enter a service date and time.');
        try { return (new \DateTimeImmutable((string) $value))->format('c'); }
        catch (Throwable) { throw new ApiError(400, 'Enter a valid service date and time.'); }
    }

    private function body(): array
    {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($body)) throw new ApiError(400, 'Request body must be a JSON object.');
        return $body;
    }

    private function uuid(string $value): string
    {
        $value = strtolower(trim($value));
        if (!TenantNames::validUuid($value)) throw new ApiError(400, 'The requested tenant record ID is invalid.');
        return $value;
    }

    private function quoteLiteral(string $value): string
    {
        return $this->db->quote($this->uuid($value));
    }

    private function identifier(string $value): string
    {
        if (!preg_match('/^lw_t_[0-9a-f]+$/', $value)) throw new ApiError(500, 'Tenant schema is invalid.');
        return '"' . $value . '"';
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
