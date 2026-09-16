<?php
$budgetFile = __DIR__ . '/Budget-2025.csv';
$transactionFile = __DIR__ . '/Transactions-Combined-2025-12-27-Categorized.csv';

function norm($s) { return strtolower(preg_replace('/[^a-z0-9]+/i','', $s)); }

// load budgetData like index.php
$lines = array_map('trim', file($budgetFile));
$inSection = false; $foundTitle = false; $budgetData = [];
foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, 'WHERE THE MONEY GOES') !== false) { $foundTitle = true; continue; }
    if ($foundTitle && strpos($line, 'Category,Amount,') !== false) { $inSection = true; continue; }
    if ($inSection && strpos($line, '===========================================') !== false) { $inSection = false; break; }
    if ($inSection && strpos($line, ',') !== false) {
        $parts = str_getcsv($line);
        if (count($parts) >= 3 && is_numeric(str_replace(['$' , ','], '', $parts[1]))) {
            $budgetData[] = $parts;
        }
    }
}
// fallback if budgetData empty: collect numeric second field anywhere
if (empty($budgetData)) {
    foreach (array_map('rtrim', file($budgetFile)) as $line) {
        if (strpos($line, ',') === false) continue;
        $parts = str_getcsv($line);
        if (count($parts) >= 2) {
            $amt = str_replace(['$',' ',','], '', $parts[1]);
            if (is_numeric($amt)) $budgetData[] = $parts;
        }
    }
}

// load transactions
$tlines = array_map('trim', file($transactionFile));
$headers = str_getcsv(array_shift($tlines));
$transactions = [];
foreach ($tlines as $line) {
    if ($line=='') continue;
    $p = str_getcsv($line);
    if (count($p) < 8) continue;
    $transactions[] = ['account'=>$p[0],'debit'=>$p[2],'credit'=>$p[3],'date'=>$p[5],'description'=>$p[6],'category'=>$p[7]];
}

// initial mapping (transactionsByCategory)
$transactionsByCategory = [];
foreach ($transactions as $trans) {
    $budgetCat = 'Misc';
    $cat = $trans['category']; $desc = $trans['description'];
    if (stripos($cat, 'Income/') === 0 || $cat == 'Payroll/Income') continue;
    if (stripos($desc, 'FNMA') !== false || stripos($desc, 'FANNIE') !== false || stripos($desc, '4604') !== false) {
        $budgetCat = 'Housing';
    } elseif (stripos($desc, '4190') !== false || stripos($desc, '4620') !== false) {
        $budgetCat = 'Vehicles';
    } elseif ($cat == 'Insurance') {
        $budgetCat = 'Insurance';
    } elseif (stripos($cat, 'Utilities/') === 0) {
        $budgetCat = 'Utilities';
    } elseif ($cat == 'Groceries' || stripos($cat, 'Groceries/') === 0) {
        $budgetCat = 'Groceries';
    } elseif ($cat == 'Gas/Fuel') {
        $budgetCat = 'Gas';
    } elseif ($cat == 'Fast Food/Dining' || $cat == 'Dining Out') {
        $budgetCat = 'Dining';
    } elseif ($cat == 'Online Shopping') {
        $budgetCat = 'Misc';
    } elseif ($cat == 'Subscriptions' || $cat == 'Memberships') {
        $budgetCat = 'Subscriptions/Memberships';
    } elseif ($cat == 'Home Improvement' || $cat == 'Farm/Home Supply') {
        $budgetCat = 'Household';
    } elseif ($cat == 'Personal Care' || $cat == 'Entertainment') {
        $budgetCat = 'Personal';
    } elseif ($cat == 'Bank Fees' || $cat == 'ATM Withdrawal') {
        $budgetCat = 'Misc';
    } elseif ($cat == 'Charitable/Religious') {
        $budgetCat = 'Giving';
    } elseif (strtolower($cat) == 'education' || strtolower($cat) == 'tuition') {
        $budgetCat = 'Education (Tuition)';
    } else {
        if (stripos($desc, 'Tuition') !== false) $budgetCat = 'Education (Tuition)';
        elseif (stripos($desc, 'Golay') !== false) $budgetCat = 'Subscriptions/Memberships';
        elseif (stripos($desc, 'PTC') !== false) $budgetCat = 'Giving';
    }
    if (!isset($transactionsByCategory[$budgetCat])) $transactionsByCategory[$budgetCat] = [];
    $transactionsByCategory[$budgetCat][] = $trans;
}

echo "TransactionsByCategory keys:\n";
foreach ($transactionsByCategory as $k=>$v) echo "- $k: " . count($v) . "\n";

// Remap as index.php
$transactionsByBudgetCategory = [];
foreach ($budgetData as $bd) $transactionsByBudgetCategory[$bd[0]] = [];
$transactionsByBudgetCategory['Misc'] = $transactionsByBudgetCategory['Misc'] ?? [];

$synonyms = [ 'gas'=>['transportation','transport','gasfuel'], 'transportation'=>['gas','gasfuel','fuel'], 'groceries'=>['grocerieskroger','grocerieswholesale','grocerieswalmart','groc'] ];

foreach ($transactionsByCategory as $mappedName => $tlist) {
    foreach ($tlist as $trans) {
        $assigned = false;
        foreach ($budgetData as $bd) {
            $bdName = $bd[0];
            $nMapped = norm($mappedName);
            $nBd = norm($bdName);
            $nDesc = norm($trans['description']);
            $nCat = norm($trans['category']);
            $match = false;
            if ($nMapped === $nBd) $match = true;
            if (!$match && (strpos($nMapped, $nBd) !== false || strpos($nBd, $nMapped) !== false)) $match = true;
            if (!$match && (strpos($nDesc, $nBd) !== false || strpos($nCat, $nBd) !== false)) $match = true;
            if (!$match) {
                foreach ($synonyms as $key=>$vals) {
                    if ($nMapped === $key || $nBd === $key) {
                        foreach ($vals as $v) {
                            if ($nMapped === $v || $nBd === $v) { $match = true; break 3; }
                        }
                    }
                }
            }
            if (!$match && $nMapped === 'gas' && $nBd === 'transportation') $match = true;
            if ($match) { $transactionsByBudgetCategory[$bdName][] = $trans; $assigned = true; break; }
        }
        if (!$assigned) $transactionsByBudgetCategory['Misc'][] = $trans;
    }
}

echo "\nRemapped counts to budget categories:\n";
foreach ($budgetData as $bd) { $name = $bd[0]; $c = isset($transactionsByBudgetCategory[$name])?count($transactionsByBudgetCategory[$name]):0; echo "- $name: $c\n"; }
echo "- Misc: " . count($transactionsByBudgetCategory['Misc']) . "\n";

echo "\nSample Misc entries containing 'gas' text:\n";
foreach ($transactionsByBudgetCategory['Misc'] as $t) {
    if (stripos($t['description'],'gas') !== false || stripos($t['category'],'Gas') !== false) echo "{$t['date']} | {$t['category']} | {$t['description']} | debit={$t['debit']}\n";
}

?>