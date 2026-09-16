<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\PropertyLists;
use MainzWorld\Support\Auth;

final class PropertyListsController
{
    public function index(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;

        echo json_encode(PropertyLists::allForUser((int) $user['id']), JSON_THROW_ON_ERROR);
    }

    public function create(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $name = trim((string) ($body['name'] ?? ''));
        $propertyIds = $body['property_ids'] ?? [];

        if ($name === '' || strlen($name) > 255) {
            http_response_code(400);
            echo json_encode(['error' => 'A list name between 1 and 255 characters is required.']);
            return;
        }
        if (!is_array($propertyIds)) {
            http_response_code(400);
            echo json_encode(['error' => 'property_ids must be an array.']);
            return;
        }

        try {
            $id = PropertyLists::create((int) $user['id'], $name, $propertyIds);
        } catch (\PDOException $error) {
            if ($error->getCode() === '23505') {
                http_response_code(409);
                echo json_encode(['error' => 'You already have a list with that name.']);
                return;
            }
            throw $error;
        }

        echo json_encode(['id' => $id], JSON_THROW_ON_ERROR);
    }

    public function show(int $id): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;

        echo json_encode(PropertyLists::itemsForUser((int) $user['id'], $id), JSON_THROW_ON_ERROR);
    }

    public function delete(int $id): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;

        try {
            PropertyLists::delete((int) $user['id'], $id);
        } catch (\InvalidArgumentException $error) {
            http_response_code(404);
            echo json_encode(['error' => $error->getMessage()]);
            return;
        }

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }
}
