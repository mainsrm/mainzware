<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\Users;
use MainzWorld\Support\Auth;

final class UsersController
{
    public function index(): void
    {
        header('Content-Type: application/json');
        if (Auth::requireAdmin() === null) {
            return;
        }
        echo json_encode(Users::all(), JSON_THROW_ON_ERROR);
    }

    public function create(): void
    {
        header('Content-Type: application/json');
        if (Auth::requireAdmin() === null) {
            return;
        }

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $role = in_array($body['role'] ?? 'admin', ['admin', 'user'], true) ? $body['role'] : 'admin';

        if ($username === '' || strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Username and a password of at least 8 characters are required.']);
            return;
        }

        if (Users::findByUsername($username) !== null) {
            http_response_code(409);
            echo json_encode(['error' => 'A user with that username already exists.']);
            return;
        }

        $id = Users::create($username, password_hash($password, PASSWORD_BCRYPT), $role);
        echo json_encode(['id' => $id], JSON_THROW_ON_ERROR);
    }

    public function update(int $id): void
    {
        header('Content-Type: application/json');
        if (Auth::requireAdmin() === null) {
            return;
        }

        $target = Users::findById($id);
        if ($target === null) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found.']);
            return;
        }

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);

        $username = null;
        if (array_key_exists('username', $body)) {
            $username = trim((string) $body['username']);
            if ($username === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Username cannot be blank.']);
                return;
            }
            $existing = Users::findByUsername($username);
            if ($existing !== null && (int) $existing['id'] !== $id) {
                http_response_code(409);
                echo json_encode(['error' => 'A user with that username already exists.']);
                return;
            }
        }

        $role = null;
        if (array_key_exists('role', $body)) {
            if (!in_array($body['role'], ['admin', 'user'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Role must be "admin" or "user".']);
                return;
            }
            $role = $body['role'];
        }

        $passwordHash = null;
        if (!empty($body['password'])) {
            if (strlen($body['password']) < 8) {
                http_response_code(400);
                echo json_encode(['error' => 'Password must be at least 8 characters.']);
                return;
            }
            $passwordHash = password_hash($body['password'], PASSWORD_BCRYPT);
        }

        Users::update($id, $username, $role, $passwordHash);
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }

    public function setActive(int $id, bool $active): void
    {
        header('Content-Type: application/json');
        $admin = Auth::requireAdmin();
        if ($admin === null) {
            return;
        }

        if ($admin['id'] === $id && !$active) {
            http_response_code(400);
            echo json_encode(['error' => 'You cannot deactivate your own account.']);
            return;
        }

        Users::setActive($id, $active);
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }
}
