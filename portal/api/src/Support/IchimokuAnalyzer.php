<?php
declare(strict_types=1);

namespace MainzWorld\Support;

// Ported from the legacy Budget app's Ichimoku pump/sell signal analysis.
final class IchimokuAnalyzer
{
    public static function analyze(array $ichimoku, float $price): array
    {
        $spanA = (float) $ichimoku['spanA'];
        $spanB = (float) $ichimoku['spanB'];
        $conversion = (float) $ichimoku['conversion'];
        $base = (float) $ichimoku['base'];
        $laggingSpanA = (float) $ichimoku['laggingSpanA'];
        $laggingSpanB = (float) $ichimoku['laggingSpanB'];

        $cloudTop = max($spanA, $spanB);
        $cloudBottom = min($spanA, $spanB);
        $laggingCloudTop = max($laggingSpanA, $laggingSpanB);
        $laggingCloudBottom = min($laggingSpanA, $laggingSpanB);

        $cloudGreen = $spanA > $spanB;
        $priceAboveCloud = $price > $cloudTop;
        $conversionCross = $conversion > $base;
        $chikuAboveCloud = $price > $laggingCloudTop;

        $validCount = ($cloudGreen ? 1 : 0) + ($priceAboveCloud ? 1 : 0) + ($conversionCross ? 1 : 0) + ($chikuAboveCloud ? 1 : 0);

        $pumpSignal = match (true) {
            $validCount === 4 => 'strong',
            $validCount === 3 => 'moderate',
            $validCount >= 2 => 'weak',
            default => 'none',
        };

        $threshold = $price * 0.01;
        $chikuTouches = abs($laggingSpanA - $price) < $threshold || abs($laggingSpanB - $price) < $threshold;
        $sellTriggered = $chikuTouches || ($conversion < $base);

        return [
            'price' => $price,
            'signals' => [
                'cloud_green' => $cloudGreen,
                'price_above_cloud' => $priceAboveCloud,
                'conversion_cross' => $conversionCross,
                'chiku_above_cloud' => $chikuAboveCloud,
            ],
            'valid_signal_count' => $validCount,
            'pump_signal' => $pumpSignal,
            'sell_signal_triggered' => $sellTriggered,
        ];
    }
}
