<?php
/**
 * Migration Script: Import CSV data into PostgreSQL Database
 * 
 * This script reads from:
 * - budget.csv (income, budget categories)
 * - Transactions CSV files
 * 
 * And imports them into the PostgreSQL database.
 * 
 * Run this ONCE to migrate existing data.
 */

include '../CMS/Connect_to_Postgres.php';

echo "<h1>Budget App Database Migration</h1>";
echo "<pre>";

// ============================================================
// STEP 1: Run schema upgrade
// ============================================================
echo "\n=== Step 1: Creating/Updating Database Schema ===\n";

$schemaFile = __DIR__ . '/schema_upgrade.sql';
if (file_exists($schemaFile)) {
    $schemaSql = file_get_contents($schemaFile);
    // Split by semicolons and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $schemaSql)));
    
    foreach ($statements as $stmt) {
        if (empty($stmt) || strpos($stmt, '--') === 0) continue;
        // Skip DROP statements for safety
        if (stripos($stmt, 'DROP TABLE') !== false) continue;
        
        $result = @pg_query($database_connection, $stmt);
        if (!$result) {
            $error = pg_last_error($database_connection);
            // Ignore "already exists" errors
            if (strpos($error, 'already exists') === false && strpos($error, 'duplicate key') === false) {
                echo "Warning: " . $error . "\n";
            }
        }
    }
    echo "Schema updated successfully.\n";
} else {
    echo "Warning: schema_upgrade.sql not found.\n";
}

// ============================================================
// STEP 2: Parse and import budget.csv
// ============================================================
echo "\n=== Step 2: Importing Budget Data from CSV ===\n";

$budgetFile = __DIR__ . '/budget.csv';
if (!file_exists($budgetFile)) {
    $budgetFile = __DIR__ . '/Budget-2025.csv';
}

