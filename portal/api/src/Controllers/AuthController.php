<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\Users;
use MainzWorld\Config\Database;
use MainzWorld\Support\Auth;

final class AuthController
{
    public function login(): void
    {
        header('Content-Type: application/json');

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Username and password are required.']);
            return;
        }

        $user = Users::findByUsername($username);

        // Constant-shape response whether the user exists or not — avoid leaking which usernames are registered.
        if ($user === null || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
            $this->recordLoginActivity(null, 'failure', null, 'invalid_credentials');
            http_response_code(401);
            echo json_encode(['error' => 'Invalid username or password.']);
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        unset($_SESSION['live_worship_account_id'], $_SESSION['live_worship_auth_mode']);
        $this->recordLoginActivity($user, 'success', $user['username']);

        // Web clients use the session cookie; the "token" field is for mobile/API clients
        // that send it back as "Authorization: Bearer <token>".
        echo json_encode([
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'token' => Auth::issueToken((int) $user['id']),
        ], JSON_THROW_ON_ERROR);
    }

    public function logout(): void
    {
        header('Content-Type: application/json');
        $this->recordLoginActivity(Auth::currentUser(), 'success', null, null, 'logout');
        $_SESSION = [];
        session_destroy();
        echo json_encode(['ok' => true]);
    }

    public function me(): void
    {
        header('Content-Type: application/json');

        $user = Auth::currentUser();
        if ($user === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Not logged in.']);
            return;
        }

        echo json_encode($user, JSON_THROW_ON_ERROR);
    }

    public function changePassword(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) {
            return;
        }

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $currentPassword = (string) ($body['current_password'] ?? '');
        $newPassword = (string) ($body['new_password'] ?? '');
        if (strlen($newPassword) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'New password must be at least 8 characters.']);
            return;
        }

        $account = Users::findByUsername($user['username']);
        if ($account === null || !password_verify($currentPassword, $account['password_hash'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Current password is incorrect.']);
            return;
        }

        Users::update((int) $user['id'], null, null, password_hash($newPassword, PASSWORD_BCRYPT));
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }

    private function recordLoginActivity(?array $user, string $outcome, ?string $usernameHint = null, ?string $failureCode = null, string $eventType = 'login'): void
    {
        try {
            $db = Database::connection();
            $stmt = $db->prepare(
                'INSERT INTO lw_control.login_activity
                    (id, event_type, outcome, auth_mode, client_kind,
                     username_hint, failure_code, occurred_on, activated_on, activated_by)
                 VALUES (:id, :event_type, :outcome, \'mainzworld\', :client_kind,
                         :username_hint, :failure_code, now(), now(), :system_actor)'
            );
            $stmt->execute([
                'id' => self::uuid(),
                'event_type' => $eventType,
                'outcome' => $outcome,
                'client_kind' => in_array(strtolower((string) ($_SERVER['HTTP_X_MAINZWARE_CLIENT'] ?? '')), ['web', 'mobile'], true)
                    ? strtolower((string) $_SERVER['HTTP_X_MAINZWARE_CLIENT']) : 'web',
                'username_hint' => $usernameHint !== null ? mb_substr(trim($usernameHint), 0, 120) : null,
                'failure_code' => $failureCode,
                'system_actor' => '00000000-0000-0000-0000-000000000001',
            ]);
        } catch (\Throwable $error) {
            // Login must not fail merely because the optional Live Worship
            // activity ledger is not yet migrated or temporarily unavailable.
            error_log('MainzWare login activity recording failed: ' . $error->getMessage());
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
