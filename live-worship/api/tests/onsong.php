<?php
declare(strict_types=1);

namespace LiveWorship;

require dirname(__DIR__) . '/src/bootstrap.php';

function check(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "PASS {$label}\n";
}

$song = OnsongParser::parse(<<<'SONG'
﻿Title: 10,000 Reasons
Artist: Matt Redman & Jonas Myrin
Key: [G]

Chorus:
Bless the[C] Lord, O my[G] soul,
[C]Worship His ho[G]ly n[Dsus4]ame.

Verse 1:
The[C] sun comes up
SONG, 'test/10,000 Reasons.onsong');

check($song['title'], '10,000 Reasons', 'metadata title');
check($song['writer'], 'Matt Redman & Jonas Myrin', 'metadata writer');
check($song['key'], 'G', 'metadata key');
check(array_column($song['sections'], 'name'), ['Chorus', 'Verse 1'], 'section names');
check($song['sections'][0]['lyrics'], "Bless the Lord, O my soul,\nWorship His holy name.", 'chord markers removed from lyrics');
check($song['sections'][0]['chord_marks'], [
    ['at' => 9, 'chord' => 'C'], ['at' => 20, 'chord' => 'G'],
    ['at' => 27, 'chord' => 'C'], ['at' => 41, 'chord' => 'G'], ['at' => 45, 'chord' => 'Dsus4'],
], 'chord positions');
check($song['sections'][1]['id'], 'onsong-' . substr(hash('sha256', 'test/10,000 Reasons.onsong:1'), 0, 20), 'stable section id');

$rhythm = OnsongParser::parse(<<<'SONG'
Title: Rhythm intro
Key: [B]

Intro:
[B] / / / / / | / / / [E/B] / /&#x20;
SONG, 'test/Rhythm intro.onsong');
check($rhythm['sections'][0]['lyrics'], ' / / / / / | / / /  / /', 'chord-only rhythm row retained');
check($rhythm['sections'][0]['chord_marks'], [
    ['at' => 0, 'chord' => 'B'], ['at' => 19, 'chord' => 'E/B'],
], 'chords positioned over rhythm row');

$variant = OnsongParser::parse("Title: Header variants\n\nChorus\n[C]Sing\n\nVerse: 1\n[G]Go\n", 'test/Header variants.onsong');
check(array_column($variant['sections'], 'name'), ['Chorus', 'Verse 1'], 'section header variants');
echo "All OnSong parser tests passed.\n";
