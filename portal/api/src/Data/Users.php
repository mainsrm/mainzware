<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class Users
{
    public static function findByUsername(string $username): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, username, password_hash, role, is_active FROM users WHERE username = :username'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, username, role, is_active FROM users WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function all(): array
    {
        $stmt = Database::connection()->query(
            'SELECT id, username, role, is_active, created_at FROM users ORDER BY id'
        );
        return $stmt->fetchAll();
    }

    public static function activeExcept(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, username FROM users WHERE is_active = TRUE AND id <> :user_id ORDER BY username'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public static function create(string $username, string $passwordHash, string $role): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (username, password_hash, role) VALUES (:username, :hash, :role) RETURNING id'
        );
        $stmt->execute(['username' => $username, 'hash' => $passwordHash, 'role' => $role]);
        return (int) $stmt->fetchColumn();
    }

    public static function setActive(int $id, bool $active): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET is_active = :active WHERE id = :id');
        $stmt->execute(['active' => $active, 'id' => $id]);
    }

    // Updates whichever of username/role/passwordHash are provided (null = leave unchanged).
    public static function update(int $id, ?string $username, ?string $role, ?string $passwordHash): void
    {
        $fields = [];
        $params = ['id' => $id];

        if ($username !== null) {
            $fields[] = 'username = :username';
            $params['username'] = $username;
        }
        if ($role !== null) {
            $fields[] = 'role = :role';
            $params['role'] = $role;
        }
        if ($passwordHash !== null) {
            $fields[] = 'password_hash = :password_hash';
            $params['password_hash'] = $passwordHash;
        }

        if (empty($fields)) {
            return;
        }

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }
}
