<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;
use Throwable;

final class Api
{
    private PDO $db;
    private array $member;

    public static function dispatch(string $method, string $path): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $api = new self();
            $api->db = Database::connection();
            $normalizedPath = rtrim(preg_replace('#^/api/v1/live-worship#', '', parse_url($path, PHP_URL_PATH) ?: '/'), '/') ?: '/';
            if ($api->authRoute($method, $normalizedPath)) return;
            $tenantContext = $api->selectedTenantContext();
            if ($tenantContext !== null) {
                TenantRuntime::dispatch($api->db, $tenantContext, $method, $path);
                return;
            }
            $api->member = Access::member();
            $api->route($method, $path);
        } catch (ApiError $error) {
            http_response_code($error->status);
            echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            error_log('Live Worship API error: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Live Worship could not complete the request.'], JSON_THROW_ON_ERROR);
        }
    }

    private function authRoute(string $method, string $path): bool
    {
        if (str_starts_with($path, '/admin')) { AdminApi::dispatch($this->db, $method, $path); return true; }
        if ($method === 'POST' && $path === '/onboarding/slug-preview') { $this->previewTeamSlug(); return true; }
        if ($method === 'POST' && $path === '/onboarding/teams/join') { $this->joinTeam(); return true; }
        if ($method === 'GET' && $path === '/onboarding/teams') { $this->listTeams(); return true; }
        if ($method === 'POST' && $path === '/onboarding/teams') { $this->createTeam(); return true; }
        if ($method === 'POST' && $path === '/context') { $this->selectTenantContext(); return true; }
        if ($method === 'GET' && $path === '/context') { $this->showTenantContext(); return true; }
        if ($method === 'DELETE' && $path === '/context') { $this->clearTenantContext(); return true; }
        if ($method === 'POST' && $path === '/auth/login') { $this->login(); return true; }
        if ($method === 'POST' && $path === '/auth/logout') { $this->logout(); return true; }
        if ($method === 'POST' && $path === '/auth/password') { $this->changePassword(); return true; }
        if ($method === 'POST' && $path === '/auth/mainzware') { $this->useMainzWareSession(); return true; }
        return false;
    }

    private function previewTeamSlug(): void
    {
        $displayName = (string) ($this->body()['display_name'] ?? '');
        try {
            $slug = TenantNames::slugFromDisplayName($displayName);
        } catch (\InvalidArgumentException $error) {
            throw new ApiError(422, $error->getMessage());
        }

        // This endpoint deliberately does not check the registry. It previews
        // deterministic translation without revealing whether a tenant exists;
        // creation performs the authoritative active-slug collision check.
        $this->respond([
            'display_name' => trim($displayName),
            'slug' => $slug,
        ]);
    }

    private function createTeam(): void
    {
        if (($_SESSION['live_worship_auth_mode'] ?? '') === 'standalone') {
            throw new ApiError(401, 'Sign in with a MainzWare account to create a team.');
        }
        $identity = \MainzWorld\Support\Auth::currentUser();
        if ($identity === null) throw new ApiError(401, 'Sign in to MainzWare to create a team.');
        $displayName = (string) ($this->body()['display_name'] ?? '');
        try {
            // Validate the submitted name before creating any identity mapping;
            // TenantProvisioner derives it again inside the reservation.
            TenantNames::slugFromDisplayName($displayName);
            $actorId = ActorIdentity::ensureMainzWareActor($this->db, $identity);
            $team = TenantProvisioner::request($this->db, $displayName, $actorId);
        } catch (TenantConflict $error) {
            throw new ApiError(409, $error->getMessage());
        } catch (\InvalidArgumentException $error) {
            throw new ApiError(422, $error->getMessage());
        }
        $this->respond($team, 202);
    }

    private function joinTeam(): void
    {
        $identity = $this->mainzWareIdentity();
        $body = $this->body();
        $code = trim((string) ($body['code'] ?? ''));
        try {
            $actorId = ActorIdentity::ensureMainzWareActor($this->db, $identity);
            $joined = TenantInvitations::accept($this->db, $code, $actorId);
        } catch (TenantInvitationError $error) {
            throw new ApiError($error->status, $error->getMessage());
        }
        $_SESSION['live_worship_tenant_id'] = $joined['tenant_id'];
        $this->respond($joined, 201);
    }

    private function listTeams(): void
    {
        $identity = $this->mainzWareIdentity();
        $actorId = ActorIdentity::ensureMainzWareActor($this->db, $identity);
        $this->respond(TenantMembershipDirectory::forActor($this->db, $actorId));
    }

    private function selectTenantContext(): void
    {
        $identity = $this->mainzWareIdentity();
        $actorId = ActorIdentity::ensureMainzWareActor($this->db, $identity);
        $slug = (string) ($this->body()['slug'] ?? '');
        try {
            $context = TenantContext::select($this->db, $identity, $slug);
        } catch (TenantContextError $error) {
            $this->recordLoginActivity($actorId, null, 'tenant_context', 'failure', 'mainzworld', null, 'context_rejected');
            throw new ApiError($error->status, $error->getMessage());
        }
        $_SESSION['live_worship_tenant_id'] = $context['tenant_id'];
        $this->recordLoginActivity($actorId, (string) $context['tenant_id'], 'tenant_context', 'success', 'mainzworld', $identity['username'] ?? null);
        $this->respond($context);
    }

    private function showTenantContext(): void
    {
        $identity = $this->mainzWareIdentity();
        try {
            $context = TenantContext::current($this->db, $identity);
        } catch (TenantContextError $error) {
            throw new ApiError($error->status, $error->getMessage());
        }
        $this->respond($context);
    }

    private function clearTenantContext(): void
    {
        unset($_SESSION['live_worship_tenant_id']);
        $this->respond(['ok' => true]);
    }

    private function mainzWareIdentity(): array
    {
        if (($_SESSION['live_worship_auth_mode'] ?? '') === 'standalone') {
            throw new ApiError(401, 'Sign in with a MainzWare account to select a team.');
        }
        $identity = \MainzWorld\Support\Auth::currentUser();
        if ($identity === null) throw new ApiError(401, 'Sign in to MainzWare to select a team.');
        return $identity;
    }

    private function selectedTenantContext(): ?array
    {
        if (($_SESSION['live_worship_auth_mode'] ?? '') === 'standalone') return null;
        $selected = trim((string) ($_SERVER['HTTP_X_LIVE_WORSHIP_TENANT_ID'] ?? $_SESSION['live_worship_tenant_id'] ?? ''));
        if ($selected === '') return null;
        try {
            return TenantContext::current($this->db, $this->mainzWareIdentity());
        } catch (TenantContextError $error) {
            throw new ApiError($error->status, $error->getMessage());
        }
    }

    private function login(): void
    {
        $body = $this->body();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($username === '' || $password === '') {
            $this->recordLoginActivity(null, null, 'login', 'failure', 'standalone', null, 'missing_credentials');
            throw new ApiError(400, 'Enter your username and password.');
        }

        $stmt = $this->db->prepare(
            'SELECT a.id, a.username, a.password_hash
               FROM live_worship.standalone_accounts a
               JOIN live_worship.members m
                 ON m.standalone_account_id = a.id
                AND m.inactivated_on IS NULL AND m.inactivated_by IS NULL
              WHERE lower(a.username) = lower(:username)
                AND a.inactivated_on IS NULL AND a.inactivated_by IS NULL'
        );
        $stmt->execute(['username' => $username]);
        $account = $stmt->fetch();
        if (!$account || !password_verify($password, $account['password_hash'])) {
            $this->recordLoginActivity(null, null, 'login', 'failure', 'standalone', null, 'invalid_credentials');
            throw new ApiError(401, 'Invalid username or password.');
        }

        session_regenerate_id(true);
        $_SESSION['live_worship_auth_mode'] = 'standalone';
        $_SESSION['live_worship_account_id'] = (int) $account['id'];
        $this->recordLoginActivity(null, null, 'login', 'success', 'standalone', $account['username']);
        $this->respond(['username' => $account['username']]);
    }

    private function logout(): void
    {
        $supportSessionId = trim((string) ($_SESSION['live_worship_support_session_id'] ?? ''));
        if ($supportSessionId !== '') {
            try {
                $identity = \MainzWorld\Support\Auth::currentUser();
                if ($identity !== null) {
                    $actorId = ActorIdentity::ensureMainzWareActor($this->db, $identity);
                    SupportSessions::end($this->db, $actorId, $supportSessionId);
                }
            } catch (Throwable $error) {
                // Logout must still clear the browser context if the support
                // record was already expired or the identity session ended.
                error_log('Live Worship support session cleanup during logout: ' . $error->getMessage());
            }
        }
        unset($_SESSION['live_worship_account_id'], $_SESSION['live_worship_tenant_id'], $_SESSION['live_worship_support_session_id']);
        $_SESSION['live_worship_auth_mode'] = 'signed_out';
        $this->respond(['ok' => true]);
    }

    private function useMainzWareSession(): void
    {
        if (\MainzWorld\Support\Auth::currentUser() === null) throw new ApiError(401, 'Sign in to MainzWare first.');
        unset($_SESSION['live_worship_account_id'], $_SESSION['live_worship_auth_mode'], $_SESSION['live_worship_tenant_id']);
        $this->respond(['ok' => true]);
    }

    private function changePassword(): void
    {
        if (($_SESSION['live_worship_auth_mode'] ?? '') !== 'standalone') throw new ApiError(400, 'Change your MainzWare password from your MainzWare account.');
        $accountId = (int) ($_SESSION['live_worship_account_id'] ?? 0);
        if ($accountId < 1) throw new ApiError(401, 'Sign in to Live Worship.');
        $body = $this->body();
        $current = (string) ($body['current_password'] ?? '');
        $next = (string) ($body['new_password'] ?? '');
        if (strlen($next) < 8) throw new ApiError(400, 'Your new password must be at least 8 characters.');

        $stmt = $this->db->prepare('SELECT password_hash FROM live_worship.standalone_accounts WHERE id = :id AND inactivated_on IS NULL AND inactivated_by IS NULL');
        $stmt->execute(['id' => $accountId]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($current, (string) $hash)) throw new ApiError(400, 'Your current password is incorrect.');
        $update = $this->db->prepare('UPDATE live_worship.standalone_accounts SET password_hash = :hash, updated_at = now() WHERE id = :id');
        $update->execute(['hash' => password_hash($next, PASSWORD_DEFAULT), 'id' => $accountId]);
        $this->respond(['ok' => true]);
    }

    private function route(string $method, string $path): void
    {
        $path = preg_replace('#^/api/v1/live-worship#', '', parse_url($path, PHP_URL_PATH) ?: '/');
        $path = rtrim($path, '/') ?: '/';

        if ($method === 'GET' && $path === '/me') { $this->respond($this->member); return; }
        if ($method === 'PATCH' && $path === '/me/view-mode') { $this->saveViewMode(); return; }
        if ($method === 'PATCH' && $path === '/me/theme') { $this->saveTheme(); return; }
        if ($method === 'GET' && $path === '/settings') { $this->settings(); return; }
        if ($method === 'PUT' && $path === '/settings') { $this->updateSettings(); return; }
        if ($method === 'POST' && $path === '/settings/logo') { $this->uploadLogo(); return; }
        if ($method === 'DELETE' && $path === '/settings/logo') { $this->deleteLogo(); return; }
        if ($method === 'GET' && $path === '/branding/logo') { $this->showLogo(); return; }
        if ($method === 'GET' && $path === '/storage') { $this->storage(); return; }
        if ($method === 'GET' && $path === '/live') { $this->currentLive(); return; }
        if ($method === 'GET' && $path === '/members') { $this->members(); return; }
        if ($method === 'POST' && $path === '/members') { $this->addMember(); return; }
        if ($method === 'PUT' && preg_match('#^/members/(\d+)$#', $path, $m)) { $this->updateMember((int) $m[1]); return; }
        if ($method === 'DELETE' && preg_match('#^/members/(\d+)$#', $path, $m)) { $this->removeMember((int) $m[1]); return; }
        if ($method === 'POST' && $path === '/songs/ocr') { $this->recognizeSongPage(); return; }
        if ($method === 'GET' && $path === '/songs') { $this->songs(); return; }
        if ($method === 'POST' && $path === '/songs') { $this->createSong(); return; }
        if ($method === 'GET' && preg_match('#^/songs/(\d+)$#', $path, $m)) { $this->song((int) $m[1]); return; }
        if ($method === 'PATCH' && preg_match('#^/songs/(\d+)/key$#', $path, $m)) { $this->updateSong((int) $m[1], true); return; }
        if ($method === 'PUT' && preg_match('#^/songs/(\d+)$#', $path, $m)) { $this->updateSong((int) $m[1]); return; }
        if ($method === 'DELETE' && preg_match('#^/songs/(\d+)$#', $path, $m)) { $this->deleteSong((int) $m[1]); return; }
        if ($method === 'GET' && $path === '/setlists') { $this->setlists(); return; }
        if ($method === 'POST' && $path === '/setlists') { $this->createSetlist(); return; }
        if ($method === 'PUT' && preg_match('#^/setlists/(\d+)$#', $path, $m)) { $this->updateSetlist((int) $m[1]); return; }
        if ($method === 'DELETE' && preg_match('#^/setlists/(\d+)$#', $path, $m)) { $this->deleteSetlist((int) $m[1]); return; }
        if ($method === 'POST' && preg_match('#^/setlists/(\d+)/start$#', $path, $m)) { $this->startSetlist((int) $m[1]); return; }
        if ($method === 'GET' && preg_match('#^/setlists/(\d+)/state$#', $path, $m)) { $this->liveState((int) $m[1]); return; }
        if ($method === 'PATCH' && preg_match('#^/setlists/(\d+)/control$#', $path, $m)) { $this->updateControlMode((int) $m[1]); return; }
        if ($method === 'PUT' && preg_match('#^/setlists/(\d+)/state$#', $path, $m)) { $this->updateLiveState((int) $m[1]); return; }
        http_response_code(404);
        echo json_encode(['error' => 'Live Worship endpoint not found.']);
    }

    private function saveViewMode(): void
    {
        Access::leader($this->member);
        $mode = $this->body()['view_mode'] ?? null;
        if (!in_array($mode, ['choir', 'musician'], true)) throw new ApiError(400, 'Choose Choir or Musician view.');
        $stmt = $this->db->prepare('UPDATE live_worship.members SET view_mode=:mode WHERE id=:id');
        $stmt->execute(['mode' => $mode, 'id' => $this->member['id']]);
        $this->respond(['view_mode' => $mode]);
    }

    private function saveTheme(): void
    {
        $theme = $this->body()['theme'] ?? null;
        if (!in_array($theme, ['light', 'dark'], true)) throw new ApiError(400, 'Choose light or dark mode.');
        $stmt = $this->db->prepare('UPDATE live_worship.members SET theme=:theme WHERE id=:id');
        $stmt->execute(['theme' => $theme, 'id' => $this->member['id']]);
        $this->respond(['theme' => $theme]);
    }

    private function recognizeSongPage(): void
    {
        Access::leader($this->member);
        if (!isset($_FILES['image'])) throw new ApiError(400, 'Choose a song page photo.');
        $image = $_FILES['image'];
        Files::inspect($image);
        $bytes = @file_get_contents($image['tmp_name']);
        if ($bytes === false) throw new ApiError(400, 'The uploaded photo could not be read.');

        $payload = json_encode(['image' => base64_encode($bytes)], JSON_THROW_ON_ERROR);
        $url = trim((string) getenv('LIVE_WORSHIP_OCR_URL')) ?: 'http://127.0.0.1:8765/ocr';
        if (!function_exists('curl_init')) throw new ApiError(503, 'Photo text recognition is unavailable. You can still enter the lyrics manually.');
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 180,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($response)) throw new ApiError(503, 'Photo text recognition is unavailable. You can still enter the lyrics manually.');

        $result = json_decode($response, true);
        if ($status < 200 || $status >= 300 || !is_array($result) || !isset($result['lines']) || !is_array($result['lines'])) {
            throw new ApiError(503, 'Photo text recognition could not read that page. You can still enter the lyrics manually.');
        }
        $this->respond(['lines' => $result['lines']]);
    }

    private function settings(): void
    {
        $this->respond($this->settingsData());
    }

    private function updateSettings(): void
    {
        Access::leader($this->member);
        $body = $this->body();
        $name = trim((string) ($body['display_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) throw new ApiError(400, 'Choose a name between 1 and 80 characters.');
        $stmt = $this->db->prepare('UPDATE live_worship.settings SET display_name = :name, updated_at = now() WHERE id = TRUE AND inactivated_on IS NULL AND inactivated_by IS NULL');
        $stmt->execute(['name' => $name]);
        $this->respond($this->settingsData());
    }

    private function uploadLogo(): void
    {
        Access::leader($this->member);
        if (!isset($_FILES['logo'])) throw new ApiError(400, 'Choose an image to upload.');
        $stored = Files::save($_FILES['logo'], 'branding');
        try {
            $stmt = $this->db->query('SELECT logo_path FROM live_worship.settings WHERE id=TRUE AND inactivated_on IS NULL AND inactivated_by IS NULL');
            $previousPath = $stmt->fetchColumn() ?: null;
            $update = $this->db->prepare('UPDATE live_worship.settings SET logo_path=:path, logo_mime_type=:mime, logo_file_size_bytes=:size, updated_at=now() WHERE id=TRUE AND inactivated_on IS NULL AND inactivated_by IS NULL');
            $update->execute(['path' => $stored['path'], 'mime' => $stored['mime'], 'size' => $stored['size']]);
        } catch (Throwable $error) {
            Files::remove($stored['path']);
            throw $error;
        }
        if ($previousPath) Files::remove($previousPath);
        $this->respond($this->settingsData());
    }

    private function deleteLogo(): void
    {
        Access::leader($this->member);
        $previousPath = $this->db->query('SELECT logo_path FROM live_worship.settings WHERE id=TRUE AND inactivated_on IS NULL AND inactivated_by IS NULL')->fetchColumn() ?: null;
        $this->db->exec('UPDATE live_worship.settings SET logo_path=NULL, logo_mime_type=NULL, logo_file_size_bytes=NULL, updated_at=now() WHERE id=TRUE AND inactivated_on IS NULL AND inactivated_by IS NULL');
        if ($previousPath) Files::remove($previousPath);
        $this->respond($this->settingsData());
    }

    private function showLogo(): void
    {
        $row = $this->db->query('SELECT logo_path, logo_mime_type FROM live_worship.settings WHERE id=TRUE AND inactivated_on IS NULL AND inactivated_by IS NULL')->fetch();
        if (!$row || !$row['logo_path']) throw new ApiError(404, 'No team logo has been uploaded.');
        $path = Files::absolute($row['logo_path']);
        header('Content-Type: ' . $row['logo_mime_type']);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        readfile($path);
    }

    private function settingsData(): array
    {
        $row = $this->db->query('SELECT display_name, logo_path, updated_at FROM live_worship.settings WHERE id=TRUE AND inactivated_on IS NULL AND inactivated_by IS NULL')->fetch();
        $row['logo_url'] = $row['logo_path'] ? '/api/v1/live-worship/branding/logo?v=' . rawurlencode((string) $row['updated_at']) : null;
        unset($row['logo_path']);
        return $row;
    }

    private function storage(): void
    {
        Access::leader($this->member);
        $this->respond(['page_count' => 0, 'total_bytes' => 0, 'total_megabytes' => 0]);
    }

    private function currentLive(): void
    {
        $this->archiveExpired();
        $this->db->exec("UPDATE live_worship.live_state st
                            SET is_live=FALSE, revision=revision+1, updated_at=now()
                           FROM live_worship.setlists sl
                          WHERE st.setlist_id=sl.id
                            AND st.is_live=TRUE
                            AND (sl.inactivated_on IS NOT NULL OR sl.inactivated_by IS NOT NULL)");
        $row = $this->db->query(
            "SELECT l.setlist_id, s.name AS setlist_name, l.song_id, l.section_id,
                    l.control_mode, l.controller_member_id, l.revision, l.updated_at
               FROM live_worship.live_state l JOIN live_worship.setlists s ON s.id=l.setlist_id
              WHERE l.is_live=TRUE
                AND l.inactivated_on IS NULL AND l.inactivated_by IS NULL
                AND s.inactivated_on IS NULL AND s.inactivated_by IS NULL
              LIMIT 1"
        )->fetch();
        if (!$row) { $this->respond(null); return; }
        $row['setlist_id'] = (int) $row['setlist_id'];
        $row['song_id'] = $row['song_id'] === null ? null : (int) $row['song_id'];
        $row['controller_member_id'] = $row['controller_member_id'] === null ? null : (int) $row['controller_member_id'];
        $row['revision'] = (int) $row['revision'];
        $this->respond($row);
    }

    private function members(): void
    {
        Access::leader($this->member);
        $rows = $this->db->query(
            "SELECT m.id, m.identity_id, m.standalone_account_id, m.role,
                    (m.inactivated_on IS NULL AND m.inactivated_by IS NULL) AS active,
                    COALESCE(u.username, a.username) AS username,
                    CASE WHEN m.standalone_account_id IS NULL THEN 'mainzware' ELSE 'live_worship' END AS auth_type,
                    m.created_at
               FROM live_worship.members m
               LEFT JOIN auth.users u ON u.id = m.identity_id
               LEFT JOIN live_worship.standalone_accounts a ON a.id = m.standalone_account_id
              ORDER BY (m.inactivated_on IS NULL AND m.inactivated_by IS NULL) DESC,
                       COALESCE(u.username, a.username)"
        )->fetchAll();
        foreach ($rows as &$row) $row['active'] = in_array($row['active'], [true, 't', '1', 1], true);
        $this->respond($rows);
    }

    private function addMember(): void
    {
        Access::leader($this->member);
        $body = $this->body();
        $username = trim((string) ($body['username'] ?? ''));
        $role = (string) ($body['role'] ?? '');
        $authType = (string) ($body['auth_type'] ?? 'mainzware');
        if ($username === '' || mb_strlen($username) > 80 || !in_array($role, ['leader', 'choir', 'musician'], true) || !in_array($authType, ['mainzware', 'live_worship'], true)) {
            throw new ApiError(400, 'Enter a username and choose a valid account type and Live Worship role.');
        }

        if ($authType === 'mainzware') {
            $userQuery = $this->db->prepare('SELECT id FROM auth.users WHERE username = :username AND is_active = TRUE');
            $userQuery->execute(['username' => $username]);
            $identityId = $userQuery->fetchColumn();
            if (!$identityId) throw new ApiError(404, 'No active MainzWare account has that username.');
            $insert = $this->db->prepare(
                'INSERT INTO live_worship.members
                    (identity_id, role, activated_on, activated_by, inactivated_on, inactivated_by)
                 VALUES (:identity_id, :role, now(), :activated_by, NULL, NULL)
                 ON CONFLICT (identity_id) DO UPDATE
                    SET role = EXCLUDED.role,
                        activated_on = now(), activated_by = EXCLUDED.activated_by,
                        inactivated_on = NULL, inactivated_by = NULL
                 RETURNING id, identity_id, role,
                           (inactivated_on IS NULL AND inactivated_by IS NULL) AS active'
            );
            $insert->execute(['identity_id' => (int) $identityId, 'role' => $role, 'activated_by' => $this->member['id']]);
            $this->respond($insert->fetch(), 201);
            return;
        }

        $password = (string) ($body['password'] ?? '');
        if (strlen($password) < 8) throw new ApiError(400, 'Set an initial password of at least 8 characters.');
        $findAccount = $this->db->prepare('SELECT id FROM live_worship.standalone_accounts WHERE lower(username) = lower(:username)');
        $findAccount->execute(['username' => $username]);
        $accountId = $findAccount->fetchColumn();
        if ($accountId) {
            $updateAccount = $this->db->prepare('UPDATE live_worship.standalone_accounts SET password_hash = :hash, updated_at = now(), inactivated_on = NULL, inactivated_by = NULL, activated_on = now(), activated_by = :activated_by WHERE id = :id');
            $updateAccount->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int) $accountId, 'activated_by' => $this->member['id']]);
        } else {
            $createAccount = $this->db->prepare('INSERT INTO live_worship.standalone_accounts (username, password_hash, activated_on, activated_by) VALUES (:username, :hash, now(), :activated_by) RETURNING id');
            $createAccount->execute(['username' => $username, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'activated_by' => $this->member['id']]);
            $accountId = $createAccount->fetchColumn();
        }
        $existingMember = $this->db->prepare('SELECT id FROM live_worship.members WHERE standalone_account_id = :account_id');
        $existingMember->execute(['account_id' => (int) $accountId]);
        $memberId = $existingMember->fetchColumn();
        if ($memberId) {
            $saveMember = $this->db->prepare('UPDATE live_worship.members SET role = :role, activated_on = now(), activated_by = :activated_by, inactivated_on = NULL, inactivated_by = NULL WHERE id = :id RETURNING id, standalone_account_id, role, (inactivated_on IS NULL AND inactivated_by IS NULL) AS active');
            $saveMember->execute(['role' => $role, 'id' => (int) $memberId, 'activated_by' => $this->member['id']]);
        } else {
            $saveMember = $this->db->prepare('INSERT INTO live_worship.members (standalone_account_id, role, activated_on, activated_by) VALUES (:account_id, :role, now(), :activated_by) RETURNING id, standalone_account_id, role, (inactivated_on IS NULL AND inactivated_by IS NULL) AS active');
            $saveMember->execute(['account_id' => (int) $accountId, 'role' => $role, 'activated_by' => $this->member['id']]);
        }
        $this->respond($saveMember->fetch(), 201);
    }

    private function updateMember(int $id): void
    {
        Access::leader($this->member);
        $body = $this->body();
        $role = (string) ($body['role'] ?? '');
        if (!in_array($role, ['leader', 'choir', 'musician'], true)) throw new ApiError(400, 'Choose leader, choir, or musician.');
        $existing = $this->memberById($id);
        if ($existing['role'] === 'leader' && $role !== 'leader') $this->ensureAnotherLeader($id);
        $stmt = $this->db->prepare('UPDATE live_worship.members SET role = :role WHERE id = :id RETURNING id, identity_id, role, (inactivated_on IS NULL AND inactivated_by IS NULL) AS active');
        $stmt->execute(['role' => $role, 'id' => $id]);
        $this->respond($stmt->fetch());
    }

    private function removeMember(int $id): void
    {
        Access::leader($this->member);
        $existing = $this->memberById($id);
        if ((int) $existing['id'] === $this->member['id']) throw new ApiError(400, 'You cannot remove your own Live Worship access.');
        if ($existing['role'] === 'leader') $this->ensureAnotherLeader($id);
        $stmt = $this->db->prepare('UPDATE live_worship.members SET inactivated_on = now(), inactivated_by = :inactivated_by WHERE id = :id AND inactivated_on IS NULL AND inactivated_by IS NULL');
        $stmt->execute(['id' => $id, 'inactivated_by' => $this->member['id']]);
        $this->respond(['ok' => true]);
    }

    private function songs(): void
    {
        $rows = $this->db->query('SELECT id FROM live_worship.songs WHERE inactivated_on IS NULL AND inactivated_by IS NULL ORDER BY title')->fetchAll(PDO::FETCH_COLUMN);
        $songs = array_map(fn($id) => $this->songData((int) $id), $rows);
        $this->respond($songs);
    }

    private function song(int $id): void { $this->respond($this->songData($id)); }

    private function createSong(): void
    {
        Access::leader($this->member);
        $body = $this->body();
        $fields = $this->songFields($body);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO live_worship.songs
                    (title, writer, default_key, original_key, lyrics, sections, ocr_text,
                     created_by, activated_on, activated_by)
                 VALUES (:title, :writer, :song_key, :original_key, :lyrics,
                         CAST(:sections AS jsonb), :ocr_text, :created_by, now(), :activated_by)
                 RETURNING id'
            );
            $stmt->execute($fields + [
                'original_key' => $fields['song_key'],
                'created_by' => $this->member['id'],
                'activated_by' => $this->member['id'],
            ]);
            $id = (int) $stmt->fetchColumn();
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollBack(); throw $error; }
        $this->respond($this->songData($id), 201);
    }

    private function updateSong(int $id, bool $keyOnly = false): void
    {
        Access::leader($this->member);
        $body = $this->body();
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM live_worship.songs WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL FOR UPDATE');
            $lock->execute(['id' => $id]);
            $current = $this->songData($id);
            if ($keyOnly) {
                $key = trim((string) ($body['default_key'] ?? ''));
                if ($key === '') throw new ApiError(400, 'Choose a key.');
                $body = array_replace($current, ['default_key' => $key]);
            }
            $fields = $this->songFields($body);
            $sections = Transpose::sections(json_decode($fields['sections'], true, 512, JSON_THROW_ON_ERROR), $current['default_key'], $fields['song_key']);
            $fields['sections'] = json_encode($sections, JSON_THROW_ON_ERROR);
            $stmt = $this->db->prepare(
                'UPDATE live_worship.songs SET title=:title, writer=:writer, default_key=:song_key,
                 original_key=COALESCE(original_key, :original_key), lyrics=:lyrics,
                 sections=CAST(:sections AS jsonb), ocr_text=:ocr_text, updated_at=now() WHERE id=:id'
            );
            $stmt->execute($fields + ['original_key' => $current['default_key'] ?: $fields['song_key'], 'id' => $id]);
            $sectionIds = array_column($sections, 'id');
            $live = $this->db->prepare('SELECT section_id FROM live_worship.live_state WHERE song_id=:id AND is_live=TRUE AND inactivated_on IS NULL AND inactivated_by IS NULL');
            $live->execute(['id' => $id]);
            $liveSection = $live->fetchColumn();
            $fix = $this->db->prepare('UPDATE live_worship.live_state SET section_id=:section, revision=revision+1, updated_at=now() WHERE song_id=:id AND is_live=TRUE AND inactivated_on IS NULL AND inactivated_by IS NULL');
            $fix->execute(['section' => $liveSection === null || in_array($liveSection, $sectionIds, true) ? $liveSection : ($sectionIds[0] ?? null), 'id' => $id]);
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollBack(); throw $error; }
        $this->respond($this->songData($id));
    }

    private function deleteSong(int $id): void
    {
        Access::leader($this->member);
        $this->db->beginTransaction();
            $this->db->prepare('UPDATE live_worship.live_state SET song_id=NULL, section_id=NULL, revision=revision+1, updated_at=now() WHERE song_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL')->execute(['id' => $id]);
        $this->db->prepare(
            'UPDATE live_worship.setlist_songs
                SET inactivated_on=now(), inactivated_by=:inactivated_by
              WHERE song_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL'
        )->execute(['id' => $id, 'inactivated_by' => $this->member['id']]);
        $stmt = $this->db->prepare(
            'UPDATE live_worship.songs
                SET inactivated_on=now(), inactivated_by=:inactivated_by, updated_at=now()
              WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL'
        );
        $stmt->execute(['id' => $id, 'inactivated_by' => $this->member['id']]);
        if ($stmt->rowCount() === 0) { $this->db->rollBack(); throw new ApiError(404, 'Song not found.'); }
        $this->db->commit();
        $this->respond(['ok' => true]);
    }

    private function setlists(): void
    {
        $this->archiveExpired();
        $archived = ($_GET['status'] ?? 'active') === 'archived';
        $order = $archived ? 'DESC' : 'ASC';
        $where = $archived
            ? 'inactivated_on IS NOT NULL AND inactivated_by IS NOT NULL'
            : 'inactivated_on IS NULL AND inactivated_by IS NULL';
        $stmt = $this->db->query("SELECT id FROM live_worship.setlists WHERE {$where} ORDER BY service_at {$order}");
        $rows = array_map(fn($id) => $this->setlistData((int) $id), $stmt->fetchAll(PDO::FETCH_COLUMN));
        $this->respond($rows);
    }

    private function createSetlist(): void
    {
        Access::leader($this->member);
        $body = $this->body();
        $name = trim((string) ($body['name'] ?? ''));
        $time = $this->date($body['service_at'] ?? '');
        $songIds = $this->songIds($body['song_ids'] ?? null);
        if ($name === '' || mb_strlen($name) > 100) throw new ApiError(400, 'Choose a service name up to 100 characters.');
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO live_worship.setlists (name, service_at, created_by, activated_on, activated_by) VALUES (:name, :service_at, :member, now(), :activated_by) RETURNING id');
            $stmt->execute(['name' => $name, 'service_at' => $time, 'member' => $this->member['id'], 'activated_by' => $this->member['id']]);
            $id = (int) $stmt->fetchColumn();
            if ((new \DateTimeImmutable($time)) <= new \DateTimeImmutable('-6 hours')) {
                $this->db->prepare('UPDATE live_worship.setlists SET inactivated_on=now(), inactivated_by=:inactivated_by WHERE id=:id')
                    ->execute(['id' => $id, 'inactivated_by' => $this->member['id']]);
            }
            $this->replaceSetlistSongs($id, $songIds);
            $this->db->prepare('INSERT INTO live_worship.live_state (setlist_id, activated_on, activated_by) VALUES (:id, now(), :activated_by)')->execute(['id' => $id, 'activated_by' => $this->member['id']]);
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollBack(); throw $error; }
        $this->respond($this->setlistData($id), 201);
    }

    private function updateSetlist(int $id): void
    {
        Access::leader($this->member);
        $this->setlistData($id);
        $body = $this->body();
        $name = trim((string) ($body['name'] ?? ''));
        $time = $this->date($body['service_at'] ?? '');
        $songIds = $this->songIds($body['song_ids'] ?? null);
        if ($name === '' || mb_strlen($name) > 100) throw new ApiError(400, 'Choose a service name up to 100 characters.');
        $this->db->beginTransaction();
        try {
            $archived = (new \DateTimeImmutable($time)) <= new \DateTimeImmutable('-6 hours');
            if ($archived) {
                $stmt = $this->db->prepare(
                    'UPDATE live_worship.setlists
                        SET name=:name, service_at=:service_at,
                            inactivated_on=COALESCE(inactivated_on, now()),
                            inactivated_by=COALESCE(inactivated_by, :inactivated_by)
                      WHERE id=:id'
                );
                $stmt->execute(['name' => $name, 'service_at' => $time, 'inactivated_by' => $this->member['id'], 'id' => $id]);
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE live_worship.setlists
                        SET name=:name, service_at=:service_at,
                            activated_on=now(), activated_by=:activated_by,
                            inactivated_on=NULL, inactivated_by=NULL
                      WHERE id=:id'
                );
                $stmt->execute(['name' => $name, 'service_at' => $time, 'activated_by' => $this->member['id'], 'id' => $id]);
            }
            $this->replaceSetlistSongs($id, $songIds);
            $this->db->prepare(
                'UPDATE live_worship.live_state SET song_id=NULL, section_id=NULL, revision=revision+1, updated_at=now()
                  WHERE setlist_id=:state_setlist
                    AND inactivated_on IS NULL AND inactivated_by IS NULL
                    AND song_id IS NOT NULL
                    AND song_id NOT IN (
                        SELECT song_id FROM live_worship.setlist_songs
                         WHERE setlist_id=:songs_setlist
                           AND inactivated_on IS NULL AND inactivated_by IS NULL
                    )'
            )->execute(['state_setlist' => $id, 'songs_setlist' => $id]);
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollBack(); throw $error; }
        $this->respond($this->setlistData($id));
    }

    private function deleteSetlist(int $id): void
    {
        Access::leader($this->member);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE live_worship.setlists SET inactivated_on=now(), inactivated_by=:inactivated_by WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL');
            $stmt->execute(['id' => $id, 'inactivated_by' => $this->member['id']]);
            if (!$stmt->rowCount()) throw new ApiError(404, 'Set list not found.');
            $this->db->prepare('UPDATE live_worship.setlist_songs SET inactivated_on=now(), inactivated_by=:inactivated_by WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL')->execute(['id' => $id, 'inactivated_by' => $this->member['id']]);
            $this->db->prepare('UPDATE live_worship.live_state SET inactivated_on=now(), inactivated_by=:inactivated_by, is_live=FALSE WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL')->execute(['id' => $id, 'inactivated_by' => $this->member['id']]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->respond(['ok' => true]);
    }

    private function startSetlist(int $id): void
    {
        Access::leader($this->member);
        $this->archiveExpired();
        $setlist = $this->setlistData($id);
        if ($setlist['status'] !== 'active') throw new ApiError(409, 'Archived services cannot be started.');
        $this->db->beginTransaction();
        $this->db->exec('UPDATE live_worship.live_state SET is_live=FALSE, revision=revision+1, updated_at=now() WHERE is_live=TRUE AND inactivated_on IS NULL AND inactivated_by IS NULL');
        $stmt = $this->db->prepare("UPDATE live_worship.live_state
                                      SET is_live=TRUE, control_mode='manual', controller_member_id=NULL, controller_token=NULL,
                                          revision=revision+1, updated_at=now()
                                    WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL");
        $stmt->execute(['id' => $id]);
        $this->db->commit();
        $this->respond($this->setlistData($id));
    }

    private function liveState(int $setlistId): void
    {
        $this->setlistData($setlistId);
        $stmt = $this->db->prepare('SELECT setlist_id, song_id, section_id, control_mode, controller_member_id, revision, updated_at FROM live_worship.live_state WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL');
        $stmt->execute(['id' => $setlistId]);
        $state = $stmt->fetch();
        if ($state) {
            $state['setlist_id'] = (int) $state['setlist_id'];
            $state['song_id'] = $state['song_id'] === null ? null : (int) $state['song_id'];
            $state['controller_member_id'] = $state['controller_member_id'] === null ? null : (int) $state['controller_member_id'];
            $state['revision'] = (int) $state['revision'];
        }
        $this->respond($state);
    }

    private function updateControlMode(int $setlistId): void
    {
        Access::leader($this->member);
        $setlist = $this->setlistData($setlistId);
        if ($setlist['status'] !== 'active') throw new ApiError(409, 'Archived services cannot be controlled live.');
        $body = $this->body();
        $mode = (string) ($body['control_mode'] ?? '');
        $token = trim((string) ($body['controller_token'] ?? ''));
        if (!in_array($mode, ['manual', 'automatic'], true)) throw new ApiError(400, 'Choose manual or automatic live guidance.');
        if ($mode === 'automatic' && $token === '') throw new ApiError(400, 'A device token is required for automatic live guidance.');

        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT control_mode, controller_member_id, controller_token FROM live_worship.live_state WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL FOR UPDATE');
            $lock->execute(['id' => $setlistId]);
            $current = $lock->fetch();
            if (!$current) throw new ApiError(409, 'Start this service before enabling live guidance.');
            $owner = $current['controller_member_id'] === null ? null : (int) $current['controller_member_id'];
            $currentToken = $current['controller_token'] === null ? null : (string) $current['controller_token'];
            if ($mode === 'automatic' && (($owner !== null && $owner !== (int) $this->member['id']) || ($currentToken !== null && !hash_equals($currentToken, $token)))) {
                throw new ApiError(409, 'Another worship leader is already running automatic voice guidance.');
            }
            $controller = $mode === 'automatic' ? (int) $this->member['id'] : null;
            $controllerToken = $mode === 'automatic' ? $token : null;
            if ((string) $current['control_mode'] !== $mode || $owner !== $controller || $currentToken !== $controllerToken) {
                $update = $this->db->prepare(
                    'UPDATE live_worship.live_state
                        SET control_mode=:mode, controller_member_id=:controller, controller_token=:token,
                            revision=revision+1, updated_at=now()
                      WHERE setlist_id=:id'
                );
                $update->execute(['mode' => $mode, 'controller' => $controller, 'token' => $controllerToken, 'id' => $setlistId]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->liveState($setlistId);
    }

    private function updateLiveState(int $setlistId): void
    {
        Access::leader($this->member);
        $setlist = $this->setlistData($setlistId);
        if ($setlist['status'] !== 'active') throw new ApiError(409, 'Archived services cannot be controlled live.');
        $body = $this->body();
        $songId = isset($body['song_id']) ? (int) $body['song_id'] : null;
        $sectionId = isset($body['section_id']) ? (string) $body['section_id'] : null;
        $requestedMode = array_key_exists('control_mode', $body) ? (string) $body['control_mode'] : null;
        $requestedToken = trim((string) ($body['controller_token'] ?? ''));
        if ($requestedMode !== null && !in_array($requestedMode, ['manual', 'automatic'], true)) {
            throw new ApiError(400, 'Choose manual or automatic live guidance.');
        }
        if ($requestedMode === 'automatic' && $requestedToken === '') throw new ApiError(400, 'A device token is required for automatic live guidance.');
        if ($songId !== null) {
            $song = $this->songData($songId);
            if (!in_array($songId, array_column($setlist['songs'], 'id'), true)) throw new ApiError(400, 'Choose a song from this set list.');
            if ($sectionId !== null && !in_array($sectionId, array_column($song['sections'], 'id'), true)) throw new ApiError(400, 'Choose a part from this song.');
        } else { $sectionId = null; }
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT control_mode, controller_member_id, controller_token FROM live_worship.live_state WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL FOR UPDATE');
            $lock->execute(['id' => $setlistId]);
            $current = $lock->fetch();
            $currentMode = $current ? (string) $current['control_mode'] : 'manual';
            $currentOwner = $current && $current['controller_member_id'] !== null ? (int) $current['controller_member_id'] : null;
            $currentToken = $current && $current['controller_token'] !== null ? (string) $current['controller_token'] : null;
            $mode = $requestedMode ?? $currentMode;
            if ($mode === 'automatic' && (($currentOwner !== null && $currentOwner !== (int) $this->member['id']) || ($currentToken !== null && !hash_equals($currentToken, $requestedToken)))) {
                throw new ApiError(409, 'Another worship leader is already running automatic voice guidance.');
            }
            if ($mode === 'automatic' && $requestedToken === '') throw new ApiError(400, 'A device token is required for automatic live guidance.');
            $controller = $mode === 'automatic' ? ($currentOwner ?? (int) $this->member['id']) : null;
            $controllerToken = $mode === 'automatic' ? ($currentToken ?? $requestedToken) : null;
            $otherLive = $this->db->prepare('UPDATE live_worship.live_state SET is_live=FALSE, revision=revision+1, updated_at=now() WHERE is_live=TRUE AND inactivated_on IS NULL AND inactivated_by IS NULL AND setlist_id<>:id');
            $otherLive->execute(['id' => $setlistId]);
            $stmt = $this->db->prepare(
                'INSERT INTO live_worship.live_state
                    (setlist_id, song_id, section_id, control_mode, controller_member_id,
                     controller_token, is_live, activated_on, activated_by)
                 VALUES (:setlist, :song, :section, :mode, :controller, :token, TRUE, now(), :activated_by)
                 ON CONFLICT (setlist_id) DO UPDATE SET song_id=EXCLUDED.song_id, section_id=EXCLUDED.section_id,
                 control_mode=EXCLUDED.control_mode, controller_member_id=EXCLUDED.controller_member_id, controller_token=EXCLUDED.controller_token,
                 is_live=TRUE, revision=live_worship.live_state.revision + 1, updated_at=now(),
                 activated_on=now(), activated_by=EXCLUDED.activated_by,
                 inactivated_on=NULL, inactivated_by=NULL
                 WHERE live_worship.live_state.song_id IS DISTINCT FROM EXCLUDED.song_id
                    OR live_worship.live_state.section_id IS DISTINCT FROM EXCLUDED.section_id
                    OR live_worship.live_state.control_mode IS DISTINCT FROM EXCLUDED.control_mode
                    OR live_worship.live_state.controller_member_id IS DISTINCT FROM EXCLUDED.controller_member_id
                    OR live_worship.live_state.controller_token IS DISTINCT FROM EXCLUDED.controller_token
                    OR live_worship.live_state.is_live=FALSE
                 RETURNING setlist_id, song_id, section_id, control_mode, controller_member_id, revision, updated_at'
            );
            $stmt->execute(['setlist' => $setlistId, 'song' => $songId, 'section' => $sectionId, 'mode' => $mode, 'controller' => $controller, 'token' => $controllerToken, 'activated_by' => $this->member['id']]);
            $state = $stmt->fetch();
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollBack(); throw $error; }
        if (!$state) { $this->liveState($setlistId); return; }
        $state['setlist_id'] = (int) $state['setlist_id'];
        $state['song_id'] = $state['song_id'] === null ? null : (int) $state['song_id'];
        $state['controller_member_id'] = $state['controller_member_id'] === null ? null : (int) $state['controller_member_id'];
        $state['revision'] = (int) $state['revision'];
        $this->respond($state);
    }

    private function songData(int $id): array
    {
        $stmt = $this->db->prepare('SELECT id, title, writer, default_key, original_key, lyrics, sections, ocr_text, created_at, updated_at FROM live_worship.songs WHERE id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL');
        $stmt->execute(['id' => $id]);
        $song = $stmt->fetch();
        if (!$song) throw new ApiError(404, 'Song not found.');
        $song['id'] = (int) $song['id'];
        $song['sections'] = $this->normalizeChordPositions(json_decode($song['sections'], true, 512, JSON_THROW_ON_ERROR) ?: []);
        // Source images are transient OCR input and are never retained as song assets.
        $song['pages'] = [];
        return $song;
    }

    /**
     * Older imports can contain chord offsets measured against the source
     * chart rather than the cleaned lyric text. Keep those chords renderable
     * and make them safe to submit through the song update endpoint.
     */
    private function normalizeChordPositions(array $sections): array
    {
        foreach ($sections as &$section) {
            if (!is_array($section)) continue;
            $maxAt = mb_strlen((string) ($section['lyrics'] ?? ''));
            foreach ($section['chord_marks'] ?? [] as $index => $mark) {
                if (!is_array($mark) || !array_key_exists('at', $mark)) continue;
                $section['chord_marks'][$index]['at'] = max(0, min((int) $mark['at'], $maxAt));
            }
        }
        unset($section);
        return $sections;
    }

    private function setlistData(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.name, s.service_at,
                    CASE WHEN s.inactivated_on IS NULL AND s.inactivated_by IS NULL
                         THEN \'active\' ELSE \'archived\' END AS status,
                    s.created_at, coalesce(l.is_live, FALSE) AS current
               FROM live_worship.setlists s
               LEFT JOIN live_worship.live_state l
                 ON l.setlist_id=s.id
                AND l.inactivated_on IS NULL AND l.inactivated_by IS NULL
              WHERE s.id=:id'
        );
        $stmt->execute(['id' => $id]);
        $setlist = $stmt->fetch();
        if (!$setlist) throw new ApiError(404, 'Set list not found.');
        $setlist['id'] = (int) $setlist['id'];
        $setlist['current'] = in_array($setlist['current'], [true, 't', '1', 1], true);
        $songs = $this->db->prepare('SELECT song_id FROM live_worship.setlist_songs WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL ORDER BY position');
        $songs->execute(['id' => $id]);
        $setlist['songs'] = array_map(fn($songId) => $this->songData((int) $songId), $songs->fetchAll(PDO::FETCH_COLUMN));
        return $setlist;
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
            $marks = $section['chord_marks'] ?? [];
            if (!is_array($marks) || count($marks) > 500) throw new ApiError(400, 'A song part can have up to 500 positioned chords.');
            $section['chord_marks'] = [];
            foreach ($marks as $mark) {
                if (!is_array($mark) || filter_var($mark['at'] ?? null, FILTER_VALIDATE_INT) === false) throw new ApiError(400, 'Each chord needs a lyric position.');
                $at = (int) $mark['at'];
                $chord = trim((string) ($mark['chord'] ?? ''));
                if ($chord === '' || mb_strlen($chord) > 16) throw new ApiError(400, 'Check the chord names and their lyric positions.');
                $section['chord_marks'][] = ['at' => max(0, min($at, mb_strlen($section['lyrics']))), 'chord' => $chord];
            }
            usort($section['chord_marks'], static fn(array $a, array $b): int => $a['at'] <=> $b['at']);
            if ($section['name'] === '' || mb_strlen($section['name']) > 80) throw new ApiError(400, 'Each song part needs a name up to 80 characters.');
        }
        return [
            'title' => $title,
            'writer' => trim((string) ($body['writer'] ?? '')) ?: null,
            'song_key' => trim((string) ($body['default_key'] ?? '')) ?: null,
            // Keep a convenient plain-text lyric column as well as JSON
            // sections with chord positions anchored to lyric characters.
            'lyrics' => (string) ($body['lyrics'] ?? implode("\n\n", array_map(
                static fn(array $section): string => $section['name'] . "\n" . $section['lyrics'],
                array_values($sections)
            ))),
            'sections' => json_encode(array_values($sections), JSON_THROW_ON_ERROR),
            'ocr_text' => (string) ($body['ocr_text'] ?? ''),
        ];
    }

    private function replaceSetlistSongs(int $setlistId, array $songIds): void
    {
        $this->db->prepare(
            'UPDATE live_worship.setlist_songs
                SET inactivated_on=now(), inactivated_by=:inactivated_by
              WHERE setlist_id=:id AND inactivated_on IS NULL AND inactivated_by IS NULL'
        )->execute(['id' => $setlistId, 'inactivated_by' => $this->member['id']]);
        $insert = $this->db->prepare(
            'INSERT INTO live_worship.setlist_songs
                (setlist_id, song_id, position, activated_on, activated_by, inactivated_on, inactivated_by)
             VALUES (:setlist, :song, :position, now(), :activated_by, NULL, NULL)
             ON CONFLICT (setlist_id, song_id) DO UPDATE
                SET position=EXCLUDED.position,
                    activated_on=now(), activated_by=EXCLUDED.activated_by,
                    inactivated_on=NULL, inactivated_by=NULL'
        );
        foreach ($songIds as $index => $songId) {
            $insert->execute([
                'setlist' => $setlistId,
                'song' => $songId,
                'position' => $index + 1,
                'activated_by' => $this->member['id'],
            ]);
        }
    }

    private function songIds(mixed $value): array
    {
        if (!is_array($value) || count($value) > 200) throw new ApiError(400, 'Choose up to 200 songs for the set list.');
        $ids = array_values(array_unique(array_map(static function ($id): int {
            if (!ctype_digit((string) $id) || (int) $id < 1) throw new ApiError(400, 'Invalid song selected.');
            return (int) $id;
        }, $value)));
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $check = $this->db->prepare("SELECT count(*) FROM live_worship.songs WHERE id IN ({$marks}) AND inactivated_on IS NULL AND inactivated_by IS NULL");
            $check->execute($ids);
            if ((int) $check->fetchColumn() !== count($ids)) throw new ApiError(400, 'One or more selected songs no longer exist.');
        }
        return $ids;
    }

    private function archiveExpired(): void
    {
        $this->db->exec("UPDATE live_worship.setlists
                            SET inactivated_on=now(), inactivated_by=0
                          WHERE inactivated_on IS NULL AND inactivated_by IS NULL
                            AND service_at + interval '6 hours' <= now()");
    }

    private function memberById(int $id): array
    {
        $stmt = $this->db->prepare('SELECT id, role, (inactivated_on IS NULL AND inactivated_by IS NULL) AS active FROM live_worship.members WHERE id=:id');
        $stmt->execute(['id' => $id]);
        $member = $stmt->fetch();
        if (!$member) throw new ApiError(404, 'Live Worship member not found.');
        return $member;
    }

    private function ensureAnotherLeader(int $excludingId): void
    {
        $stmt = $this->db->prepare("SELECT count(*) FROM live_worship.members WHERE inactivated_on IS NULL AND inactivated_by IS NULL AND role='leader' AND id<>:id");
        $stmt->execute(['id' => $excludingId]);
        if ((int) $stmt->fetchColumn() === 0) throw new ApiError(400, 'Keep at least one active worship leader.');
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

    private function respond(mixed $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function recordLoginActivity(
        ?string $actorId,
        ?string $tenantId,
        string $eventType,
        string $outcome,
        string $authMode,
        ?string $usernameHint = null,
        ?string $failureCode = null
    ): void {
        try {
            LoginTracking::record($this->db, $actorId, $tenantId, $eventType, $outcome, $authMode, LoginTracking::clientKind(), $usernameHint, $failureCode);
        } catch (Throwable $error) {
            // Authentication and tenant selection must remain available if the
            // optional activity ledger is unavailable during a rollout.
            error_log('Live Worship login activity recording failed: ' . $error->getMessage());
        }
    }
}
