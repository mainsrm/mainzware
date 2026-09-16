<?php
include '../CMS/Connect_to_Postgres.php';

// Get current month boundaries
$currentMonth = date('Y-m-01');
$nextMonth = date('Y-m-01', strtotime('+1 month'));
$monthName = date('F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mains Budget - Summary</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; }
        .banner { background-color: #333; color: white; text-align: center; padding: 20px; font-size: 2em; }
        nav { background-color: #444; padding: 10px; }
        nav ul { list-style: none; margin: 0; padding: 0; display: flex; justify-content: center; flex-wrap: wrap; }
        nav li { margin: 0 15px; }
        nav a { color: white; text-decoration: none; }
        nav a:hover { text-decoration: underline; }
        .container { max-width: 1200px; margin: 20px auto; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .summary { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px; }
        .summary-item { flex: 1; min-width: 200px; padding: 15px; background: #e9e9e9; border-radius: 5px; text-align: center; }
        .summary-item.income { background: #d4edda; border-left: 4px solid #28a745; }
        .summary-item.expense { background: #f8d7da; border-left: 4px solid #dc3545; }
        .summary-item.remaining { background: #cce5ff; border-left: 4px solid #007bff; }
        .summary-item h3 { margin: 0 0 10px 0; font-size: 1em; color: #666; }
        .summary-item p { margin: 0; font-size: 1.5em; font-weight: bold; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #333; color: white; }
        .table-container { overflow-x: auto; }
        h2 { color: #333; border-bottom: 2px solid #444; padding-bottom: 10px; margin-top: 30px; }
        .debit { color: #dc3545; }
        .credit { color: #28a745; }
        .variance-over { color: #dc3545; font-weight: bold; }
        .variance-under { color: #28a745; font-weight: bold; }
        .manual-badge { 
            background: #6f42c1; 
            color: white; 
            padding: 2px 6px; 
            border-radius: 3px; 
            font-size: 0.75em; 
            margin-left: 5px;
        }
        @media (max-width: 768px) {
            .banner { font-size: 1.5em; padding: 15px; }
            nav ul { flex-direction: column; align-items: center; }
            nav li { margin: 5px 0; }
            .summary { flex-direction: column; }
            table { font-size: 0.85em; }
        }
    </style>
</head>
<body>
    <header>
        <div class="banner">💰 Mains Budget</div>
        <nav>
            <ul>
                <li><a href="index.php">Dashboard</a></li>
                <li><a href="view_budget.php">Budget Summary</a></li>
                <li><a href="add_transaction.php">Add Transaction</a></li>
                <li><a href="import_transactions.php">Import Transactions</a></li>
            </ul>
        </nav>
    </header>
    <div class="container">
        <h1>Budget Summary - <?php echo $monthName; ?></h1>
        
        <div class="summary">
            <?php
            // Total Income from income table
            $result = pg_query($database_connection, "SELECT SUM(amount) as total_income FROM income");
            $row = pg_fetch_assoc($result);
            $totalIncome = floatval($row['total_income'] ?? 0);
            echo "<div class='summary-item income'><h3>Monthly Income</h3><p>\$" . number_format($totalIncome, 2) . "</p></div>";

            // Total Budgeted from budget_categories
            $result = pg_query($database_connection, "SELECT SUM(budgeted_amount) as total FROM budget_categories");
            $row = pg_fetch_assoc($result);
            $totalBudgeted = floatval($row['total'] ?? 0);
            echo "<div class='summary-item'><h3>Total Budgeted</h3><p>\$" . number_format($totalBudgeted, 2) . "</p></div>";

            // Actual spent this month
            $result = pg_query_params($database_connection, 
                "SELECT SUM(debit) as total FROM transactions WHERE date >= $1 AND date < $2 AND debit > 0",
                [$currentMonth, $nextMonth]
            );
            $row = pg_fetch_assoc($result);
            $totalSpent = floatval($row['total'] ?? 0);
            echo "<div class='summary-item expense'><h3>Spent This Month</h3><p>\$" . number_format($totalSpent, 2) . "</p></div>";

            // Remaining budget
            $remaining = $totalBudgeted - $totalSpent;
            $remainingClass = $remaining >= 0 ? 'remaining' : 'expense';
            echo "<div class='summary-item $remainingClass'><h3>Budget Remaining</h3><p>\$" . number_format($remaining, 2) . "</p></div>";
            ?>
        </div>

        <h2>Income Sources</h2>
        <div class="table-container">
            <table>
                <tr><th>Source</th><th>Amount</th><th>% of Total</th></tr>
                <?php
                $result = pg_query($database_connection, "SELECT * FROM income ORDER BY amount DESC");
                while ($row = pg_fetch_assoc($result)) {
                    $pct = $totalIncome > 0 ? number_format(($row['amount'] / $totalIncome) * 100, 1) : 0;
                    echo "<tr><td>" . htmlspecialchars($row['category']) . "</td>";
                    echo "<td>\$" . number_format($row['amount'], 2) . "</td>";
                    echo "<td>{$pct}%</td></tr>";
                }
                ?>
                <tr style="font-weight: bold; background: #f0f0f0;">
                    <td>Total</td>
                    <td>$<?php echo number_format($totalIncome, 2); ?></td>
                    <td>100%</td>
                </tr>
            </table>
        </div>

        <h2>Budget vs Actual (<?php echo $monthName; ?>)</h2>
        <div class="table-container">
            <table>
                <tr><th>Category</th><th>Budgeted</th><th>Actual</th><th>Variance</th><th>Status</th></tr>
                <?php
                $result = pg_query($database_connection, "SELECT * FROM budget_categories ORDER BY sort_order, name");
                while ($cat = pg_fetch_assoc($result)) {
                    $catName = $cat['name'];
                    $budgeted = floatval($cat['budgeted_amount']);
                    
                    // Get actual spending for this category
                    $spentResult = pg_query_params($database_connection,
                        "SELECT SUM(debit) as total FROM transactions 
                         WHERE (budget_category = $1 OR category ILIKE $2)
                         AND date >= $3 AND date < $4 AND debit > 0",
                        [$catName, "%$catName%", $currentMonth, $nextMonth]
                    );
                    $spentRow = pg_fetch_assoc($spentResult);
                    $actual = floatval($spentRow['total'] ?? 0);
                    
                    $variance = $budgeted - $actual;
                    $varianceClass = $variance >= 0 ? 'variance-under' : 'variance-over';
                    $status = $variance >= 0 ? '✅ Under' : '⚠️ Over';
                    
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($catName) . "</td>";
                    echo "<td>\$" . number_format($budgeted, 2) . "</td>";
                    echo "<td>\$" . number_format($actual, 2) . "</td>";
                    echo "<td class='$varianceClass'>\$" . number_format(abs($variance), 2) . "</td>";
                    echo "<td>$status</td>";
                    echo "</tr>";
                }
                ?>
            </table>
        </div>

        <h2>Recent Transactions</h2>
        <div class="table-container">
            <table>
                <tr>
                    <th>Date</th>
                    <th>Account</th>
                    <th>Description</th>
                    <th>Amount</th>
                    <th>Category</th>
                </tr>
                <?php
                $result = pg_query($database_connection, "SELECT * FROM transactions ORDER BY date DESC, id DESC LIMIT 50");
                while ($row = pg_fetch_assoc($result)) {
                    $amount = '';
                    $amountClass = '';
                    if ($row['debit']) {
                        $amount = '-$' . number_format($row['debit'], 2);
                        $amountClass = 'debit';
                    } elseif ($row['credit']) {
                        $amount = '+$' . number_format($row['credit'], 2);
                        $amountClass = 'credit';
                    }
                    $manualBadge = ($row['is_manual'] ?? false) ? '<span class="manual-badge">Manual</span>' : '';
                    
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($row['date']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['account']) . "$manualBadge</td>";
                    echo "<td>" . htmlspecialchars(substr($row['description'], 0, 50)) . "</td>";
                    echo "<td class='$amountClass'>$amount</td>";
                    echo "<td>" . htmlspecialchars($row['budget_category'] ?? $row['category'] ?? '') . "</td>";
                    echo "</tr>";
                }
                ?>
            </table>
        </div>
    </div>
</body>
</html>