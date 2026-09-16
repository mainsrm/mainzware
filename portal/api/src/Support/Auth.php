<?php
declare(strict_types=1);

namespace MainzWorld\Support;

use MainzWorld\Data\Users;

// Shared auth guard used by any controller action that requires login. Accepts either
// a PHP session cookie (web front end) or a stateless "Authorization: Bearer <jwt>" token
// (mobile app), since mobile clients cannot rely on browser session cookies.
final class Auth
{
    public static function currentUser(): ?array
    {
        $userId = $_SESSION['user_id'] ?? self::userIdFromBearerToken();
        if ($userId === null) {
            return null;
        }
        $user = Users::findById((int) $userId);
        if ($user === null || !$user['is_active']) {
            return null;
        }
        return $user;
    }

    // Issues a stateless bearer token for the given user (used by mobile/API clients).
    public static function issueToken(int $userId, int $ttlSeconds = 30 * 24 * 60 * 60): string
    {
        return Jwt::encode(['sub' => $userId], $ttlSeconds);
    }

    private static function userIdFromBearerToken(): ?int
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }
        $claims = Jwt::decode($matches[1]);
        if ($claims === null || !isset($claims['sub'])) {
            return null;
        }
        return (int) $claims['sub'];
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
