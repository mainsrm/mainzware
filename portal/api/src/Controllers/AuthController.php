<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\Users;
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
            http_response_code(401);
            echo json_encode(['error' => 'Invalid username or password.']);
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];

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
}
