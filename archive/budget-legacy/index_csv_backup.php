<?php
error_reporting(E_ALL);

// Handle budget editing
// Management actions: add category, update income sources
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    // determine budget file path (same logic used elsewhere)
    $budgetPathA = '/Users/mains/www/Budget/budget.csv';
    $budgetPathB = '/Users/mains/www/Budget/Budget-2025.csv';
    if (!file_exists($budgetPathA) && file_exists($budgetPathB)) {
        copy($budgetPathB, $budgetPathA);
    }
    $path = file_exists($budgetPathA) ? $budgetPathA : $budgetPathB;

    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($action === 'add_category') {
        $newCat = trim($_POST['new_cat'] ?? '');
        $newAmt = trim($_POST['new_amt'] ?? '');
        if ($newCat !== '' && is_numeric(str_replace([',','$'], '', $newAmt))) {
            // find WHERE THE MONEY GOES section and insert before its divider
            $out = [];
            $inWhere = false;
            $inserted = false;
            foreach ($lines as $line) {
                $out[] = $line;
                if (!$inWhere && stripos($line, 'WHERE THE MONEY GOES') !== false) {
                    $inWhere = true;
                }
                if ($inWhere && (stripos($line, 'Category,Amount') !== false || stripos($line, 'Category,Amount,') !== false)) {
                    // after header, keep appending until divider; insertion will happen when we hit divider
                    continue;
                }
                if ($inWhere && strpos($line, '===========================================') !== false && !$inserted) {
                    // insert new category line just before divider
                    $out[count($out)-1] = $newCat . ',' . $newAmt; // replace divider placeholder with new line temporarily
                    $out[] = $line; // re-add divider
                    $inserted = true;
                }
            }
            if ($inserted) {
                file_put_contents($path, implode("\n", $out) . "\n");
            }
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($action === 'update_income') {
        // replace the INCOME (Monthly) block with user-provided content
        $incomeBlock = trim($_POST['income_block'] ?? '');
        if ($incomeBlock !== '') {
            $out = [];
            $inIncome = false;
            $replaced = false;
            foreach ($lines as $line) {
                if (!$inIncome && stripos($line, 'INCOME (Monthly)') !== false) {
                    $inIncome = true;
                    $out[] = $line;
                    // now append the user's income block (multiple lines)
                    $blockLines = preg_split('/\r\n|\r|\n/', $incomeBlock);
                    foreach ($blockLines as $bline) {
                        $out[] = $bline;
                    }
                    $replaced = true;
                    continue;
                }
                if ($inIncome && strpos($line, '===========================================') !== false) {
                    // close income section and continue adding the rest
                    $inIncome = false;
                    $out[] = $line;
                    continue;
                }
                if ($inIncome) {
                    // skip original income lines
                    continue;
                }
                $out[] = $line;
            }
            if ($replaced) {
                file_put_contents($path, implode("\n", $out) . "\n");
            }
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// existing single-category edit handler (kept for backward compatibility)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['old_category'])) {
    $oldCat = trim($_POST['old_category']);
    $newCat = trim($_POST['new_category']);
    $newAmt = trim($_POST['new_amount']);

    // determine canonical budget file (use budget.csv for rolling budget; fall back)
    $budgetPathA = '/Users/mains/www/Budget/budget.csv';
    $budgetPathB = '/Users/mains/www/Budget/Budget-2025.csv';
    if (!file_exists($budgetPathA) && file_exists($budgetPathB)) {
        // create a rolling budget copy if not present
        copy($budgetPathB, $budgetPathA);
    }
    $path = file_exists($budgetPathA) ? $budgetPathA : $budgetPathB;

    $lines = file($path);
    $out = [];
    $inSection = false;
    $foundWhere = false;
    $updated = false;
    $sectionLines = [];

    // helper to normalize strings for comparison
    $norm = function($s) {
        return strtolower(preg_replace('/[^a-z0-9]+/i','', $s));
    };

    foreach ($lines as $line) {
        $trim = rtrim($line, "\r\n");
        // detect WHERE THE MONEY GOES header
        if (strpos($trim, 'WHERE THE MONEY GOES') !== false) {
            $foundWhere = true;
        }
        // start section only after header
        if ($foundWhere && (stripos($trim, 'Category,Amount') !== false || stripos($trim, 'Category,Amount,') !== false)) {
            $inSection = true;
            $out[] = $trim;
            continue;
        }

        if ($inSection && strpos($trim, '===========================================') !== false) {
            // process collected section lines and replace if needed
            $processed = [];
            foreach ($sectionLines as $sline) {
                if (strpos($sline, ',') !== false && stripos($sline, 'Category,Amount') === false) {
                    $parts = str_getcsv($sline);
                    $catName = trim($parts[0]);
                    $amtOrig = isset($parts[1]) ? $parts[1] : '';

                    // if old category matches (normalized), update
                    if ($norm($catName) === $norm($oldCat)) {
                        if ($newCat !== '') {
                            $catName = $newCat;
                        }
                        // if newAmt provided and numeric, use it; otherwise keep original
                        if ($newAmt !== '' && is_numeric($newAmt)) {
                            $amtUse = $newAmt;
                        } else {
                            $amtUse = $amtOrig;
                        }
                        $updated = true;
                    } else {
                        $amtUse = $amtOrig;
                    }
                    // do NOT write percentage into CSV; keep Category,Amount format
                    $processed[] = $catName . ',' . $amtUse;
                } else {
                    $processed[] = $sline;
                }
            }

            // append processed section and the divider line
            foreach ($processed as $pl) $out[] = $pl;
            $out[] = $trim;
            // append the rest of file unchanged
            $inSection = false;
            $sectionLines = [];
            continue;
        }

        if ($inSection) {
            $sectionLines[] = $trim;
            continue;
        }

        $out[] = $trim;
    }

    if ($updated) {
        file_put_contents($path, implode("\n", $out) . "\n");
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Dashboard</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            padding: 30px;
            backdrop-filter: blur(10px);
        }
        h1 {
            text-align: center;
            color: #2c3e50;
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
        }
        .timestamp {
            text-align: center;
            color: #7f8c8d;
            font-size: 0.9em;
            margin-bottom: 30px;
        }
        .api-data {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 30px;
            font-family: monospace;
            font-size: 0.8em;
        }
        h2 {
            color: #34495e;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
            margin-top: 40px;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }
        th {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background: #f8f9fa;
        }
        tr:hover {
            background: #e3f2fd;
            transform: scale(1.01);
            transition: all 0.2s ease;
        }
        .amount {
            font-weight: bold;
            color: #27ae60;
        }
        .percentage {
            color: #e74c3c;
            font-weight: 500;
        }
        .savings {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: black;
        }
        .expandable {
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .expandable:hover {
            background: #d1ecf1 !important;
        }
        .transaction-row {
            background: #f8f9fa;
        }
        .transaction-row.hidden {
            display: none;
        }
        .transaction-list {
            margin: 10px 0;
        }
        .transaction-list h4 {
            margin: 0 0 10px 0;
            color: #34495e;
        }
        .transaction-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9em;
        }
        .transaction-table th {
            background: #ecf0f1;
            color: #2c3e50;
            padding: 8px;
            text-align: left;
        }
        .transaction-table td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        .transaction-table tr:nth-child(even) {
            background: #f9f9f9;
        }
        .no-transactions {
            padding: 20px;
            text-align: center;
            color: #7f8c8d;
            font-style: italic;
        }
        .signal-dashboard {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        .signal-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-left: 5px solid #ddd;
        }
        .signal-card.pump.strong { border-left-color: #27ae60; }
        .signal-card.pump.moderate { border-left-color: #f39c12; }
        .signal-card.pump.weak { border-left-color: #e74c3c; }
        .signal-card.pump.none { border-left-color: #95a5a6; }
        .signal-card.sell.triggered { border-left-color: #e74c3c; }
        .signal-card.sell.none { border-left-color: #27ae60; }
        .signal-card h5 {
            margin: 0 0 15px 0;
            font-size: 1.2em;
        }
        .signal-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }
        .metric {
            font-size: 0.9em;
            padding: 5px;
            background: #f8f9fa;
            border-radius: 5px;
            text-align: center;
        }
        .signal-recommendation {
            font-weight: bold;
            font-size: 1.1em;
            text-align: center;
            padding: 10px;
            border-radius: 5px;
            background: #ecf0f1;
        }
        .signal-details {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .signal-details h5 {
            margin-top: 0;
        }
        .signal-details ul {
            list-style: none;
            padding: 0;
        }
        .signal-details li {
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        #budget-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        #budget-table th, #budget-table td {
            padding: 8px 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        #budget-table th:nth-child(1), #budget-table td:nth-child(1) {
            width: 40%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        #budget-table th:nth-child(2), #budget-table td:nth-child(2) {
            width: 30%;
        }
        #budget-table th:nth-child(3), #budget-table td:nth-child(3) {
            width: 30%;
        }
        .table-responsive {
            overflow-x: auto;
        }
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            #budget-table {
                font-size: 0.8em;
            }
        }
        .historical-touches {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .historical-touches h3 {
            margin-top: 0;
            color: #34495e;
        }
        .historical-touches ul {
            list-style: none;
            padding: 0;
        }
        .historical-touches li {
            background: #f8f9fa;
            margin: 5px 0;
            padding: 10px;
            border-radius: 5px;
            border-left: 4px solid #3498db;
        }
        .budget-tiles {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .budget-tile {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-left: 5px solid #3498db;
        }
        .budget-tile.savings {
            border-left-color: #27ae60;
        }
        .budget-tile.total {
            border-left-color: #e74c3c;
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
        }
        .budget-tile h3 {
            margin: 0 0 10px 0;
            font-size: 1.2em;
        }
        .budget-tile .amount {
            font-size: 1.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .budget-tile .percentage {
            color: #7f8c8d;
            font-size: 0.9em;
        }
        .btc-toggle {
            text-align: center;
            margin: 20px 0;
        }
        .btc-toggle button {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background 0.3s ease;
        }
        .btc-toggle button:hover {
            background: linear-gradient(135deg, #e67e22, #d35400);
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 400px;
            border-radius: 10px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }
        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        .modal-content form {
            display: flex;
            flex-direction: column;
        }
        .modal-content label {
            margin-top: 10px;
        }
        .modal-content input {
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .modal-content button {
            background: #3498db;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 5px;
            cursor: pointer;
        }
        .modal-content button:hover {
            background: #2980b9;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>MoneyGo Dashboard</h1>
        <div class="timestamp">
<?php
$timestampRecord = date('m-d-Y H:i:s.u', time());
//echo $timestampRecord;
?>
        </div>

        <div class="api-data">
<?php
//exit;

//echo $path; // removed undefined variable

function download_page($path)
{
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $path);
  curl_setopt($ch, CURLOPT_FAILONERROR, 0);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  //curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Accept: application/json'));
  $retValue = curl_exec($ch);
  curl_close($ch);
  return $retValue;
}

$serverAPIDomain = "https://api.taapi.io/ichimoku?secret=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJlbWFpbCI6Ik1BSU5TUk04MUBHTUFJTC5DT00iLCJpYXQiOjE2MzcyNzkzNzcsImV4cCI6Nzk0NDQ3OTM3N30.c6j5vapp_qVLy7fKDgmG1zNpA69aISLeHeFlx7F8M7E&exchange=binance&symbol=BTC/USDT&interval=1d";// Gets Current Term
$json = download_page($serverAPIDomain);
$arr = json_decode($json, true);

// echo '<pre>'.print_r($arr, TRUE).'</pre>';

// Fetch historical prices for last 7 days
// $histPriceUrl = "https://api.coingecko.com/api/v3/coins/bitcoin/market_chart?vs_currency=usd&days=7";
// $histPriceJson = download_page($histPriceUrl);
// $histPriceArr = json_decode($histPriceJson, true);
// $histPrices = $histPriceArr['prices'] ?? [];

// Fetch historical Ichimoku for last 7 days
// $historicalIchimoku = [];
// for ($i = 1; $i <= 7; $i++) {
//     $histUrl = "https://api.taapi.io/ichimoku?secret=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJlbWFpbCI6Ik1BSU5TUk04MUBHTUFJTC5DT00iLCJpYXQiOjE2MzcyNzkzNzcsImV4cCI6Nzk0NDQ3OTM3N30.c6j5vapp_qVLy7fKDgmG1zNpA69aISLeHeFlx7F8M7E&exchange=binance&symbol=BTC/USDT&interval=1d&backtrack=$i";
//     $histJson = download_page($histUrl);
//     $histData = json_decode($histJson, true);
//     if ($histData && !isset($histData['error'])) {
//         $historicalIchimoku[] = $histData;
//     } else {
//         break; // Stop if API error
//     }
// }

// Read budget data (rolling budget.csv preferred)
$budgetPathA = '/Users/mains/www/Budget/budget.csv';
$budgetPathB = '/Users/mains/www/Budget/Budget-2025.csv';
if (!file_exists($budgetPathA) && file_exists($budgetPathB)) {
    copy($budgetPathB, $budgetPathA);
}
$budgetFile = file_exists($budgetPathA) ? $budgetPathA : $budgetPathB;
$lines = file($budgetFile);
$inSection = false;
$foundTitle = false;
$budgetData = [];
$totalIncome = 0.0;

foreach($lines as $line) {
    $line = trim($line);
    // capture total income if present
    if (stripos($line, 'Total Monthly Income') === 0) {
        $parts = str_getcsv($line);
        if (isset($parts[1]) && is_numeric(str_replace(['$', ','], '', $parts[1]))) {
            $totalIncome = (float) str_replace(['$', ','], '', $parts[1]);
        }
    }
    if (strpos($line, 'WHERE THE MONEY GOES') !== false) {
        $foundTitle = true;
        continue;
    }
    // Only start the Category/Amount section after we've found the WHERE THE MONEY GOES title
    if ($foundTitle && (stripos($line, 'Category,Amount') !== false || stripos($line, 'Category,Amount,') !== false)) {
        $inSection = true;
        continue;
    }
    if ($inSection && strpos($line, '===========================================') !== false) {
        $inSection = false;
        break;
    }
    if ($inSection && strpos($line, ',') !== false) {
        $parts = str_getcsv($line);
        if (count($parts) >= 2 && is_numeric(str_replace(['$', ','], '', $parts[1]))) {
            // store only Category and Amount; ignore any percent column
            $budgetData[] = [$parts[0], $parts[1]];
        }
    }
}

// Read transactions
$transactionFile = '/Users/mains/www/Budget/Transactions-Combined-2025-12-27-Categorized.csv';
$transactionLines = file($transactionFile);
$transactions = [];
$headers = str_getcsv(array_shift($transactionLines)); // Skip header

foreach($transactionLines as $line) {
    $parts = str_getcsv($line);
    if (count($parts) >= 8) {
        $transactions[] = [
            'account' => $parts[0],
            'debit' => $parts[2],
            'credit' => $parts[3],
            'date' => $parts[5],
            'description' => $parts[6],
            'category' => $parts[7]
        ];
    }
}

// Mapping transaction categories to budget categories
$transactionsByCategory = [];
foreach ($transactions as $trans) {
    $budgetCat = 'Misc'; // default
    $cat = $trans['category'];
    $desc = $trans['description'];
    
    // Skip income transactions
    if (stripos($cat, 'Income/') === 0 || $cat == 'Payroll/Income') {
        continue;
    }
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
        if (stripos($desc, 'Tuition') !== false) {
            $budgetCat = 'Education (Tuition)';
        } elseif (stripos($desc, 'Golay') !== false) {
            $budgetCat = 'Subscriptions/Memberships';
        } elseif (stripos($desc, 'PTC') !== false) {
            $budgetCat = 'Giving';
        }
    }
    
    if (!isset($transactionsByCategory[$budgetCat])) {
        $transactionsByCategory[$budgetCat] = [];
    }
    $transactionsByCategory[$budgetCat][] = $trans;
}

// Remap transactions into the exact budget categories from the budget file
$transactionsByBudgetCategory = [];
// initialize buckets from budgetData
foreach ($budgetData as $bd) {
    $transactionsByBudgetCategory[$bd[0]] = [];
}
// ensure Misc bucket exists
$transactionsByBudgetCategory['Misc'] = $transactionsByBudgetCategory['Misc'] ?? [];

// NOTE: removed hard-coded Gas->Transportation transfer; rely on normalization/synonyms or persistent mapping

// small synonyms map for common renames
$synonyms = [
    'gas' => ['transportation','transport','gasfuel'],
    'transportation' => ['gas','gasfuel','fuel'],
    'groceries' => ['grocerieskroger','grocerieswholesale','grocerieswalmart','groc'],
];

foreach ($transactionsByCategory as $mappedName => $tlist) {
    foreach ($tlist as $trans) {
        $assigned = false;
        foreach ($budgetData as $bd) {
            $bdName = $bd[0];
            // normalize names for more flexible matching
            $norm = function($s) {
                return strtolower(preg_replace('/[^a-z0-9]+/i','', $s));
            };
            $nMapped = $norm($mappedName);
            $nBd = $norm($bdName);
            $nDesc = $norm($trans['description']);
            $nCat = $norm($trans['category']);

            $match = false;
            if ($nMapped === $nBd) $match = true;
            // substring matches
            if (!$match && (strpos($nMapped, $nBd) !== false || strpos($nBd, $nMapped) !== false)) $match = true;
            // description/category contains bdName
            if (!$match && (strpos($nDesc, $nBd) !== false || strpos($nCat, $nBd) !== false)) $match = true;
            // synonyms
            if (!$match) {
                foreach ($synonyms as $key => $vals) {
                    if ($nMapped === $key || $nBd === $key) {
                        foreach ($vals as $v) {
                            if ($nMapped === $v || $nBd === $v) { $match = true; break 3; }
                        }
                    }
                }
            }
            // no hard-coded special-case mappings here; use synonyms/normalization
            if ($match) {
                $transactionsByBudgetCategory[$bdName][] = $trans;
                $assigned = true;
                break;
            }
        }
        if (!$assigned) {
            $transactionsByBudgetCategory['Misc'][] = $trans;
        }
    }
}

// Calculate total allotted
$totalAllotted = 0;
foreach ($budgetData as $data) {
    $amount = str_replace(['$', ','], '', $data[1]);
    $totalAllotted += (float) $amount;
}

// BTC Analysis Section
echo '<div class="btc-analysis">';
echo '<div class="btc-toggle">';
echo '<button onclick="toggleBTC()">Toggle BTC Analysis</button>';
echo '</div>';
echo '<div id="btc-section" style="display: none;">';

// Get current BTC price from CoinGecko
$priceAPIDomain = "https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd";
$priceJson = download_page($priceAPIDomain);
$priceArr = json_decode($priceJson, true);

$price = $priceArr['bitcoin']['usd'] ?? null;
if (is_numeric($price)) {
    echo '<h3>Current BTC/USDT Price: $' . number_format($price, 2) . '</h3>';
    
    if ($arr && !isset($arr['error'])) {
    
    // Analyze Ichimoku pump signal requirements
    $signals = [];
    
    // 1. Cloud is Green
    if ($arr['spanA'] > $arr['spanB']) {
        $signals[] = "✅ Cloud is green (spanA > spanB)";
    } else {
        $signals[] = "❌ Cloud is red (spanA < spanB)";
    }
    
    // 2. Price action is above the cloud
    if ($price > $arr['spanA']) {
        $signals[] = "✅ Price above cloud";
    } else {
        $signals[] = "❌ Price below cloud";
    }
    
    // 3. We have a conversion/baseline cross
    if ($arr['conversion'] > $arr['base']) {
        $extra = ($arr['conversion'] > $arr['spanA']) ? " (above cloud - very bullish)" : " (in/below cloud)";
        $signals[] = "✅ Conversion/baseline cross present" . $extra;
    } else {
        $signals[] = "❌ No conversion/baseline cross";
    }
    
    // 4. Lagging span (Chiku) closes above the cloud
    if ($arr['laggingSpanA'] > $arr['spanA'] && $arr['laggingSpanB'] > $arr['spanB']) {
        $signals[] = "✅ Chiku span above cloud";
    } else {
        $signals[] = "❌ Chiku span below cloud";
    }
    
    // Overall recommendation
    $validCount = substr_count(implode('', $signals), '✅');
    if ($validCount == 4) {
        $recommendation = "🚀 STRONG PUMP SIGNAL (All 4 conditions met) - Price likely heading to next resistance";
    } elseif ($validCount == 3) {
        $recommendation = "⚠️ MODERATE PUMP SIGNAL (3 of 4 conditions met) - Potential move to resistance";
    } elseif ($validCount >= 2) {
        $recommendation = "🟡 WEAK PUMP SIGNAL (2 of 4 conditions met) - Watch for resistance breakout";
    } else {
        $recommendation = "🔴 NO BULLISH SIGNAL (0-1 of 4 conditions met) - No upward momentum expected";
    }
    
    // Analyze Ichimoku sell signal requirements
    $sellSignals = [];
    
    // Check if Chiku span touches price action
    // This is a bit interpretive - checking if Chiku is close to price (within a threshold)
    $threshold = $price * 0.01; // 1% threshold for "touches"
    $chikuTouches = (abs($arr['laggingSpanA'] - $price) < $threshold) || (abs($arr['laggingSpanB'] - $price) < $threshold);
    if ($chikuTouches) {
        $sellSignals[] = "⚠️ Chiku span touches price action";
    } else {
        $sellSignals[] = "✅ Chiku span does not touch price action";
    }
    
    // Check if conversion crosses baseline (bearish)
    if ($arr['conversion'] < $arr['base']) {
        $sellSignals[] = "⚠️ Conversion below baseline (death cross)";
    } else {
        $sellSignals[] = "✅ Conversion above baseline";
    }
    
    // Sell signal if either condition is true
    $sellTriggered = $chikuTouches || ($arr['conversion'] < $arr['base']);
    if ($sellTriggered) {
        $sellRecommendation = "🔴 SELL SIGNAL TRIGGERED (Exit position)";
    } else {
        $sellRecommendation = "✅ NO SELL SIGNAL";
    }
    
    echo '<h4>Ichimoku Signal Dashboard (1-Day Timeframe)</h4>';
    echo '<div class="signal-dashboard">';
    
    // Pump Signal Summary
    $pumpStatus = ($validCount == 4) ? 'strong' : (($validCount == 3) ? 'moderate' : (($validCount >= 2) ? 'weak' : 'none'));
    echo '<div class="signal-card pump ' . $pumpStatus . '">';
    echo '<h5>🚀 Pump Signal</h5>';
    echo '<div class="signal-metrics">';
    echo '<div class="metric">Cloud Green: ' . ($arr['spanA'] > $arr['spanB'] ? '✅' : '❌') . '</div>';
    echo '<div class="metric">Price > Cloud: ' . ($price > $arr['spanA'] ? '✅' : '❌') . '</div>';
    echo '<div class="metric">Cross Present: ' . ($arr['conversion'] > $arr['base'] ? '✅' : '❌') . '</div>';
    echo '<div class="metric">Chiku > Cloud: ' . (($arr['laggingSpanA'] > $arr['spanA'] && $arr['laggingSpanB'] > $arr['spanB']) ? '✅' : '❌') . '</div>';
    echo '</div>';
    echo '<div class="signal-recommendation">' . $recommendation . '</div>';
    echo '</div>';
    
    // Sell Signal Summary
    $sellStatus = $sellTriggered ? 'triggered' : 'none';
    echo '<div class="signal-card sell ' . $sellStatus . '">';
    echo '<h5>🔴 Sell Signal</h5>';
    echo '<div class="signal-metrics">';
    echo '<div class="metric">Chiku Touches Price: ' . ($chikuTouches ? '⚠️' : '✅') . '</div>';
    echo '<div class="metric">Death Cross: ' . ($arr['conversion'] < $arr['base'] ? '⚠️' : '✅') . '</div>';
    echo '</div>';
    echo '<div class="signal-recommendation">' . $sellRecommendation . '</div>';
    echo '</div>';
    
    echo '</div>';
    
    echo '<div class="signal-details">';
    echo '<h5>Key Indicators</h5>';
    echo '<ul>';
    echo '<li><strong>Price:</strong> $' . number_format($price, 2) . '</li>';
    echo '<li><strong>Cloud:</strong> ' . number_format($arr['spanA'], 2) . ' - ' . number_format($arr['spanB'], 2) . ' (' . ($arr['spanA'] > $arr['spanB'] ? 'Green' : 'Red') . ')</li>';
    echo '<li><strong>Conversion/Base:</strong> ' . number_format($arr['conversion'], 2) . ' / ' . number_format($arr['base'], 2) . '</li>';
    echo '<li><strong>Chiku:</strong> ' . number_format($arr['laggingSpanA'], 2) . ' / ' . number_format($arr['laggingSpanB'], 2) . '</li>';
    echo '</ul>';
    echo '</div>';
    
    } else {
        echo '<p>Ichimoku data currently unavailable due to API rate limit.</p>';
    }
}
echo '</div>'; // Close btc-section
echo '</div>'; // Close btc-analysis

// if (!empty($historicalIchimoku)) {
//     echo '<div class="historical-touches">';
//     echo '<h3>Historical Chiku Touches (Last 7 Days)</h3>';
//     $touches = [];
//     foreach ($historicalIchimoku as $index => $ich) {
//         if (isset($histPrices[$index])) {
//             $date = date('Y-m-d', $histPrices[$index][0] / 1000);
//             $price = $histPrices[$index][1];
//             $threshold = $price * 0.01; // 1% threshold
//             if (abs($ich['laggingSpanA'] - $price) < $threshold || abs($ich['laggingSpanB'] - $price) < $threshold) {
//                 $touches[] = $date;
//             }
//         }
//     }
//     if (!empty($touches)) {
//         echo '<ul>';
//         foreach ($touches as $date) {
//             echo '<li>' . $date . '</li>';
//         }
//         echo '</ul>';
//     } else {
//         echo '<p>No Chiku touches detected in the last 7 days.</p>';
//     }
?>
        </div>

<?php
// Display budget tiles
echo '<h2>Where the Money Goes</h2>';
echo '<div class="budget-tiles">';
echo '<button class="budget-tile manage-btn" onclick="openManage()"><h3 style="margin:0;">Manage Budget</h3></button>';
foreach ($budgetData as $data) {
    $category = $data[0];
    $amount = (float) str_replace(['$', ','], '', $data[1]);
    $pct = ($totalIncome > 0) ? number_format(($amount / $totalIncome) * 100, 1) . '%' : '0.0%';
    $rowClass = (strpos($category, 'SAVINGS') !== false) ? 'savings' : '';
    echo '<div class="budget-tile ' . $rowClass . '" data-category="' . htmlspecialchars($category) . '" data-amount="' . $amount . '" onclick="editCategory(this)">';
    echo '<h3>' . $category . '</h3>';
    echo '<div class="amount">$' . number_format($amount, 2) . '</div>';
    echo '<div class="percentage">' . $pct . ' of income</div>';
    echo '</div>';
}
echo '<div class="budget-tile total">';
echo '<h3>Total Allotted</h3>';
echo '<div class="amount">$' . number_format($totalAllotted, 2) . '</div>';
if ($totalIncome > 0) {
    echo '<div class="percentage">' . number_format(($totalAllotted / $totalIncome) * 100, 1) . '% of income</div>';
} else {
    echo '<div class="percentage">N/A</div>';
}
echo '</div>';
echo '</div>';
?>

        <h2>Where the Money Went</h2>
        <div class="table-responsive">
        <table id="budget-table">
            <tr>
                <th>Category</th>
                <th>Monthly Allotment</th>
                <th>Budget Variance</th>
            </tr>
<?php
foreach($budgetData as $index => $data) {
    $category = $data[0];
    $budgeted = str_replace(['$', ','], '', $data[1]);
    $rowClass = (strpos($category, 'SAVINGS') !== false) ? 'savings' : '';
    $transList = isset($transactionsByBudgetCategory[$category]) ? $transactionsByBudgetCategory[$category] : [];
    $actualSum = 0;
    foreach ($transList as $trans) {
        if ($trans['debit']) {
            $actualSum += (float) $trans['debit'];
        }
    }
    if ($category == 'Education (Tuition)') {
        echo "<!-- Debug: Education (Tuition) - Sum: $actualSum, Transactions: " . count($transList) . " -->\n";
        foreach ($transList as $t) {
            echo "<!-- Debug trans: {$t['description']} - Debit: {$t['debit']}, Category: {$t['category']} -->\n";
        }
    }
    // Monthly Allotment column shows total spent for the category
    $amount = '$' . number_format($actualSum, 2);
    // Budget variance = (actual - budgeted) / budgeted
    if ($budgeted > 0) {
        $variance = (($actualSum - (float)$budgeted) / (float)$budgeted) * 100;
        $percentage = ($variance > 0 ? '+' : '') . number_format($variance, 1) . '%';
    } else {
        $percentage = 'N/A';
    }
    echo "<tr class='expandable $rowClass' onclick='toggleTransactions($index)'>";
    echo "<td>$category</td>";
    echo "<td class='amount'>$amount</td>";
    echo "<td class='percentage'>$percentage</td>";
    echo "</tr>";
    echo "<tr id='transactions-$index' class='transaction-row hidden'>";
    echo "<td colspan='3'>";
    if (empty($transList)) {
        echo "<div class='no-transactions'>No transactions found for this category.</div>";
    } else {
        echo "<div class='transaction-list'>";
        echo "<h4>Recent Transactions</h4>";
        echo "<table class='transaction-table'>";
        echo "<tr><th>Date</th><th>Description</th><th>Amount</th></tr>";
        foreach ($transList as $trans) {
            $transAmount = $trans['debit'] ? "-$" . $trans['debit'] : "+$" . $trans['credit'];
            echo "<tr><td>{$trans['date']}</td><td>{$trans['description']}</td><td>$transAmount</td></tr>";
        }
        echo "</table>";
        echo "</div>";
    }
    echo "</td>";
    echo "</tr>";
}
?>
        </table>
        </div>
    </div>
    <script>
        function toggleTransactions(index) {
            const row = document.getElementById('transactions-' + index);
            row.classList.toggle('hidden');
        }
        function toggleBTC() {
            const section = document.getElementById('btc-section');
            const container = document.querySelector('.container');
            if (section.style.display === 'none') {
                section.style.display = 'block';
                container.style.minHeight = '2500px';
            } else {
                section.style.display = 'none';
                container.style.minHeight = 'auto';
            }
        }
        function toggleEdit() {
            const section = document.getElementById('edit-budget');
            section.style.display = section.style.display === 'none' ? 'block' : 'none';
        }
        function addRow() {
            const container = document.getElementById('budget-rows');
            const div = document.createElement('div');
            div.className = 'edit-row';
            div.style.marginBottom = '10px';
            div.innerHTML = '<input type="text" name="categories[]" placeholder="Category" style="width: 200px; margin-right: 10px;"><input type="number" step="0.01" name="amounts[]" placeholder="Amount" style="width: 100px; margin-right: 10px;"><button type="button" onclick="removeRow(this)">Remove</button>';
            container.appendChild(div);
        }
        function removeRow(btn) {
            btn.parentElement.remove();
        }
    </script>
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEdit()">&times;</span>
            <h2>Edit Budget Category</h2>
            <form method="POST">
                <input type="hidden" id="oldCategory" name="old_category" value="">
                <label for="newCategory">Category:</label>
                <input type="text" id="newCategory" name="new_category" required>
                <label for="newAmount">Amount:</label>
                <input type="number" id="newAmount" name="new_amount" step="0.01" required>
                <button type="submit">Save Changes</button>
            </form>
        </div>
    </div>
    <div id="manageModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeManage()">&times;</span>
            <h2>Manage Budget</h2>
            <h3>Add Category</h3>
            <form method="POST" style="margin-bottom:15px;">
                <input type="hidden" name="action" value="add_category">
                <label>Category:</label>
                <input type="text" name="new_cat" required>
                <label>Amount:</label>
                <input type="number" step="0.01" name="new_amt" required>
                <div style="margin-top:10px;"><button type="submit">Add Category</button></div>
            </form>

            <h3>Update Income Sources</h3>
            <p>Edit the INCOME (Monthly) block lines (Category,Amount or totals). Paste multiple lines if needed.</p>
            <form method="POST">
                <input type="hidden" name="action" value="update_income">
                <textarea name="income_block" rows="8" style="width:100%;"><?php
                    // prefill with existing income block for convenience
                    $incLines = [];
                    $raw = file($budgetFile);
                    $inIncome = false;
                    foreach ($raw as $l) {
                        if (!$inIncome && stripos($l, 'INCOME (Monthly)') !== false) { $inIncome = true; continue; }
                        if ($inIncome && strpos($l, '===========================================') !== false) { break; }
                        if ($inIncome) { $incLines[] = trim($l); }
                    }
                    echo htmlspecialchars(implode("\n", $incLines));
                ?></textarea>
                <div style="margin-top:10px;"><button type="submit">Update Income</button></div>
            </form>
        </div>
    </div>
    <script>
        function editCategory(tile) {
            const category = tile.getAttribute('data-category');
            const amount = tile.getAttribute('data-amount');
            document.getElementById('oldCategory').value = category;
            document.getElementById('newCategory').value = category;
            document.getElementById('newAmount').value = amount;
            document.getElementById('editModal').style.display = 'block';
        }
        function closeEdit() {
            document.getElementById('editModal').style.display = 'none';
        }
        function openManage() {
            document.getElementById('manageModal').style.display = 'block';
        }
        function closeManage() {
            document.getElementById('manageModal').style.display = 'none';
        }
    </script>
</body>
</html>