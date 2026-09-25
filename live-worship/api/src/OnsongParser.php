<?php
declare(strict_types=1);

namespace LiveWorship;

/**
 * Parses the plain-text .onsong format used by the mattgraham/worship repo.
 * The parser keeps metadata and song content only; repository text is never
 * evaluated as executable input.
 */
final class OnsongParser
{
    private const METADATA_KEYS = [
        'title', 'artist', 'author', 'writer', 'composer', 'key', 'original key',
        'copyright', 'notes', 'tempo', 'book', 'capo', 'scripture reference(s)',
    ];

    /** @return array{title:string,writer:?string,key:?string,lyrics:string,sections:array<int,array<string,mixed>>,metadata:array<string,string>} */
    public static function parse(string $contents, string $sourcePath): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $contents = html_entity_decode($contents, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $lines = explode("\n", $contents);
        $metadata = [];
        $sections = [];
        $current = null;
        $leading = [];
        $sawSection = false;

        foreach ($lines as $line) {
            $line = rtrim($line, "\t \0\x0B");
            $trimmed = trim($line);
            if (!$sawSection && self::metadataLine($trimmed, $key, $value)) {
                $metadata[$key] = $value;
                continue;
            }
            $sectionName = self::sectionHeader($trimmed);
            if ($sectionName !== null) {
                if ($current !== null) $sections[] = $current;
                elseif (array_filter($leading, static fn(string $value): bool => trim($value) !== '')) $sections[] = ['name' => 'Verse 1', 'lines' => $leading];
                $leading = [];
                $current = ['name' => $sectionName, 'lines' => [], 'index' => count($sections)];
                $sawSection = true;
                continue;
            }
            if ($current === null) $leading[] = $line;
            else $current['lines'][] = $line;
        }
        if ($current !== null) $sections[] = $current;
        elseif (array_filter($leading, static fn(string $value): bool => trim($value) !== '')) $sections[] = ['name' => 'Verse 1', 'lines' => $leading];

        $parsedSections = [];
        foreach ($sections as $index => $section) $parsedSections[] = self::makeSection($section['name'], $section['lines'], $sourcePath, $index);
        $parsedSections = array_values(array_filter($parsedSections, static fn(array $section): bool => $section['lyrics'] !== '' || $section['chord_marks'] !== []));
        if ($parsedSections === []) $parsedSections[] = self::makeSection('Verse 1', [], $sourcePath, 0);

        $title = trim($metadata['title'] ?? '') ?: pathinfo($sourcePath, PATHINFO_FILENAME);
        $writer = trim($metadata['artist'] ?? $metadata['writer'] ?? $metadata['author'] ?? $metadata['composer'] ?? '');
        $key = self::cleanKey($metadata['key'] ?? $metadata['original key'] ?? '');
        return [
            'title' => self::cleanText($title, 200),
            'writer' => $writer === '' ? null : self::cleanText($writer, 200),
            'key' => $key === '' ? null : $key,
            'lyrics' => implode("\n\n", array_map(static fn(array $section): string => $section['name'] . "\n" . $section['lyrics'], $parsedSections)),
            'sections' => $parsedSections,
            'metadata' => $metadata,
        ];
    }

    /** @return array{name:string,lyrics:string,chords:string,chord_marks:array<int,array{at:int,chord:string}>,id:string} */
    private static function makeSection(string $name, array $lines, string $sourcePath, int $index): array
    {
        while ($lines !== [] && trim((string) end($lines)) === '') array_pop($lines);
        $lyrics = [];
        $marks = [];
        $offset = 0;
        foreach ($lines as $line) {
            [$plain, $lineMarks] = self::extractChords($line);
            $lyrics[] = $plain;
            foreach ($lineMarks as $mark) $marks[] = ['at' => $offset + $mark['at'], 'chord' => $mark['chord']];
            $offset += mb_strlen($plain) + 1;
        }
        return [
            'id' => 'onsong-' . substr(hash('sha256', $sourcePath . ':' . $index), 0, 20),
            'name' => self::cleanText(self::normalizeSectionName($name), 80),
            'lyrics' => implode("\n", $lyrics),
            'chords' => '',
            'chord_marks' => $marks,
        ];
    }

