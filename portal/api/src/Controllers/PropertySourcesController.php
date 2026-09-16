<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\PropertySources;
use MainzWorld\Support\Auth;
use MainzWorld\Support\SaleScraper;

final class PropertySourcesController
{
    public function create(): void
    {
        header('Content-Type: application/json');
        if (Auth::requireAdmin() === null) return;

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($body)) {
            $this->error('A JSON object is required.');
            return;
        }

        $sriSources = $this->sourceList($body['sri_sources'] ?? $body['sri'] ?? null);
        $gisSources = $this->sourceList($body['gis_sources'] ?? $body['gis'] ?? null);
        if ($sriSources === null || $gisSources === null || ($sriSources === [] && $gisSources === [])) {
            $this->error('At least one valid SRI or GIS source is required.');
            return;
        }

        $validatedSri = [];
        foreach ($sriSources as $source) {
            $label = trim((string) ($source['label'] ?? ''));
            $url = trim((string) ($source['url'] ?? ''));
            $vendor = strtoupper(trim((string) ($source['vendor'] ?? 'SRI')));
            if ($vendor !== 'SRI' || $label === '' || !filter_var($url, FILTER_VALIDATE_URL)
                || !str_contains(parse_url($url, PHP_URL_HOST) ?: '', 'sriservices.com')) {
                $this->error('Each SRI source requires a label and a valid sriservices.com URL.');
                return;
            }
            $validatedSri[] = ['label' => $label, 'url' => $url, 'vendor' => $vendor];
        }

        $validatedGis = [];
        foreach ($gisSources as $source) {
            $county = trim((string) ($source['county'] ?? ''));
            $state = strtoupper(trim((string) ($source['state'] ?? '')));
            $url = trim((string) ($source['url'] ?? ''));
            if ($county === '' || $state === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                $this->error('Each GIS source requires a county, state, and valid URL.');
                return;
            }
            $validatedGis[] = ['county' => $county, 'state' => $state, 'url' => $url];
        }

        try {
            $saved = PropertySources::save($validatedSri, $validatedGis);
            $queued = SaleScraper::queueAndDispatch($saved['sri_urls']);
            echo json_encode([
                'sri_ids' => $saved['sri_ids'],
                'gis_ids' => $saved['gis_ids'],
                'scrape_queued' => $queued > 0,
                'queued_count' => $queued,
            ], JSON_THROW_ON_ERROR);
        } catch (\PDOException $error) {
            if ($error->getCode() === '23505') {
                $this->error('One or more source values conflict with an existing source.', 409);
                return;
            }
            throw $error;
        }
    }

    private function sourceList(mixed $value): ?array
    {
        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $source) {
                if (!is_array($source)) return null;
            }
            return $value;
        }
        return is_array($value) ? [$value] : null;
    }

    private function error(string $message, int $status = 400): void
    {
        http_response_code($status);
        echo json_encode(['error' => $message], JSON_THROW_ON_ERROR);
    }
}