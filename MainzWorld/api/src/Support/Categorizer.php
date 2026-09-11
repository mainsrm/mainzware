<?php
declare(strict_types=1);

namespace MainzWorld\Support;

// Hybrid classifier: normalize merchant text, score multiple signals, then expose
// confidence/method metadata so uncertain imports can be reviewed instead of hidden.
final class Categorizer
{
    private const MERCHANT_RULES = [
        'Groceries' => ['kroger', 'walmart', 'wal mart', 'wm supercenter', 'sams club', 'samsclub', 'costco whse', 'save a lot', 'aldi', 'meijer', 'target', 'dollar tree', 'dollar general', 'paveys country'],
        'Dining' => ['mcdonald', 'wendy', 'burger king', 'arbys', 'taco bell', 'chipotle', 'dairy queen', 'papa john', 'domino', 'el camino', 'great china', 'long john', 'kfc', 'chick fil', 'skyline chili', 'pizza', 'restaurant', 'grill', 'cafe', 'buffet', 'diner', 'dunkin'],
        'Transportation' => ['speedway', 'marathon', 'sunoco', 'shell', 'bp', 'costco gas', 'exxon', 'chevron', 'mobil', 'circle k', 'loves', 'pilot', 'flying j', 'quiktrip', 'wawa', 'sheetz', 'fuel'],
        'Utilities' => ['duke', 'electric', 'verizon', 'frontier', 'att', 'water', 'gas company', 'internet', 'connersville utl'],
        'Insurance' => ['insurance', 'celina', 'progressive', 'geico', 'state farm'],
        'Subscriptions Memberships' => ['amazon prime', 'netflix', 'disney', 'disneyplus', 'hulu', 'spotify', 'pure flix', 'github', 'costco.com', 'membership'],
        'Giving' => ['ptc ministries', 'church', 'tithe', 'offering', 'donation', 'charity'],
        'Household' => ['tractor supply', 'home depot', 'lowes', 'ace hardware', 'menards', 'zimmer tractor'],
        'Personal' => ['great clips', 'salon', 'barber', 'kohls', 'shoe show', 'lehigh', 'clothing', 'venmo', 'showtime', 'entertainment', 'label shop'],
        'Education (Tuition)' => ['univ of', 'university', 'college', 'tuition', 'golay', 'univ cinti'],
    ];

    public static function normalize(string $description): string
    {
        $normalized = strtolower($description);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    public static function merchant(string $description): string
    {
        $text = self::normalize($description);
        $text = preg_replace('/\b(pos|web|ccd|ppd|ach|payment|payments)\b.*$/', '', $text) ?? $text;
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    public static function classify(string $description, ?string $csvCategory = null): array
    {
        $text = self::normalize($description);
        $merchant = self::merchant($description);

        if (preg_match('/\b(payroll|deposit|from mom|christmas from)\b/', $text)) {
            return ['category' => 'Transfer', 'budget_category' => null, 'confidence' => 0.99, 'method' => 'transfer-rule', 'merchant' => $merchant];
        }
        if (preg_match('/\b(internet loan|internet tfr|loan adv|loan pymt|tfr frm|tfr to|withdrawal|advance from)\b/', $text)) {
            return ['category' => 'Transfer', 'budget_category' => 'Transfer', 'confidence' => 0.99, 'method' => 'transfer-rule', 'merchant' => $merchant];
        }
        if (preg_match('/\b(kroger fuel|kroger fuel ctr|costco gas|gas|fuel|speedway|marathon|shell|bp)\b/', $text)) {
            return ['category' => 'Transportation', 'budget_category' => 'Transportation', 'confidence' => 0.99, 'method' => 'fuel-rule', 'merchant' => $merchant];
        }

        $scores = [];
        foreach (self::MERCHANT_RULES as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($merchant, $needle)) {
                    $scores[$category] = ($scores[$category] ?? 0) + (strlen($needle) >= 7 ? 10 : 6);
                }
            }
        }

        $csvMapped = self::mapCsvCategory($csvCategory);
        if ($csvMapped !== null) {
            $scores[$csvMapped] = ($scores[$csvMapped] ?? 0) + 8;
        }

        if ($scores === []) {
            return ['category' => 'Misc', 'budget_category' => 'Misc', 'confidence' => 0.2, 'method' => 'fallback', 'merchant' => $merchant];
        }

        arsort($scores);
        $categories = array_keys($scores);
        $winner = $categories[0];
        $top = $scores[$winner];
        $second = count($categories) > 1 ? $scores[$categories[1]] : 0;
        $confidence = min(0.99, max(0.45, 0.55 + (($top - $second) / max(10, $top)) * 0.4));

        return [
            'category' => $winner,
            'budget_category' => $winner,
            'confidence' => round($confidence, 2),
            'method' => $csvMapped !== null ? 'merchant-plus-bank-category' : 'merchant-scoring',
            'merchant' => $merchant,
        ];
    }

    public static function categorize(string $description): string
    {
        return self::classify($description)['category'];
    }

    public static function budgetCategory(string $description, ?string $csvCategory = null): ?string
    {
        return self::classify($description, $csvCategory)['budget_category'];
    }

    private static function mapCsvCategory(?string $category): ?string
    {
        $value = self::normalize($category ?? '');
        return match (true) {
            $value === '' => null,
            str_contains($value, 'grocer') => 'Groceries',
            str_contains($value, 'dining') || str_contains($value, 'fast food') => 'Dining',
            str_contains($value, 'gas') || str_contains($value, 'fuel') => 'Transportation',
            str_contains($value, 'utility') => 'Utilities',
            str_contains($value, 'insurance') => 'Insurance',
            str_contains($value, 'subscription') || str_contains($value, 'membership') => 'Subscriptions Memberships',
            default => null,
        };
    }
}
