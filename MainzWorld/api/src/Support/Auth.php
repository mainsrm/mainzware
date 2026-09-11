<?php
declare(strict_types=1);

namespace MainzWorld\Support;

use MainzWorld\Data\Users;

// Shared session-auth guard used by any controller action that requires login.
final class Auth
{
    public static function currentUser(): ?array
    {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) {
            return null;
        }
        $user = Users::findById((int) $userId);
        if ($user === null || !$user['is_active']) {
            return null;
        }
        return $user;
    }

    // Returns the logged-in user, or writes a 401 JSON response and returns null.
    public static function requireLogin(): ?array
    {
        $user = self::currentUser();
        if ($user === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Login required.']);
            return null;
        }
        return $user;
    }

    // Returns the logged-in admin user, or writes a 401/403 JSON response and returns null.
    public static function requireAdmin(): ?array
    {
        $user = self::requireLogin();
        if ($user === null) {
            return null;
        }
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Admin role required.']);
            return null;
        }
        return $user;
    }
}
