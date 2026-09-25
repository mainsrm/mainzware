<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/ApiError.php';
require dirname(__DIR__) . '/src/Transpose.php';

use LiveWorship\ApiError;
use LiveWorship\Transpose;

function check(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . json_encode($actual));
}

$part = ['id' => 'verse', 'name' => 'Verse', 'lyrics' => 'Amazing grace', 'chords' => 'C Am7 F G/B N.C.', 'chord_marks' => [
    ['at' => 0, 'chord' => 'Cmaj7/G'], ['at' => 8, 'chord' => 'F#dim7'],
]];
$result = Transpose::sections([$part], 'C', 'D')[0];
check($result['chords'], 'D Bm7 G A/C# N.C.', 'Transpose legacy chord text');
check($result['chord_marks'], [['at' => 0, 'chord' => 'Dmaj7/A'], ['at' => 8, 'chord' => 'G#dim7']], 'Transpose positioned chords and slash bass');
check($result['lyrics'], $part['lyrics'], 'Keep lyrics');
check($result['id'], $part['id'], 'Keep section IDs');
check(Transpose::sections([$result], 'D', 'C'), [$part], 'Round trip');
check(Transpose::sections([$part], 'C', 'B♭')[0]['chords'], 'Bb Gm7 Eb F/A N.C.', 'Unicode flat destination');
check(Transpose::sections([$part], 'Am', 'Bm')[0]['chords'], 'D Bm7 G A/C# N.C.', 'Minor keys');
check(Transpose::sections([$part], null, 'D'), [$part], 'First key assignment does not move chords');
check(Transpose::sections([$part], 'C', 'C'), [$part], 'Same key does not modify the chart');
$unicode = [['chords' => 'F♯m7/B D♭sus4 Cadd9 C7(b9) C/E', 'chord_marks' => []]];
check(Transpose::sections($unicode, 'C', 'D')[0]['chords'], 'G#m7/C# D#sus4 Dadd9 D7(b9) D/F#', 'Unicode accidentals and chord suffixes');
try {
    Transpose::sections([$part], 'C', 'invalid');
    throw new RuntimeException('Invalid key was accepted');
} catch (ApiError $error) {
    check($error->status, 400, 'Invalid key returns a client error');
}
echo "Transposition checks passed.\n";
