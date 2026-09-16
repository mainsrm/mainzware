<?php
include '../CMS/Connect_to_Postgres.php'; // Adjust path if needed

// Function to parse budget CSV
function parseBudgetCSV($filePath) {
    $income = [];
    $expenses = [];
    $currentSection = '';
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        if (strpos($line, 'INCOME (Monthly)') !== false) {
            $currentSection = 'income';
            continue;
        } elseif (strpos($line, 'FIXED MONTHLY EXPENSES') !== false) {
            $currentSection = 'expenses';
            continue;
        }

        if ($currentSection == 'income') {
            if (strpos($line, 'Category,Amount,Notes') === 0) continue; // Skip header
            if (strpos($line, 'Total Monthly Income') === 0) continue;
            $parts = explode(',', $line, 3);
            if (count($parts) >= 2) {
                $income[] = [
                    'category' => trim($parts[0]),
                    'amount' => (float) str_replace(',', '', $parts[1]),
                    'notes' => isset($parts[2]) ? trim($parts[2]) : ''
                ];
            }
        } elseif ($currentSection == 'expenses') {
            if (strpos($line, 'Expense,Due Date,Monthly Amount,Notes') === 0) continue;
            if (strpos($line, 'Subtotal') === 0 || strpos($line, '===========================================') === 0) continue;
            if (preg_match('/^[A-Z]+$/', $line)) { // Category header like HOUSING
                $currentCategory = $line;
                continue;
            }
            $parts = explode(',', $line, 4);
            if (count($parts) >= 3) {
                $expenses[] = [
                    'category' => $currentCategory,
                    'expense_name' => trim($parts[0]),
                    'due_date' => is_numeric($parts[1]) ? (int)$parts[1] : null,
                    'monthly_amount' => (float) str_replace(',', '', $parts[2]),
                    'notes' => isset($parts[3]) ? trim($parts[3]) : ''
                ];
            }
        }
    }
    return ['income' => $income, 'expenses' => $expenses];
}

// Parse the CSV
$data = parseBudgetCSV('Budget-2025.csv');

// Insert income
foreach ($data['income'] as $inc) {
    $query = "INSERT INTO income (category, amount, notes) VALUES ($1, $2, $3)";
    pg_query_params($database_connection, $query, [$inc['category'], $inc['amount'], $inc['notes']]);
}

// Get category IDs
$categoryMap = [];
$result = pg_query($database_connection, "SELECT id, name FROM expense_categories");
while ($row = pg_fetch_assoc($result)) {
    $categoryMap[$row['name']] = $row['id'];
}

// Insert expenses
foreach ($data['expenses'] as $exp) {
    $catId = $categoryMap[$exp['category']] ?? $categoryMap['OTHER'];
    $query = "INSERT INTO expenses (category_id, expense_name, due_date, monthly_amount, notes) VALUES ($1, $2, $3, $4, $5)";
    pg_query_params($database_connection, $query, [$catId, $exp['expense_name'], $exp['due_date'], $exp['monthly_amount'], $exp['notes']]);
}

echo "Budget data imported successfully.";
?>