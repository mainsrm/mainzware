<?php
declare(strict_types=1);

namespace LiveWorship;

final class Transpose
{
    private const NOTES = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5, 'G' => 7, 'A' => 9, 'B' => 11];

    private static function pitch(string $note): int
    {
        return (self::NOTES[$note[0]] + (str_contains($note, '#') ? 1 : (str_contains($note, 'b') ? -1 : 0)) + 12) % 12;
    }

    public static function sections(array $sections, ?string $from, ?string $to): array
    {
        $from = str_replace(['♯', '♭'], ['#', 'b'], trim($from ?? ''));
        $to = str_replace(['♯', '♭'], ['#', 'b'], trim($to ?? ''));
        if ($from === $to) return $sections;
        if ($to !== '' && !preg_match('/^[A-G][#b]?m?$/', $to)) throw new ApiError(400, 'Choose a valid musical key.');
        // Assigning the first known key establishes the chart's existing key.
        if ($from === '' || $to === '') return $sections;
        if (!preg_match('/^[A-G][#b]?m?$/', $from)) throw new ApiError(400, 'The current song key must be a valid musical key before transposing.');
        $shift = (self::pitch($to) - self::pitch($from) + 12) % 12;
        $flat = str_contains($to, 'b') || in_array($to, ['F', 'Dm', 'Gm', 'Cm', 'Fm'], true);
        $notes = $flat ? ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'] : ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
        $transpose = static function (string $text) use ($shift, $notes): string {
            $text = str_replace(['♯', '♭'], ['#', 'b'], $text);
            return preg_replace_callback(
                '/(?<![\p{L}\p{N}#])([A-G][#b]?)(?=(?:maj|min|dim|aug|sus|add|m|M)?(?:[0-9+#b°øΔ()\-]|\/|\s|$|[|,;\]]))/u',
                static fn(array $match): string => $notes[(self::pitch($match[1]) + $shift) % 12],
                $text
            );
        };
        foreach ($sections as &$section) {
            $section['chords'] = $transpose((string) ($section['chords'] ?? ''));
            foreach ($section['chord_marks'] ?? [] as $index => $mark) {
                $section['chord_marks'][$index]['chord'] = $transpose((string) $mark['chord']);
            }
        }
        return $sections;
    }
}