if (file_exists($budgetFile)) {
    $lines = file($budgetFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    $currentSection = '';
    $incomeCount = 0;
    $budgetCatCount = 0;
    $fixedExpenseCount = 0;
    $variableCount = 0;
    $currentFixedCategory = '';
    $currentVariableCategory = '';
    
    // Clear existing data (optional - comment out if you want to keep existing)
    pg_query($database_connection, "TRUNCATE income RESTART IDENTITY CASCADE");
    pg_query($database_connection, "TRUNCATE budget_categories RESTART IDENTITY CASCADE");
    pg_query($database_connection, "TRUNCATE fixed_expenses RESTART IDENTITY CASCADE");
    pg_query($database_connection, "TRUNCATE variable_spending RESTART IDENTITY CASCADE");
    
    $sortOrder = 0;
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Detect section headers
        if (strpos($line, 'INCOME (Monthly)') !== false) {
            $currentSection = 'income';
            continue;
        }
        if (strpos($line, 'FIXED MONTHLY EXPENSES') !== false) {
            $currentSection = 'fixed';
            continue;
        }
        if (strpos($line, 'VARIABLE SPENDING') !== false) {
            $currentSection = 'variable';
            continue;
        }
        if (strpos($line, 'WHERE THE MONEY GOES') !== false) {
            $currentSection = 'budget_categories';
            continue;
        }
        if (strpos($line, 'BUDGET SUMMARY') !== false) {
            $currentSection = 'summary';
            continue;
        }
        
        // Skip dividers and headers
        if (strpos($line, '===') !== false) continue;
        if (stripos($line, 'Category,Amount') !== false) continue;
        if (stripos($line, 'Category,Budget') !== false) continue;
        if (stripos($line, 'Expense,Due Date') !== false) continue;
        if (stripos($line, 'Item,Amount') !== false) continue;
        if (empty($line)) continue;
        
        $parts = str_getcsv($line);
        if (count($parts) < 2) continue;
        
        $col1 = trim($parts[0]);
        $col2 = isset($parts[1]) ? trim($parts[1]) : '';
        $col3 = isset($parts[2]) ? trim($parts[2]) : '';
        
        // Skip subtotals and totals
        if (stripos($col1, 'Subtotal') === 0) continue;
        if (stripos($col1, 'Total') === 0) continue;
        
        // Parse based on section
        switch ($currentSection) {
            case 'income':
                $amount = (float) str_replace(['$', ','], '', $col2);
                if ($amount > 0 && $col1 !== '') {
                    $pct = (float) str_replace('%', '', $col3);
                    pg_query_params($database_connection, 
                        "INSERT INTO income (category, amount, percentage) VALUES ($1, $2, $3)",
                        [$col1, $amount, $pct]
                    );
                    $incomeCount++;
                    echo "  Income: $col1 = \$$amount\n";
                }
                break;
                
            case 'fixed':
                // Check if this is a category header (all caps, no amount)
                if (preg_match('/^[A-Z\/]+$/', $col1) && ($col2 === '' || !is_numeric(str_replace(['$', ','], '', $col2)))) {
                    $currentFixedCategory = $col1;
                    continue 2;
                }
                
                $dueDate = is_numeric($col2) ? (int)$col2 : null;
                // For fixed expenses, amount is typically in col3 as percentage or we need to extract it elsewhere
                // Let's assume the CSV has: Expense, Due Date, Percentage
                // We'll set amount to 0 for now and update manually
                if ($col1 !== '' && $currentFixedCategory !== '') {
                    pg_query_params($database_connection,
                        "INSERT INTO fixed_expenses (category, expense_name, due_date, amount) VALUES ($1, $2, $3, $4)",
                        [$currentFixedCategory, $col1, $dueDate, 0]
                    );
                    $fixedExpenseCount++;
                    echo "  Fixed Expense: $currentFixedCategory / $col1 (due: $dueDate)\n";
                }
                break;
                
            case 'variable':
                // Check if this is a category header (all caps)
                if (preg_match('/^[A-Z &\/]+$/', $col1) && ($col2 === '' || $col2 === 'Budget')) {
                    $currentVariableCategory = $col1;
                    continue 2;
                }
                
                $amount = (float) str_replace(['$', ','], '', $col2);
                if ($col1 !== '' && $currentVariableCategory !== '' && $amount > 0) {
                    pg_query_params($database_connection,
                        "INSERT INTO variable_spending (parent_category, subcategory, budgeted_amount) VALUES ($1, $2, $3)",
                        [$currentVariableCategory, $col1, $amount]
                    );
                    $variableCount++;
                    echo "  Variable: $currentVariableCategory / $col1 = \$$amount\n";
                }
                break;
                
            case 'budget_categories':
                $amount = (float) str_replace(['$', ','], '', $col2);
                if ($col1 !== '' && $amount > 0) {
                    $isSavings = (stripos($col1, 'Saving') !== false) ? 'TRUE' : 'FALSE';
                    pg_query_params($database_connection,
                        "INSERT INTO budget_categories (name, budgeted_amount, sort_order, is_savings) VALUES ($1, $2, $3, $4)",
                        [$col1, $amount, $sortOrder++, $isSavings === 'TRUE']
                    );
                    $budgetCatCount++;
                    echo "  Budget Category: $col1 = \$$amount\n";
                }
                break;
        }
    }
    
    echo "\nBudget CSV Import Complete:\n";
    echo "  - Income sources: $incomeCount\n";
    echo "  - Budget categories: $budgetCatCount\n";
    echo "  - Fixed expenses: $fixedExpenseCount\n";
    echo "  - Variable spending: $variableCount\n";
    
} else {
    echo "Warning: budget.csv not found.\n";
}

// ============================================================
// STEP 3: Import transactions from CSV
// ============================================================
echo "\n=== Step 3: Importing Transactions ===\n";

// Find transaction CSV files
$transactionFiles = glob(__DIR__ . '/Transactions-*.csv');