    /** @return array{0:string,1:array<int,array{at:int,chord:string}>} */
    private static function extractChords(string $line): array
    {
        $plain = '';
        $marks = [];
        $position = 0;
        $length = mb_strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $character = mb_substr($line, $i, 1);
            if ($character === '[') {
                $close = mb_strpos($line, ']', $i + 1);
                if ($close !== false) {
                    $chord = trim(mb_substr($line, $i + 1, $close - $i - 1));
                    if ($chord !== '' && mb_strlen($chord) <= 16 && self::looksLikeChord($chord)) {
                        $marks[] = ['at' => $position, 'chord' => $chord];
                        $i = $close;
                        continue;
                    }
                }
            }
            $plain .= $character;
            $position++;
        }
        return [rtrim($plain), $marks];
    }

    private static function metadataLine(string $line, ?string &$key, ?string &$value): bool
    {
        if (!preg_match('/^([^:]{1,50}):\s*(.*)$/u', $line, $matches)) return false;
        $candidate = strtolower(trim($matches[1]));
        if (!in_array($candidate, self::METADATA_KEYS, true)) return false;
        $key = $candidate;
        $value = trim($matches[2]);
        return true;
    }

    private static function sectionHeader(string $line): ?string
    {
        if ($line === '') return null;
        $name = null;
        if (preg_match('/^([^:\[\]]{1,80}):\s*(\d+)?\s*$/u', $line, $matches)) {
            $name = trim($matches[1]);
            if (!empty($matches[2])) $name .= ' ' . $matches[2];
        } elseif (preg_match('/^(?:intro|outro|ending|bridge|tag|vamp|turn|turnaround|instrumental|intstrumental|refrain|chor(?:us|is)|pre[- ]?chorus|verse|v)(?:\s*:?\s*\d+)?$/iu', $line)) {
            $name = trim($line, " :\t");
        }
        if ($name === null || $name === '' || in_array(strtolower($name), self::METADATA_KEYS, true)) return null;
        return self::normalizeSectionName($name);
    }

    private static function normalizeSectionName(string $name): string
    {
        $name = trim($name);
        $lower = strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $aliases = [
            'choris' => 'Chorus', 'chorus' => 'Chorus', 'refrain' => 'Refrain',
            'pre chorus' => 'Pre-Chorus', 'pre-chorus' => 'Pre-Chorus',
            'intstrumental' => 'Instrumental', 'instrumental' => 'Instrumental',
            'intro' => 'Intro', 'outro' => 'Outro', 'ending' => 'Ending',
            'bridge' => 'Bridge', 'tag' => 'Tag', 'vamp' => 'Vamp',
            'turn' => 'Turn', 'turnaround' => 'Turnaround',
        ];
        if (isset($aliases[$lower])) return $aliases[$lower];
        if (preg_match('/^verse\s*:?\s*(\d+)$/i', $name, $match)) return 'Verse ' . $match[1];
        if (preg_match('/^v\s*:?\s*(\d+)$/i', $name, $match)) return 'Verse ' . $match[1];
        if (preg_match('/^(chorus|refrain)\s*:?\s*(\d+)$/i', $name, $match)) return ucfirst(strtolower($match[1])) . ' ' . $match[2];
        return self::cleanText($name, 80);
    }

    private static function cleanKey(string $key): string
    {
        $key = trim(trim($key, "[] \t"));
        return preg_replace('/\s+.*/u', '', $key) ?? $key;
    }

    private static function cleanText(string $value, int $limit): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value;
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 0, $limit);
    }

    private static function looksLikeChord(string $value): bool
    {
        return preg_match('/^(?:N\.?C\.?|[A-G](?:[#♯b♭])?(?:[a-zA-Z0-9+#♯b♭()\/-]*)?)$/u', $value) === 1;
    }
}
