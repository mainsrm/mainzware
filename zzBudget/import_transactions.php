<?php
/**
 * Import Transactions from Bank CSV Files
 * 
 * This script imports transaction data from CSV files exported from banking institutions.
 * It supports the existing CSV format and maps transactions to budget categories.
 */
include '../CMS/Connect_to_Postgres.php';

$message = '';
$messageType = '';
$importedCount = 0;
$skippedCount = 0;

// Handle file upload and import
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'import_csv' && isset($_FILES['csv_file'])) {
        $uploadedFile = $_FILES['csv_file'];
        
        if ($uploadedFile['error'] === UPLOAD_ERR_OK) {
            $file = fopen($uploadedFile['tmp_name'], 'r');
            
            // Read and skip header
            $header = fgetcsv($file);
            
            while (($data = fgetcsv($file)) !== FALSE) {
                if (count($data) < 7) continue;
                
                $account = trim($data[0]);
                $chkref = trim($data[1]) ?: null;
                $debit = $data[2] ? (float) str_replace(',', '', $data[2]) : null;
                $credit = $data[3] ? (float) str_replace(',', '', $data[3]) : null;
                $balance = $data[4] ? (float) str_replace(',', '', $data[4]) : null;
                $dateStr = trim($data[5]);
                $date = date('Y-m-d', strtotime($dateStr));
                $description = trim($data[6]);
                $category = isset($data[7]) ? trim($data[7]) : null;
                
                // Skip invalid dates
                if ($date === '1970-01-01' || empty($dateStr)) {
                    $skippedCount++;
                    continue;
                }
                
                // Check for duplicate (same account, date, amount, description)
                $checkQuery = "SELECT id FROM transactions WHERE account = $1 AND date = $2 AND description = $3";
                $checkParams = [$account, $date, $description];
                
                if ($debit) {
                    $checkQuery .= " AND debit = $4";
                    $checkParams[] = $debit;
                } elseif ($credit) {
                    $checkQuery .= " AND credit = $4";
                    $checkParams[] = $credit;
                }
                
                $checkResult = pg_query_params($database_connection, $checkQuery, $checkParams);
                if (pg_num_rows($checkResult) > 0) {
                    $skippedCount++;
                    continue; // Skip duplicate
                }
                
                // Map to budget category
                $budgetCategory = mapToBudgetCategory($category, $description);
                
                // Insert transaction
                $query = "INSERT INTO transactions (account, chkref, debit, credit, balance, date, description, category, budget_category, is_manual) 
                          VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, FALSE)";
                $result = pg_query_params($database_connection, $query, [
                    $account, $chkref, $debit, $credit, $balance, $date, $description, $category, $budgetCategory
                ]);
                
                if ($result) {
                    $importedCount++;
                }
            }
            
            fclose($file);
            
            $message = "Import complete! $importedCount transactions imported, $skippedCount skipped (duplicates or invalid).";
            $messageType = 'success';
        } else {
            $message = "Error uploading file.";
            $messageType = 'error';
        }
    }
    
    if ($_POST['action'] === 'import_existing') {
        // Import from existing CSV file in the Budget directory
        $filename = trim($_POST['filename'] ?? '');
        $filepath = __DIR__ . '/' . basename($filename);
        
        if (file_exists($filepath) && pathinfo($filepath, PATHINFO_EXTENSION) === 'csv') {
            $file = fopen($filepath, 'r');
            fgetcsv($file); // Skip header
            
            while (($data = fgetcsv($file)) !== FALSE) {
                if (count($data) < 7) continue;
                
                $account = trim($data[0]);
                $chkref = trim($data[1]) ?: null;
                $debit = $data[2] ? (float) str_replace(',', '', $data[2]) : null;
                $credit = $data[3] ? (float) str_replace(',', '', $data[3]) : null;
                $balance = $data[4] ? (float) str_replace(',', '', $data[4]) : null;
                $date = date('Y-m-d', strtotime($data[5]));
                $description = trim($data[6]);
                $category = isset($data[7]) ? trim($data[7]) : null;
                
                if ($date === '1970-01-01') {
                    $skippedCount++;
                    continue;
                }
                
                // Check for duplicate
                $checkQuery = "SELECT id FROM transactions WHERE account = $1 AND date = $2 AND description = $3 LIMIT 1";
                $checkResult = pg_query_params($database_connection, $checkQuery, [$account, $date, $description]);
                if (pg_num_rows($checkResult) > 0) {
                    $skippedCount++;
                    continue;
                }
                
                $budgetCategory = mapToBudgetCategory($category, $description);
                
                $query = "INSERT INTO transactions (account, chkref, debit, credit, balance, date, description, category, budget_category, is_manual) 
                          VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, FALSE)";
                $result = pg_query_params($database_connection, $query, [
                    $account, $chkref, $debit, $credit, $balance, $date, $description, $category, $budgetCategory
                ]);
                
                if ($result) {
                    $importedCount++;
                }
            }
            
            fclose($file);
            $message = "Import complete! $importedCount transactions imported from $filename, $skippedCount skipped.";
            $messageType = 'success';
        } else {
            $message = "File not found or invalid: $filename";
            $messageType = 'error';
        }
    }
}

