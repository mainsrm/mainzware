<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\ScrapeSources;
use MainzWorld\Support\Auth;
use MainzWorld\Support\SaleScraper;

final class ScrapeSourcesController
{
    public function index(): void
    {
        header('Content-Type: application/json');
        if (Auth::requireAdmin() === null) return;
        echo json_encode(ScrapeSources::all(), JSON_THROW_ON_ERROR);
    }

    public function create(): void
    {
        header('Content-Type: application/json');
        if (Auth::requireAdmin() === null) return;

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $label = trim((string) ($body['label'] ?? ''));
        $url = trim((string) ($body['url'] ?? ''));
        $vendor = strtoupper(trim((string) ($body['vendor'] ?? 'SRI')));

        if ($vendor !== 'SRI' || $label === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => 'SRI vendor, label, and a valid URL are required.']);
            return;
        }
        if (!str_contains(parse_url($url, PHP_URL_HOST) ?: '', 'sriservices.com')) {
            http_response_code(400);
            echo json_encode(['error' => 'The URL must belong to sriservices.com.']);
            return;
        }

        try {
            $id = ScrapeSources::create($label, $url, $vendor);
            echo json_encode(['id' => $id, 'scrape_started' => true], JSON_THROW_ON_ERROR);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            set_time_limit(150);
            SaleScraper::refreshStaleSources();
        } catch (\PDOException $error) {
            if ($error->getCode() === '23505') {
                http_response_code(409);
                echo json_encode(['error' => 'That SRI URL is already configured.']);
                return;
            }
            throw $error;
        }
    }

    public function delete(int $id): void
    {
        header('Content-Type: application/json');
        if (Auth::requireAdmin() === null) return;
        try {
            ScrapeSources::delete($id);
        } catch (\InvalidArgumentException $error) {
            http_response_code(404);
            echo json_encode(['error' => $error->getMessage()]);
            return;
        }
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }
}