if (!empty($transactionFiles)) {
    // Check if transactions already exist
    $result = pg_query($database_connection, "SELECT COUNT(*) FROM transactions");
    $row = pg_fetch_row($result);
    $existingCount = (int)$row[0];
    
    if ($existingCount > 0) {
        echo "Found $existingCount existing transactions. Skipping import to avoid duplicates.\n";
        echo "To re-import, run: TRUNCATE transactions RESTART IDENTITY;\n";
    } else {
        // Use the combined/categorized file if available
        $transFile = __DIR__ . '/Transactions-Combined-2025-12-27-Categorized.csv';
        if (!file_exists($transFile)) {
            $transFile = $transactionFiles[0]; // Use first available
        }
        
        if (file_exists($transFile)) {
            $file = fopen($transFile, 'r');
            fgetcsv($file); // Skip header
            
            $transCount = 0;
            while (($data = fgetcsv($file)) !== FALSE) {
                if (count($data) < 7) continue;
                
                $account = $data[0];
                $chkref = $data[1] ?: null;
                $debit = $data[2] ? (float) str_replace(',', '', $data[2]) : null;
                $credit = $data[3] ? (float) str_replace(',', '', $data[3]) : null;
                $balance = $data[4] ? (float) str_replace(',', '', $data[4]) : null;
                $date = date('Y-m-d', strtotime($data[5]));
                $description = $data[6];
                $category = isset($data[7]) ? $data[7] : null;
                
                // Map to budget category
                $budgetCategory = mapToBudgetCategory($database_connection, $category, $description);
                
                $query = "INSERT INTO transactions (account, chkref, debit, credit, balance, date, description, category, budget_category, is_manual) 
                          VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, FALSE)";
                pg_query_params($database_connection, $query, [
                    $account, $chkref, $debit, $credit, $balance, $date, $description, $category, $budgetCategory
                ]);
                $transCount++;
            }
            fclose($file);
            
            echo "Imported $transCount transactions from $transFile\n";
        }
    }
} else {
    echo "No transaction CSV files found.\n";
}

// ============================================================
// Helper function to map transaction category to budget category
// ============================================================
function mapToBudgetCategory($conn, $category, $description) {
    if (empty($category)) return 'Misc';
    
    // First try to find in category_mappings table
    $result = pg_query_params($conn, 
        "SELECT budget_category FROM category_mappings WHERE transaction_category = $1",
        [$category]
    );
    if ($result && pg_num_rows($result) > 0) {
        $row = pg_fetch_assoc($result);
        return $row['budget_category'];
    }
    
    // Otherwise use logic-based mapping
    $cat = strtolower($category);
    $desc = strtolower($description);
    
    // Skip income
    if (strpos($cat, 'income') !== false || strpos($cat, 'payroll') !== false) {
        return null;
    }
    
    // Housing
    if (strpos($desc, 'fnma') !== false || strpos($desc, 'fannie') !== false || strpos($desc, '4604') !== false) {
        return 'Housing';
    }
    
    // Vehicles
    if (strpos($desc, '4190') !== false || strpos($desc, '4620') !== false) {
        return 'Vehicles';
    }
    
    // Insurance
    if ($cat === 'insurance') return 'Insurance';
    
    // Utilities
    if (strpos($cat, 'utilities') === 0) return 'Utilities';
    
    // Groceries
    if (strpos($cat, 'groceries') !== false) return 'Groceries';
    
    // Gas/Transportation
    if ($cat === 'gas/fuel') return 'Transportation';
    
    // Dining
    if (strpos($cat, 'dining') !== false || strpos($cat, 'fast food') !== false) return 'Dining';
    
    // Subscriptions
    if (strpos($cat, 'subscriptions') !== false || strpos($cat, 'memberships') !== false) {
        return 'Subscriptions Memberships';
    }
    
    // Household
    if (strpos($cat, 'home improvement') !== false || strpos($cat, 'farm') !== false) {
        return 'Household';
    }
    
    // Personal
    if (strpos($cat, 'personal') !== false || strpos($cat, 'entertainment') !== false) {
        return 'Personal';
    }
    
    // Giving
    if (strpos($cat, 'charitable') !== false || strpos($desc, 'ptc') !== false) {
        return 'Giving';
    }
    
    // Education
    if (strpos($cat, 'education') !== false || strpos($cat, 'tuition') !== false) {
        return 'Education (Tuition)';
    }
    
    return 'Misc';
}

echo "\n=== Migration Complete! ===\n";
echo "\nYou can now use the database-powered budget app.\n";
echo "</pre>";

echo "<p><a href='index.php'>Go to Budget Dashboard</a></p>";
echo "<p><a href='view_budget.php'>Go to Budget Summary</a></p>";
echo "<p><a href='add_transaction.php'>Add Transaction</a></p>";
?>