/**
 * Map transaction category to budget category based on description
 */
function mapToBudgetCategory($category, $description) {
    $desc = strtolower($description);
    $cat = strtolower($category ?? '');
    
    // === INCOME - return null to not categorize as expense ===
    if (strpos($desc, 'payroll') !== false || 
        strpos($desc, 'deposit') !== false ||
        strpos($desc, 'advance from') !== false ||
        strpos($desc, 'from mom') !== false ||
        strpos($desc, 'christmas from') !== false) {
        return null;
    }
    
    // === HOUSING (Mortgage only - loan 4604) ===
    if (strpos($desc, 'fnma') !== false ||
        strpos($desc, 'fannie') !== false ||
        strpos($desc, '4604') !== false) {
        return 'Housing';
    }
    
    // === VEHICLES (Car loan payments - 4190 and 4620) ===
    if (strpos($desc, '4190') !== false ||
        strpos($desc, '4620') !== false ||
        strpos($desc, 'auto loan') !== false ||
        strpos($desc, 'car payment') !== false) {
        return 'Vehicles';
    }
    
    // === TRANSPORTATION (Gas/Fuel) ===
    if (strpos($desc, 'speedway') !== false ||
        strpos($desc, 'marathon') !== false ||
        strpos($desc, 'sunoco') !== false ||
        strpos($desc, 'shell') !== false ||
        strpos($desc, 'bp#') !== false ||
        strpos($desc, 'costco gas') !== false ||
        strpos($desc, 'gas #') !== false ||
        strpos($desc, 'exxon') !== false ||
        strpos($desc, 'chevron') !== false ||
        strpos($desc, 'mobil') !== false ||
        strpos($desc, 'circle k') !== false ||
        strpos($desc, 'loves ') !== false ||
        strpos($desc, 'pilot ') !== false ||
        strpos($desc, 'flying j') !== false ||
        strpos($desc, 'quiktrip') !== false ||
        strpos($desc, 'wawa') !== false ||
        strpos($desc, 'sheetz') !== false ||
        strpos($desc, 'fuel') !== false) {
        return 'Transportation';
    }
    
    // === INSURANCE ===
    if (strpos($desc, 'insurance') !== false ||
        strpos($desc, 'celina') !== false ||
        strpos($desc, 'prog ') !== false ||
        strpos($desc, 'progressive') !== false ||
        strpos($desc, 'geico') !== false ||
        strpos($desc, 'state farm') !== false) {
        return 'Insurance';
    }
    
    // === UTILITIES ===
    if (strpos($desc, 'duke') !== false ||
        strpos($desc, 'electric') !== false ||
        strpos($desc, 'verizon') !== false ||
        strpos($desc, 'frontier') !== false ||
        strpos($desc, 'att ') !== false ||
        strpos($desc, 'water') !== false ||
        strpos($desc, 'gas company') !== false ||
        strpos($desc, 'internet') !== false) {
        return 'Utilities';
    }
    
    // === GROCERIES ===
    if (strpos($desc, 'kroger') !== false ||
        strpos($desc, 'wal-mart') !== false ||
        strpos($desc, 'walmart') !== false ||
        strpos($desc, 'wm supercenter') !== false ||
        strpos($desc, 'sams club') !== false ||
        strpos($desc, 'samsclub') !== false ||
        strpos($desc, 'costco whse') !== false ||
        strpos($desc, 'save-a-lot') !== false ||
        strpos($desc, 'pavey') !== false ||
        strpos($desc, 'aldi') !== false ||
        strpos($desc, 'meijer') !== false ||
        strpos($desc, 'target') !== false ||
        strpos($desc, 'dollar tree') !== false ||
        strpos($desc, 'dollar general') !== false) {
        return 'Groceries';
    }
    
    // === DINING ===
    if (strpos($desc, 'mcdonald') !== false ||
        strpos($desc, 'wendy') !== false ||
        strpos($desc, 'burger king') !== false ||
        strpos($desc, 'arbys') !== false ||
        strpos($desc, 'arby\'s') !== false ||
        strpos($desc, 'taco bell') !== false ||
        strpos($desc, 'chipotle') !== false ||
        strpos($desc, 'dairy queen') !== false ||
        strpos($desc, 'papa john') !== false ||
        strpos($desc, 'domino') !== false ||
        strpos($desc, 'pizza') !== false ||
        strpos($desc, 'el camino') !== false ||
        strpos($desc, 'great china') !== false ||
        strpos($desc, 'ljs ') !== false ||
        strpos($desc, 'long john') !== false ||
        strpos($desc, 'kfc') !== false ||
        strpos($desc, 'chick-fil') !== false ||
        strpos($desc, 'restaurant') !== false ||
        strpos($desc, 'grill') !== false ||
        strpos($desc, 'cafe') !== false ||
        strpos($desc, 'buffet') !== false ||
        strpos($desc, 'diner') !== false) {
        return 'Dining';
    }
    
    // === SUBSCRIPTIONS/MEMBERSHIPS ===
    if (strpos($desc, 'amazon prime') !== false ||
        strpos($desc, 'netflix') !== false ||
        strpos($desc, 'disney') !== false ||
        strpos($desc, 'disneyplus') !== false ||
        strpos($desc, 'hulu') !== false ||
        strpos($desc, 'spotify') !== false ||
        strpos($desc, 'pure flix') !== false ||
        strpos($desc, 'github') !== false ||
        strpos($desc, 'costco.com') !== false ||
        strpos($desc, 'new sams') !== false ||
        strpos($desc, 'membership') !== false) {
        return 'Subscriptions Memberships';
    }
    
    // === GIVING ===
    if (strpos($desc, 'ptc ministries') !== false ||
        strpos($desc, 'church') !== false ||
        strpos($desc, 'tithe') !== false ||
        strpos($desc, 'offering') !== false ||
        strpos($desc, 'donation') !== false ||
        strpos($desc, 'charity') !== false) {
        return 'Giving';
    }
    
    // === HOUSEHOLD ===
    if (strpos($desc, 'tractor supply') !== false ||
        strpos($desc, 'home depot') !== false ||
        strpos($desc, 'lowes') !== false ||
        strpos($desc, 'ace hardware') !== false ||
        strpos($desc, 'menards') !== false) {
        return 'Household';
    }
    
    // === PERSONAL ===
    if (strpos($desc, 'great clips') !== false ||
        strpos($desc, 'salon') !== false ||
        strpos($desc, 'barber') !== false ||
        strpos($desc, 'kohls') !== false ||
        strpos($desc, 'shoe show') !== false ||
        strpos($desc, 'lehigh') !== false ||
        strpos($desc, 'clothing') !== false ||
        strpos($desc, 'venmo') !== false ||
        strpos($desc, 'cinema') !== false ||
        strpos($desc, 'showtime') !== false ||
        strpos($desc, 'entertainment') !== false) {
        return 'Personal';
    }
    
    // === EDUCATION ===
    if (strpos($desc, 'univ of') !== false ||
        strpos($desc, 'university') !== false ||
        strpos($desc, 'college') !== false ||
        strpos($desc, 'tuition') !== false ||
        strpos($desc, 'golay') !== false) {
        return 'Education (Tuition)';
    }
    
    // Fallback - if there's a category from the CSV, use existing logic
    if (!empty($cat)) {
        if (strpos($cat, 'groceries') !== false) return 'Groceries';
        if (strpos($cat, 'dining') !== false || strpos($cat, 'fast food') !== false) return 'Dining';
        if (strpos($cat, 'gas/fuel') !== false) return 'Vehicles';
        if (strpos($cat, 'utilities') !== false) return 'Utilities';
        if (strpos($cat, 'insurance') !== false) return 'Insurance';
        if (strpos($cat, 'subscription') !== false) return 'Subscriptions Memberships';
    }
    
    return 'Misc';
}

