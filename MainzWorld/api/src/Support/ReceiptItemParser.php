<?php
declare(strict_types=1);

namespace MainzWorld\Support;

// Best-effort line-item extraction from raw OCR text. Every result is provisional —
// the API always lets the user review/edit items (via receipt_items endpoints) before
// a receipt is confirmed into a budget transaction.
final class ReceiptItemParser
{
    private const SKIP_PATTERN = '/\b(subtotal|sub total|tax|change|cash|debit|credit|balance|visa|mastercard|amex|approved|auth code|card|tender)\b/i';
    private const TOTAL_PATTERN = '/\btotal\b/i';
    private const PRICE_PATTERN = '/(-?\$?\d+\.\d{2})\s*$/';
    private const DATE_PATTERN = '/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/';

    public static function parse(string $rawText): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $rawText)), fn ($line) => $line !== ''));

        $items = [];
        $total = null;
        $purchaseDate = null;
        $merchant = null;

        foreach ($lines as $index => $line) {
            if ($merchant === null && $index === 0 && !preg_match(self::PRICE_PATTERN, $line)) {
                $merchant = $line;
            }

            if ($purchaseDate === null && preg_match(self::DATE_PATTERN, $line, $dateMatch)) {
                $purchaseDate = self::normalizeDate($dateMatch[1]);
            }

            if (!preg_match(self::PRICE_PATTERN, $line, $priceMatch)) {
                continue;
            }
            $amount = (float) str_replace('$', '', $priceMatch[1]);

            if (preg_match(self::TOTAL_PATTERN, $line) && !preg_match('/subtotal/i', $line)) {
                $total = $amount;
                continue;
            }
            if (preg_match(self::SKIP_PATTERN, $line)) {
                continue;
            }

            $description = trim(preg_replace(self::PRICE_PATTERN, '', $line) ?? $line);
            if ($description === '') {
                continue;
            }

            $items[] = [
                'description' => $description,
                'quantity' => 1,
                'unit_price' => $amount,
                'amount' => $amount,
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'merchant' => $merchant,
            'purchase_date' => $purchaseDate,
        ];
    }

    private static function normalizeDate(string $raw): ?string
    {
        $parts = preg_split('#[/\-]#', $raw) ?: [];
        if (count($parts) !== 3) {
            return null;
        }
        [$month, $day, $year] = $parts;
        if (strlen($year) === 2) {
            $year = '20' . $year;
        }
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }
}
