<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\GisSources;
use MainzWorld\Support\Auth;

final class GisSourcesController
{
    public function index(): void
    {
        header('Content-Type: application/json');
        if (Auth::requireLogin() === null) return;
        echo json_encode(GisSources::all(), JSON_THROW_ON_ERROR);
    }

    public function create(): void
    {
        header('Content-Type: application/json');
        if (Auth::requireAdmin() === null) return;
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $county = trim((string) ($body['county'] ?? ''));
        $state = strtoupper(trim((string) ($body['state'] ?? '')));
        $url = trim((string) ($body['url'] ?? ''));
        if ($county === '' || $state === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => 'County, state, and a valid GIS URL are required.']);
            return;
        }
        echo json_encode(['id' => GisSources::create($county, $state, $url)], JSON_THROW_ON_ERROR);
    }

    public function delete(int $id): void
    {
        header('Content-Type: application/json');
        if (Auth::requireAdmin() === null) return;
        GisSources::delete($id);
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }
}