// Find existing CSV files in the Budget directory
$csvFiles = glob(__DIR__ . '/Transactions-*.csv');
$existingFiles = array_map('basename', $csvFiles);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Transactions - Mains Budget</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; }
        .banner { background-color: #333; color: white; text-align: center; padding: 20px; font-size: 2em; }
        nav { background-color: #444; padding: 10px; }
        nav ul { list-style: none; margin: 0; padding: 0; display: flex; justify-content: center; flex-wrap: wrap; }
        nav li { margin: 0 15px; }
        nav a { color: white; text-decoration: none; }
        nav a:hover { text-decoration: underline; }
        .container { max-width: 900px; margin: 20px auto; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        
        h2 { color: #333; border-bottom: 2px solid #444; padding-bottom: 10px; }
        
        .form-section { 
            background: #f8f9fa; 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            padding: 20px; 
            margin-bottom: 20px; 
        }
        .form-section h3 { margin-top: 0; color: #444; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input[type="file"],
        .form-group select { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #ddd; 
            border-radius: 4px; 
        }
        
        .btn { 
            padding: 12px 24px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            font-size: 1em; 
        }
        .btn-primary { background-color: #007bff; color: white; }
        .btn-primary:hover { background-color: #0056b3; }
        .btn-success { background-color: #28a745; color: white; }
        .btn-success:hover { background-color: #1e7e34; }
        
        .message { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .info-box {
            background: #e7f3ff;
            border: 1px solid #b3d7ff;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .info-box h4 { margin: 0 0 10px 0; color: #004085; }
        .info-box p { margin: 0; color: #004085; }
        .info-box code { background: #fff; padding: 2px 6px; border-radius: 3px; }
        
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #333; color: white; }
        
        @media (max-width: 768px) {
            .banner { font-size: 1.5em; padding: 15px; }
            nav ul { flex-direction: column; align-items: center; }
            nav li { margin: 5px 0; }
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
        <h2>📥 Import Transactions</h2>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h4>Expected CSV Format</h4>
            <p>The CSV file should have these columns:</p>
            <p><code>Account, ChkRef, Debit, Credit, Balance, Date, Description, Category</code></p>
        </div>
        
        <div class="form-section">
            <h3>Upload New CSV File</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_csv">
                <div class="form-group">
                    <label for="csv_file">Select CSV File:</label>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                </div>
                <button type="submit" class="btn btn-primary">Upload & Import</button>
            </form>
        </div>
        
        <?php if (!empty($existingFiles)): ?>
        <div class="form-section">
            <h3>Import from Existing File</h3>
            <form method="POST">
                <input type="hidden" name="action" value="import_existing">
                <div class="form-group">
                    <label for="filename">Select File:</label>
                    <select id="filename" name="filename" required>
                        <option value="">-- Select File --</option>
                        <?php foreach ($existingFiles as $file): ?>
                            <option value="<?php echo htmlspecialchars($file); ?>">
                                <?php echo htmlspecialchars($file); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-success">Import Selected File</button>
            </form>
        </div>
        <?php endif; ?>
        
        <h2>Recent Imports</h2>
        <p>Last 20 imported transactions:</p>
        <table>
            <tr>
                <th>Date</th>
                <th>Account</th>
                <th>Description</th>
                <th>Amount</th>
                <th>Category</th>
            </tr>
            <?php
            $result = pg_query($database_connection, 
                "SELECT * FROM transactions WHERE is_manual = FALSE OR is_manual IS NULL ORDER BY id DESC LIMIT 20"
            );
            if ($result) {
                while ($row = pg_fetch_assoc($result)) {
                    $amount = $row['debit'] ? '-$' . number_format($row['debit'], 2) : '+$' . number_format($row['credit'], 2);
                    $amountClass = $row['debit'] ? 'style="color:#dc3545;"' : 'style="color:#28a745;"';
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($row['date']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['account']) . "</td>";
                    echo "<td>" . htmlspecialchars(substr($row['description'], 0, 40)) . "</td>";
                    echo "<td $amountClass>$amount</td>";
                    echo "<td>" . htmlspecialchars($row['budget_category'] ?? $row['category'] ?? '') . "</td>";
                    echo "</tr>";
                }
            }
            ?>
        </table>
    </div>
</body>
</html>