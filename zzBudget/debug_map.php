<?php

$transFile = __DIR__ . '/Transactions-Combined-2025-12-27-Categorized.csv';
$budgetFile = __DIR__ . '/Budget-2025.csv';

function norm($s) {
    return strtolower(preg_replace('/[^a-z0-9]+/i','', $s));
}

// read transactions
$lines = array_map('trim', file($transFile));
$header = str_getcsv(array_shift($lines));
$transactions = [];
foreach ($lines as $line) {
    if (trim($line) === '') continue;
    $parts = str_getcsv($line);
    if (count($parts) < 8) continue;
    $transactions[] = [
        'account'=>$parts[0],
        'debit'=>$parts[2],
        'credit'=>$parts[3],
        'date'=>$parts[5],
        'description'=>$parts[6],
        'category'=>$parts[7]
    ];
}

// show counts by original category
$byOrig = [];
foreach ($transactions as $t) {
    $cat = $t['category'] ?: 'UNKNOWN';
    if (!isset($byOrig[$cat])) $byOrig[$cat] = [];
    $byOrig[$cat][] = $t;
}

echo "Original transaction categories and counts:\n";
foreach ($byOrig as $k => $arr) {
    echo "- $k: " . count($arr) . "\n";
}

echo "\nSample transactions containing 'gas' or 'fuel' in description/category:\n";
foreach ($transactions as $t) {
    if (stripos($t['description'], 'gas') !== false || stripos($t['category'], 'Gas') !== false || stripos($t['description'], 'fuel') !== false) {
        echo "{$t['date']} | {$t['category']} | {$t['description']} | debit={$t['debit']}\n";
    }
}

// read budget categories (WHERE THE MONEY GOES section)
$blines = array_map('rtrim', file($budgetFile));
$budgetCats = [];
// Collect any lines that look like Category,Amount,Pct where Amount is numeric
foreach ($blines as $line) {
    if (strpos($line, ',') === false) continue;
    $parts = str_getcsv($line);
    if (count($parts) >= 2) {
        $amt = str_replace(['$',' ',','], '', $parts[1]);
        if (is_numeric($amt)) {
            $budgetCats[] = trim($parts[0]);
        }
    }
}

echo "\nBudget categories (parsed):\n";
foreach ($budgetCats as $b) echo "- $b\n";

// synonyms as in index.php
$synonyms = [
    'gas' => ['transportation','transport','gasfuel'],
    'transportation' => ['gas','gasfuel','fuel'],
    'groceries' => ['grocerieskroger','grocerieswholesale','grocerieswalmart','groc'],
];

// try mapping
$mapped = [];
foreach ($transactions as $t) {
    $assigned = false;
    $nDesc = norm($t['description']);
    $nCat = norm($t['category']);
    foreach ($budgetCats as $bd) {
        $nBd = norm($bd);
        if ($nCat === $nBd || strpos($nCat, $nBd) !== false || strpos($nBd, $nCat) !== false || strpos($nDesc, $nBd) !== false) {
            $mapped[$bd][] = $t; $assigned = true; break;
        }
        // synonyms
        foreach ($synonyms as $key => $vals) {
            if ($nCat === $key || $nBd === $key) {
                foreach ($vals as $v) {
                    if ($nCat === $v || $nBd === $v) { $mapped[$bd][] = $t; $assigned = true; break 3; }
                }
            }
        }
    }
    if (!$assigned) $mapped['Misc'][] = $t;
}

echo "\nMapped counts to budget categories:\n";
foreach ($budgetCats as $bd) {
    $c = isset($mapped[$bd]) ? count($mapped[$bd]) : 0;
    echo "- $bd: $c\n";
}
echo "- Misc: " . (isset($mapped['Misc']) ? count($mapped['Misc']) : 0) . "\n";

// show samples for Transportation
$want = 'Transportation';
if (isset($mapped[$want])) {
    echo "\nSample mapped to $want:\n";
    foreach (array_slice($mapped[$want],0,10) as $t) echo "{$t['date']} | {$t['category']} | {$t['description']} | debit={$t['debit']}\n";
} else {
    echo "\nNo transactions mapped to $want\n";
}

?>