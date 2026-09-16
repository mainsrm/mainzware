<?php
/**
 * Budget Dashboard - Database Version
 * 
 * This is the main budget dashboard that reads from PostgreSQL database
 * instead of CSV files.
 */
error_reporting(E_ALL);
include '../CMS/Connect_to_Postgres.php';

// Handle budget editing actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add_category') {
        $newCat = trim($_POST['new_cat'] ?? '');
        $newAmt = floatval(str_replace(['$', ','], '', $_POST['new_amt'] ?? '0'));
        if ($newCat !== '' && $newAmt > 0) {
            // Get max sort order
            $result = pg_query($database_connection, "SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM budget_categories");
            $row = pg_fetch_assoc($result);
            $sortOrder = $row['next_order'];
            
            pg_query_params($database_connection,
                "INSERT INTO budget_categories (name, budgeted_amount, sort_order) VALUES ($1, $2, $3) 
                 ON CONFLICT (name) DO UPDATE SET budgeted_amount = $2",
                [$newCat, $newAmt, $sortOrder]
            );
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if ($action === 'update_category') {
        $oldCat = trim($_POST['old_category'] ?? '');
        $newCat = trim($_POST['new_category'] ?? '');
        $newAmt = floatval(str_replace(['$', ','], '', $_POST['new_amount'] ?? '0'));
        
        if ($oldCat !== '') {
            if ($newCat !== '' && $newCat !== $oldCat) {
                // Rename category
                pg_query_params($database_connection,
                    "UPDATE budget_categories SET name = $1, budgeted_amount = $2, updated_at = NOW() WHERE name = $3",
                    [$newCat, $newAmt, $oldCat]
                );
                // Update transactions referencing old category
                pg_query_params($database_connection,
                    "UPDATE transactions SET budget_category = $1 WHERE budget_category = $2",
                    [$newCat, $oldCat]
                );
            } else {
                // Just update amount
                pg_query_params($database_connection,
                    "UPDATE budget_categories SET budgeted_amount = $1, updated_at = NOW() WHERE name = $2",
                    [$newAmt, $oldCat]
                );
            }

        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if ($action === 'delete_category') {
        $catName = trim($_POST['category_name'] ?? '');
        if ($catName !== '') {
            pg_query_params($database_connection,
                "DELETE FROM budget_categories WHERE name = $1",
                [$catName]
            );
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if ($action === 'update_income') {
        // Parse income block text and update database
        $incomeBlock = trim($_POST['income_block'] ?? '');
        if ($incomeBlock !== '') {
            // Clear existing income
            pg_query($database_connection, "TRUNCATE income RESTART IDENTITY");
            
            $lines = preg_split('/\r\n|\r|\n/', $incomeBlock);
            foreach ($lines as $line) {
                $parts = str_getcsv($line);
                if (count($parts) >= 2) {
                    $category = trim($parts[0]);
                    $amount = floatval(str_replace(['$', ','], '', $parts[1]));
                    $pct = isset($parts[2]) ? floatval(str_replace('%', '', $parts[2])) : 0;
                    
                    if ($category !== '' && $amount > 0 && stripos($category, 'Total') === false) {
                        pg_query_params($database_connection,
                            "INSERT INTO income (category, amount, percentage) VALUES ($1, $2, $3)",
                            [$category, $amount, $pct]
                        );
                    }
                }
            }
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Also handle old-style category edit (for backward compatibility)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['old_category']) && !isset($_POST['action'])) {
    $oldCat = trim($_POST['old_category']);
    $newCat = trim($_POST['new_category']);
    $newAmt = floatval(str_replace(['$', ','], '', $_POST['new_amount'] ?? '0'));
    
    if ($oldCat !== '' && $newAmt > 0) {
        if ($newCat !== '' && $newCat !== $oldCat) {
            pg_query_params($database_connection,
                "UPDATE budget_categories SET name = $1, budgeted_amount = $2, updated_at = NOW() WHERE name = $3",
                [$newCat, $newAmt, $oldCat]
            );
            pg_query_params($database_connection,
                "UPDATE transactions SET budget_category = $1 WHERE budget_category = $2",
                [$newCat, $oldCat]
            );
        } else {
            pg_query_params($database_connection,
                "UPDATE budget_categories SET budgeted_amount = $1, updated_at = NOW() WHERE name = $2",
                [$newAmt, $oldCat]
            );
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ============================================================
// Fetch data from database
// ============================================================

// Get total income
$result = pg_query($database_connection, "SELECT SUM(amount) as total FROM income");
$row = pg_fetch_assoc($result);
$totalIncome = floatval($row['total'] ?? 0);

// Get income details
$incomeData = [];
$result = pg_query($database_connection, "SELECT * FROM income ORDER BY id");
while ($row = pg_fetch_assoc($result)) {
    $incomeData[] = $row;
}

// Get budget categories (Where the Money Goes)
$budgetData = [];
$result = pg_query($database_connection, "SELECT * FROM budget_categories ORDER BY sort_order, name");
while ($row = pg_fetch_assoc($result)) {
    $budgetData[] = [
        'name' => $row['name'],
        'amount' => floatval($row['budgeted_amount']),
        'is_savings' => $row['is_savings'] === 't'
    ];
}

// Calculate total allotted
$totalAllotted = 0;
foreach ($budgetData as $data) {
    $totalAllotted += $data['amount'];
}

// Get transactions for display - use most recent month with data
// First, find the most recent month that has transactions
$result = pg_query($database_connection, 
    "SELECT DATE_TRUNC('month', date) as month FROM transactions 
     WHERE debit IS NOT NULL AND debit > 0 
     ORDER BY date DESC LIMIT 1"
);
$row = pg_fetch_assoc($result);
if ($row && $row['month']) {
    $displayMonth = date('Y-m-01', strtotime($row['month']));
    $displayMonthEnd = date('Y-m-01', strtotime($displayMonth . ' +1 month'));
    $displayMonthName = date('F Y', strtotime($displayMonth));
} else {
    // Fallback to current month if no transactions
    $displayMonth = date('Y-m-01');
    $displayMonthEnd = date('Y-m-01', strtotime('+1 month'));
    $displayMonthName = date('F Y');
}

$transactionsByCategory = [];
foreach ($budgetData as $bd) {
    $transactionsByCategory[$bd['name']] = [];
}
$transactionsByCategory['Misc'] = [];
$transactionsByCategory['Uncategorized'] = [];

// Category mapping: transaction category => budget category
$categoryMap = [
    // Vehicles (car payments/loans)
    'auto loan' => 'Vehicles',
    'car payment' => 'Vehicles',
    'vehicle' => 'Vehicles',
    
    // Transportation (gas/fuel)
    'gas/fuel' => 'Transportation',
    'fuel' => 'Transportation',
    'gas' => 'Transportation',
    'parking' => 'Transportation',
    
    // Dining
    'dining out' => 'Dining',
    'fast food/dining' => 'Dining',
    'fast food' => 'Dining',
    'restaurant' => 'Dining',
    
    // Groceries
    'groceries' => 'Groceries',
    'groceries/retail' => 'Groceries',
    'groceries/wholesale' => 'Groceries',
    'groceries/convenience' => 'Groceries',
    'groceries/local' => 'Groceries',
    
    // Housing
    'loan payment' => 'Housing',
    'mortgage' => 'Housing',
    'rent' => 'Housing',
    
    // Utilities
    'utilities/electric' => 'Utilities',
    'utilities/internet' => 'Utilities',
    'utilities/phone' => 'Utilities',
    'utilities/gas' => 'Utilities',
    'utilities/water' => 'Utilities',
    
    // Subscriptions/Memberships
    'subscriptions' => 'Subscriptions Memberships',
    'memberships' => 'Subscriptions Memberships',
    'recurring payment' => 'Subscriptions Memberships',
    
    // Insurance
    'insurance' => 'Insurance',
    
    // Giving
    'charitable/religious' => 'Giving',
    'donation' => 'Giving',
    'charity' => 'Giving',
    
    // Personal
    'personal care' => 'Personal',
    'personal payments' => 'Personal',
    'shopping/clothing' => 'Personal',
    'atm withdrawal' => 'Personal',
    
    // Household
    'home improvement' => 'Household',
    'farm/home supply' => 'Household',
    'discount/retail' => 'Household',
    'convenience store' => 'Household',
    
    // Transportation
    'transportation' => 'Transportation',
    'uber' => 'Transportation',
    'lyft' => 'Transportation',
    
    // Misc
    'online shopping' => 'Misc',
    'entertainment' => 'Misc',
    'bank fees' => 'Misc',
    'bank transfer' => 'Misc',
    'credit card payment' => 'Misc',
    'local services' => 'Misc',
];

$result = pg_query_params($database_connection, 
    "SELECT * FROM transactions 
     WHERE date >= $1 AND date < $2 
     AND (debit IS NOT NULL AND debit > 0)
     ORDER BY date DESC",
    [$displayMonth, $displayMonthEnd]
);

while ($row = pg_fetch_assoc($result)) {
    // Use budget_category first, then fall back to category field
    $txnCat = !empty($row['budget_category']) ? $row['budget_category'] : ($row['category'] ?? 'Uncategorized');
    $txnCatLower = strtolower($txnCat);
    
    // First check explicit mapping
    $matched = false;
    if (isset($categoryMap[$txnCatLower])) {
        $budgetCat = $categoryMap[$txnCatLower];
        if (isset($transactionsByCategory[$budgetCat])) {
            $transactionsByCategory[$budgetCat][] = $row;
            $matched = true;
        }
    }
    
    // If no explicit mapping, try flexible matching
    if (!$matched) {
        foreach ($budgetData as $bd) {
            $budgetName = strtolower($bd['name']);
            
            // Exact match
            if ($budgetName === $txnCatLower) {
                $transactionsByCategory[$bd['name']][] = $row;
                $matched = true;
                break;
            }
            
            // Partial match
            if (strpos($txnCatLower, $budgetName) !== false || strpos($budgetName, $txnCatLower) !== false) {
                $transactionsByCategory[$bd['name']][] = $row;
                $matched = true;
                break;
            }
        }
    }
    
    if (!$matched) {
        $transactionsByCategory['Misc'][] = $row;
    }
}

// ============================================================
// Download function for BTC API (kept from original)
// ============================================================
function download_page($path) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $path);
    curl_setopt($ch, CURLOPT_FAILONERROR, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $retValue = curl_exec($ch);
    curl_close($ch);
    return $retValue;
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
        nav {
            background-color: #444;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        nav ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
        }
        nav li { margin: 0 15px; }
        nav a { color: white; text-decoration: none; }
        nav a:hover { text-decoration: underline; }
        .timestamp {
            text-align: center;
            color: #7f8c8d;
            font-size: 0.9em;
            margin-bottom: 30px;
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
        }
        .amount {
            font-weight: bold;
            color: #27ae60;
        }
        .percentage {
            font-weight: 500;
        }
        .percentage.over { color: #e74c3c; }
        .percentage.under { color: #27ae60; }
        .savings {
            background: linear-gradient(135deg, #27ae60, #2ecc71) !important;
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
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .budget-tile:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }
        .budget-tile.savings {
            border-left-color: #27ae60;
        }
        .budget-tile.total {
            border-left-color: #e74c3c;
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            cursor: default;
        }
        .budget-tile.manage-btn {
            border-left-color: #9b59b6;
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
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
            color: inherit;
        }
        .budget-tile .percentage {
            color: #7f8c8d;
            font-size: 0.9em;
        }
        .budget-tile.total .percentage,
        .budget-tile.manage-btn .percentage {
            color: rgba(255,255,255,0.8);
        }
        .table-responsive {
            overflow-x: auto;
        }
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            h1 { font-size: 1.8em; }
            table { font-size: 0.8em; }
        }
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 90%;
            max-width: 500px;
            border-radius: 10px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover { color: black; }
        .modal-content form {
            display: flex;
            flex-direction: column;
        }
        .modal-content label {
            margin-top: 10px;
            font-weight: bold;
        }
        .modal-content input, .modal-content textarea {
            padding: 10px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1em;
        }
        .modal-content button {
            background: #3498db;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 15px;
            font-size: 1em;
        }
        .modal-content button:hover { background: #2980b9; }
        .btn-danger {
            background: #e74c3c !important;
        }
        .btn-danger:hover { background: #c0392b !important; }
        
        /* Quick stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .stat-card h4 {
            margin: 0 0 10px 0;
            color: #7f8c8d;
            font-size: 0.9em;
        }
        .stat-card .value {
            font-size: 1.8em;
            font-weight: bold;
            color: #2c3e50;
        }
        .stat-card.income .value { color: #27ae60; }
        .stat-card.expense .value { color: #e74c3c; }
        .stat-card.remaining .value { color: #3498db; }
        
        /* BTC Analysis Styles */
        .btc-toggle {
            text-align: center;
            margin: 20px 0;
        }
        .btc-toggle button {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background 0.3s ease;
        }
        .btc-toggle button:hover {
            background: linear-gradient(135deg, #e67e22, #d35400);
        }
        .signal-dashboard {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        @media (max-width: 768px) {
            .signal-dashboard { grid-template-columns: 1fr; }
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
        .signal-card h5 { margin: 0 0 15px 0; font-size: 1.2em; }
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
        .signal-details h5 { margin-top: 0; }
        .signal-details ul { list-style: none; padding: 0; }
        .signal-details li { padding: 5px 0; border-bottom: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="container">
        <h1>💰 MoneyGo Dashboard</h1>
        
        <nav>
            <ul>
                <li><a href="index.php">Dashboard</a></li>
                <li><a href="view_budget.php">Budget Summary</a></li>
                <li><a href="add_transaction.php">Add Transaction</a></li>
                <li><a href="import_transactions.php">Import Transactions</a></li>
            </ul>
        </nav>
        
        <div class="timestamp">
            <?php echo date('F j, Y - g:i A'); ?>
        </div>
        
        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card income">
                <h4>Monthly Income</h4>
                <div class="value">$<?php echo number_format($totalIncome, 2); ?></div>
            </div>
            <div class="stat-card expense">
                <h4>Total Budgeted</h4>
                <div class="value">$<?php echo number_format($totalAllotted, 2); ?></div>
            </div>
            <div class="stat-card remaining">
                <h4>Remaining</h4>
                <div class="value">$<?php echo number_format($totalIncome - $totalAllotted, 2); ?></div>
            </div>
            <?php
            // Calculate spending for the display month
            $result = pg_query_params($database_connection,
                "SELECT SUM(debit) as total FROM transactions WHERE date >= $1 AND date < $2 AND debit > 0",
                [$displayMonth, $displayMonthEnd]
            );
            $row = pg_fetch_assoc($result);
            $monthlySpent = floatval($row['total'] ?? 0);
            ?>
            <div class="stat-card">
                <h4>Spent (<?php echo date('M Y', strtotime($displayMonth)); ?>)</h4>
                <div class="value" style="color: #e67e22;">$<?php echo number_format($monthlySpent, 2); ?></div>
            </div>
        </div>

        <!-- BTC Ichimoku Analysis Section -->
        <div class="btc-analysis">
            <div class="btc-toggle">
                <button onclick="toggleBTC()">&#9650; Toggle BTC Ichimoku Analysis</button>
            </div>
            <div id="btc-section" style="display: none;">
                <?php
                // Fetch Ichimoku data
                $serverAPIDomain = "https://api.taapi.io/ichimoku?secret=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJlbWFpbCI6Ik1BSU5TUk04MUBHTUFJTC5DT00iLCJpYXQiOjE2MzcyNzkzNzcsImV4cCI6Nzk0NDQ3OTM3N30.c6j5vapp_qVLy7fKDgmG1zNpA69aISLeHeFlx7F8M7E&exchange=binance&symbol=BTC/USDT&interval=1d";
                $json = download_page($serverAPIDomain);
                $arr = json_decode($json, true);
                
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
                        
                        // 2. Price action is above the cloud (must be above the higher span)
                        $cloudTop = max($arr['spanA'], $arr['spanB']);
                        $cloudBottom = min($arr['spanA'], $arr['spanB']);
                        if ($price > $cloudTop) {
                            $signals[] = "✅ Price above cloud";
                        } elseif ($price < $cloudBottom) {
                            $signals[] = "❌ Price below cloud";
                        } else {
                            $signals[] = "❌ Price inside cloud";
                        }
                        
                        // 3. We have a conversion/baseline cross
                        if ($arr['conversion'] > $arr['base']) {
                            $extra = ($arr['conversion'] > $arr['spanA']) ? " (above cloud - very bullish)" : " (in/below cloud)";
                            $signals[] = "✅ Conversion/baseline cross present" . $extra;
                        } else {
                            $signals[] = "❌ No conversion/baseline cross";
                        }
                        
                        // 4. Lagging span (Chiku) closes above the cloud
                        // Chiku = current price plotted 26 periods back
                        // Must compare price to the cloud at that lagging position
                        $laggingCloudTop = max($arr['laggingSpanA'], $arr['laggingSpanB']);
                        $laggingCloudBottom = min($arr['laggingSpanA'], $arr['laggingSpanB']);
                        if ($price > $laggingCloudTop) {
                            $signals[] = "✅ Chiku span above cloud";
                        } elseif ($price < $laggingCloudBottom) {
                            $signals[] = "❌ Chiku span below cloud";
                        } else {
                            $signals[] = "❌ Chiku span inside cloud";
                        }
                        $chikuAboveCloud = ($price > $laggingCloudTop);
                        
                        // Overall recommendation
                        $validCount = substr_count(implode('', $signals), '✅');
                        if ($validCount == 4) {
                            $recommendation = "🚀 STRONG PUMP SIGNAL (All 4 conditions met)";
                        } elseif ($validCount == 3) {
                            $recommendation = "⚠️ MODERATE PUMP SIGNAL (3 of 4 conditions met)";
                        } elseif ($validCount >= 2) {
                            $recommendation = "🟡 WEAK PUMP SIGNAL (2 of 4 conditions met)";
                        } else {
                            $recommendation = "🔴 NO BULLISH SIGNAL (0-1 of 4 conditions met)";
                        }
                        
                        // Sell signal analysis
                        $threshold = $price * 0.01;
                        $chikuTouches = (abs($arr['laggingSpanA'] - $price) < $threshold) || (abs($arr['laggingSpanB'] - $price) < $threshold);
                        $sellTriggered = $chikuTouches || ($arr['conversion'] < $arr['base']);
                        $sellRecommendation = $sellTriggered ? "🔴 SELL SIGNAL TRIGGERED" : "✅ NO SELL SIGNAL";
                        
                        echo '<h4>Ichimoku Signal Dashboard (1-Day Timeframe)</h4>';
                        echo '<div class="signal-dashboard">';
                        
                        // Pump Signal Summary
                        $pumpStatus = ($validCount == 4) ? 'strong' : (($validCount == 3) ? 'moderate' : (($validCount >= 2) ? 'weak' : 'none'));
                        echo '<div class="signal-card pump ' . $pumpStatus . '">';
                        echo '<h5>🚀 Pump Signal</h5>';
                        echo '<div class="signal-metrics">';
                        echo '<div class="metric">Cloud Green: ' . ($arr['spanA'] > $arr['spanB'] ? '✅' : '❌') . '</div>';
                        echo '<div class="metric">Price > Cloud: ' . ($price > $cloudTop ? '✅' : '❌') . '</div>';
                        echo '<div class="metric">Cross Present: ' . ($arr['conversion'] > $arr['base'] ? '✅' : '❌') . '</div>';
                        echo '<div class="metric">Chiku > Cloud: ' . ($chikuAboveCloud ? '✅' : '❌') . '</div>';
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
                        echo '<li><strong>Cloud:</strong> ' . number_format($cloudBottom, 2) . ' - ' . number_format($cloudTop, 2) . ' (' . ($arr['spanA'] > $arr['spanB'] ? 'Green' : 'Red') . ')</li>';
                        echo '<li><strong>Conversion/Base:</strong> ' . number_format($arr['conversion'], 2) . ' / ' . number_format($arr['base'], 2) . '</li>';
                        echo '<li><strong>Lagging Cloud:</strong> ' . number_format($laggingCloudBottom, 2) . ' - ' . number_format($laggingCloudTop, 2) . ' (Chiku@' . number_format($price, 0) . ' is ' . ($price > $laggingCloudTop ? 'above' : ($price < $laggingCloudBottom ? 'below' : 'inside')) . ')</li>';
                        echo '</ul>';
                        echo '</div>';
                    } else {
                        echo '<p>Ichimoku data currently unavailable due to API rate limit.</p>';
                    }
                } else {
                    echo '<p>Unable to fetch BTC price data.</p>';
                }
                ?>
            </div>
        </div>

        <h2>Where the Money Goes <small style="font-weight:normal;font-size:0.6em;color:#666;">(<?php echo $displayMonthName; ?>)</small></h2>
        <div class="budget-tiles">
            <button class="budget-tile manage-btn" onclick="openManage()">
                <h3>⚙️ Manage Budget</h3>
                <div class="percentage">Add categories, update income</div>
            </button>
            
            <?php foreach ($budgetData as $data): 
                $pct = ($totalIncome > 0) ? number_format(($data['amount'] / $totalIncome) * 100, 1) . '%' : '0.0%';
                $rowClass = $data['is_savings'] ? 'savings' : '';
            ?>
            <div class="budget-tile <?php echo $rowClass; ?>" 
                 data-category="<?php echo htmlspecialchars($data['name']); ?>" 
                 data-amount="<?php echo $data['amount']; ?>" 
                 onclick="editCategory(this)">
                <h3><?php echo htmlspecialchars($data['name']); ?></h3>
                <div class="amount">$<?php echo number_format($data['amount'], 2); ?></div>
                <div class="percentage"><?php echo $pct; ?> of income</div>
            </div>
            <?php endforeach; ?>
            
            <div class="budget-tile total">
                <h3>Total Allotted</h3>
                <div class="amount">$<?php echo number_format($totalAllotted, 2); ?></div>
                <div class="percentage">
                    <?php echo ($totalIncome > 0) ? number_format(($totalAllotted / $totalIncome) * 100, 1) . '% of income' : 'N/A'; ?>
                </div>
            </div>
        </div>

        <h2>Where the Money Went (<?php echo date('F Y'); ?>)</h2>
        <div class="table-responsive">
            <table id="budget-table">
                <tr>
                    <th>Category</th>
                    <th>Budgeted</th>
                    <th>Actual</th>
                    <th>Variance</th>
                </tr>
                <?php 
                $index = 0;
                foreach($budgetData as $data): 
                    $category = $data['name'];
                    $budgeted = $data['amount'];
                    $rowClass = $data['is_savings'] ? 'savings' : '';
                    
                    $transList = isset($transactionsByCategory[$category]) ? $transactionsByCategory[$category] : [];
                    $actualSum = 0;
                    foreach ($transList as $trans) {
                        $actualSum += floatval($trans['debit']);
                    }
                    
                    // Calculate variance
                    if ($budgeted > 0) {
                        $variance = (($actualSum - $budgeted) / $budgeted) * 100;
                        $varianceText = ($variance > 0 ? '+' : '') . number_format($variance, 1) . '%';
                        $varianceClass = $variance > 0 ? 'over' : 'under';
                    } else {
                        $varianceText = 'N/A';
                        $varianceClass = '';
                    }
                ?>
                <tr class="expandable <?php echo $rowClass; ?>" onclick="toggleTransactions(<?php echo $index; ?>)">
                    <td><?php echo htmlspecialchars($category); ?></td>
                    <td>$<?php echo number_format($budgeted, 2); ?></td>
                    <td class="amount">$<?php echo number_format($actualSum, 2); ?></td>
                    <td class="percentage <?php echo $varianceClass; ?>"><?php echo $varianceText; ?></td>
                </tr>
                <tr id="transactions-<?php echo $index; ?>" class="transaction-row hidden">
                    <td colspan="4">
                        <?php if (empty($transList)): ?>
                            <div class="no-transactions">No transactions found for this category this month.</div>
                        <?php else: ?>
                            <div class="transaction-list">
                                <h4>Recent Transactions (<?php echo count($transList); ?>)</h4>
                                <table class="transaction-table">
                                    <tr><th>Date</th><th>Description</th><th>Amount</th></tr>
                                    <?php foreach ($transList as $trans): 
                                        $transAmount = '$' . number_format(floatval($trans['debit']), 2);
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($trans['date']); ?></td>
                                        <td><?php echo htmlspecialchars(substr($trans['description'], 0, 60)); ?></td>
                                        <td>-<?php echo $transAmount; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php 
                $index++;
                endforeach; 
                ?>
            </table>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEdit()">&times;</span>
            <h2>Edit Budget Category</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update_category">
                <input type="hidden" id="oldCategory" name="old_category" value="">
                
                <label for="newCategory">Category Name:</label>
                <input type="text" id="newCategory" name="new_category" required>
                
                <label for="newAmount">Monthly Budget Amount:</label>
                <input type="number" id="newAmount" name="new_amount" step="0.01" min="0" required>
                
                <button type="submit">Save Changes</button>
            </form>
            <hr style="margin: 20px 0;">
            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this category?');">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" id="deleteCategoryName" name="category_name" value="">
                <button type="submit" class="btn-danger">Delete Category</button>
            </form>
        </div>
    </div>

    <!-- Manage Budget Modal -->
    <div id="manageModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeManage()">&times;</span>
            <h2>Manage Budget</h2>
            
            <h3>Add New Category</h3>
            <form method="POST" style="margin-bottom: 20px;">
                <input type="hidden" name="action" value="add_category">
                <label>Category Name:</label>
                <input type="text" name="new_cat" placeholder="e.g., Entertainment" required>
                <label>Monthly Budget:</label>
                <input type="number" step="0.01" name="new_amt" placeholder="0.00" required>
                <button type="submit">Add Category</button>
            </form>

            <hr>
            
            <h3>Update Income Sources</h3>
            <p style="font-size: 0.9em; color: #666;">Edit income sources (one per line): Category,Amount</p>
            <form method="POST">
                <input type="hidden" name="action" value="update_income">
                <textarea name="income_block" rows="6" style="width: 100%;" placeholder="UC Payroll,5303.59&#10;Side Income,500.00"><?php
                    foreach ($incomeData as $inc) {
                        echo htmlspecialchars($inc['category']) . ',' . $inc['amount'] . "\n";
                    }
                ?></textarea>
                <button type="submit">Update Income</button>
            </form>
        </div>
    </div>

    <script>
        function toggleTransactions(index) {
            const row = document.getElementById('transactions-' + index);
            row.classList.toggle('hidden');
        }
        
        function editCategory(tile) {
            const category = tile.getAttribute('data-category');
            const amount = tile.getAttribute('data-amount');
            document.getElementById('oldCategory').value = category;
            document.getElementById('newCategory').value = category;
            document.getElementById('newAmount').value = amount;
            document.getElementById('deleteCategoryName').value = category;
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
        
        function toggleBTC() {
            var section = document.getElementById('btc-section');
            section.style.display = (section.style.display === 'none') ? 'block' : 'none';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
