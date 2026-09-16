<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Support\IchimokuAnalyzer;
use RuntimeException;

final class MarketController
{
    public function btcIchimoku(): void
    {
        header('Content-Type: application/json');

        $secret = getenv('TAAPI_API_KEY');
        if (!$secret) {
            http_response_code(503);
            echo json_encode(['error' => 'TAAPI_API_KEY is not configured on the server.']);
            return;
        }

        try {
            // v2 API: /indicator/{name} path, bearer-token auth, "timeframe" (not "interval"),
            // and symbol without a slash (e.g. BTCUSDT).
            $ichimokuUrl = 'https://v2.taapi.io/indicator/ichimoku?exchange=binance&symbol=BTCUSDT&timeframe=1d';
            $raw = $this->fetchJson($ichimokuUrl, $secret);

            if (isset($raw['error'])) {
                throw new RuntimeException('taapi.io error: ' . $raw['error']);
            }

            // Multi-value indicators return each field as an array of historical values;
            // we only asked for the latest one, so take index 0 of each.
            $fields = ['conversion', 'base', 'spanA', 'spanB', 'laggingSpanA', 'laggingSpanB'];
            $ichimoku = [];
            foreach ($fields as $field) {
                if (!isset($raw[$field][0])) {
                    throw new RuntimeException("taapi.io response missing field: $field");
                }
                $ichimoku[$field] = $raw[$field][0];
            }

            $priceData = $this->fetchJson('https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd');
            $price = $priceData['bitcoin']['usd'] ?? null;

            if (!is_numeric($price)) {
                throw new RuntimeException('Could not fetch current BTC price.');
            }

            echo json_encode(IchimokuAnalyzer::analyze($ichimoku, (float) $price), JSON_THROW_ON_ERROR);
        } catch (RuntimeException $e) {
            http_response_code(502);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function fetchJson(string $url, ?string $bearerToken = null): array
    {
        // ignore_errors=true so we still get the response body on 4xx/5xx (e.g. taapi.io's
        // JSON error payload) instead of file_get_contents just returning false.
        $headerLines = ['User-Agent: MainzWorld/1.0 (+https://mainzworld.local)'];
        if ($bearerToken !== null) {
            $headerLines[] = "Authorization: Bearer $bearerToken";
        }
        $safeUrl = $url;
        $body = false;
        $status = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $httpOptions = [
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headerLines),
            ];
            $context = stream_context_create(['http' => $httpOptions]);
            $body = @file_get_contents($url, false, $context);
            $status = $this->responseStatus($http_response_header ?? []);

            if ($body !== false || $attempt === 1) {
                break;
            }

            usleep(250000);
        }

        if ($body === false) {
            throw new RuntimeException("Upstream request failed after 2 attempts: $safeUrl");
        }

        $data = json_decode($body, true);

        if ($status !== null && $status >= 400) {
            $detail = is_array($data) ? ($data['errors'][0] ?? $data['error'] ?? $body) : $body;
            throw new RuntimeException("HTTP $status from $safeUrl — " . (is_string($detail) ? $detail : json_encode($detail)));
        }

        if (!is_array($data)) {
            throw new RuntimeException("Invalid response from: $safeUrl");
        }
        return $data;
    }

    private function responseStatus(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }
}
