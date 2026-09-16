<?php
/**
 * Quick import script - run once to populate database
 */
include '../CMS/Connect_to_Postgres.php';

echo "<pre>";
echo "=== Importing Budget Data ===\n\n";

// Read budget.csv
$budgetFile = __DIR__ . '/budget.csv';
$lines = file($budgetFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$currentSection = '';
$sortOrder = 0;
$incomeCount = 0;
$budgetCount = 0;

foreach ($lines as $line) {
    $line = trim($line);
    
    if (strpos($line, 'INCOME (Monthly)') !== false) {
        $currentSection = 'income';
        continue;
    }
    if (strpos($line, 'WHERE THE MONEY GOES') !== false) {
        $currentSection = 'budget_categories';
        continue;
    }
    if (strpos($line, 'FIXED MONTHLY') !== false || strpos($line, 'VARIABLE SPENDING') !== false || strpos($line, 'BUDGET SUMMARY') !== false) {
        $currentSection = '';
        continue;
    }
    
    if (strpos($line, '===') !== false) continue;
    if (stripos($line, 'Category,Amount') !== false) continue;
    if (stripos($line, 'Category,Budget') !== false) continue;
    if (empty($line)) continue;
    
    $parts = str_getcsv($line);
    if (count($parts) < 2) continue;
    
    $col1 = trim($parts[0]);
    $col2 = trim($parts[1] ?? '');
    
    if (stripos($col1, 'Subtotal') === 0 || stripos($col1, 'Total') === 0) continue;
    
    if ($currentSection === 'income') {
        $amount = (float) str_replace(['$', ','], '', $col2);
        if ($amount > 0 && $col1 !== '') {
            $result = pg_query_params($database_connection, 
                "INSERT INTO income (category, amount) VALUES ($1, $2)",
                [$col1, $amount]
            );
            if ($result) {
                echo "Income: $col1 = \$$amount\n";
                $incomeCount++;
            }
        }
    }
    
    if ($currentSection === 'budget_categories') {
        $amount = (float) str_replace(['$', ','], '', $col2);
        if ($col1 !== '' && $amount > 0) {
            $isSavings = (stripos($col1, 'Saving') !== false) ? 't' : 'f';
            $result = pg_query_params($database_connection,
                "INSERT INTO budget_categories (name, budgeted_amount, sort_order, is_savings) 
                 VALUES ($1, $2, $3, $4) 
                 ON CONFLICT (name) DO UPDATE SET budgeted_amount = EXCLUDED.budgeted_amount",
                [$col1, $amount, $sortOrder++, $isSavings]
            );
            if ($result) {
                echo "Budget Category: $col1 = \$$amount\n";
                $budgetCount++;
            } else {
                echo "ERROR inserting $col1: " . pg_last_error($database_connection) . "\n";
            }
        }
    }
}

echo "\n=== Import Summary ===\n";
echo "Income sources imported: $incomeCount\n";
echo "Budget categories imported: $budgetCount\n";

echo "\n=== Done! ===\n";
echo "</pre>";

echo "<p><a href='index.php'>Go to Dashboard</a></p>";
?>
