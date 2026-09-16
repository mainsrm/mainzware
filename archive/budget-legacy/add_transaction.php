<?php
include '../CMS/Connect_to_Postgres.php';

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'add_transaction') {
        $account = trim($_POST['account'] ?? 'MANUAL');
        $date = $_POST['date'] ?? date('Y-m-d');
        $description = trim($_POST['description'] ?? '');
        $amount = floatval(str_replace(['$', ','], '', $_POST['amount'] ?? '0'));
        $type = $_POST['type'] ?? 'debit'; // debit = expense, credit = income
        $category = trim($_POST['category'] ?? '');
        $budgetCategory = trim($_POST['budget_category'] ?? $category); // Map to budget category
        $chkref = trim($_POST['chkref'] ?? '');
        
        if ($description && $amount > 0) {
            $debit = ($type === 'debit') ? $amount : null;
            $credit = ($type === 'credit') ? $amount : null;
            
            $query = "INSERT INTO transactions (account, chkref, debit, credit, balance, date, description, category, budget_category, is_manual) 
                      VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, TRUE)";
            $result = pg_query_params($database_connection, $query, [
                $account, 
                $chkref ?: null, 
                $debit, 
                $credit, 
                null, // balance not tracked for manual entries
                $date, 
                $description, 
                $category,
                $budgetCategory ?: null
            ]);
            
            if ($result) {
                $message = "Transaction added successfully!";
                $messageType = 'success';
            } else {
                $message = "Error adding transaction: " . pg_last_error($database_connection);
                $messageType = 'error';
            }
        } else {
            $message = "Please fill in all required fields (description and amount).";
            $messageType = 'error';
        }
    }
    
    if ($_POST['action'] === 'delete_transaction' && isset($_POST['transaction_id'])) {
        $id = intval($_POST['transaction_id']);
        $result = pg_query_params($database_connection, "DELETE FROM transactions WHERE id = $1", [$id]);
        if ($result) {
            $message = "Transaction deleted successfully!";
            $messageType = 'success';
        } else {
            $message = "Error deleting transaction.";
            $messageType = 'error';
        }
    }
}

// Get unique categories from existing transactions
$categories = [];
$catResult = pg_query($database_connection, "SELECT DISTINCT category FROM transactions WHERE category IS NOT NULL AND category != '' ORDER BY category");
while ($row = pg_fetch_assoc($catResult)) {
    $categories[] = $row['category'];
}

// Also get expense categories from the expense_categories table (if exists)
$expCatResult = @pg_query($database_connection, "SELECT name FROM expense_categories ORDER BY name");
if ($expCatResult) {
    while ($row = pg_fetch_assoc($expCatResult)) {
        if (!in_array($row['name'], $categories)) {
            $categories[] = $row['name'];
        }
    }
}
sort($categories);

// Get budget categories for mapping
$budgetCategories = [];
$budgetCatResult = @pg_query($database_connection, "SELECT name FROM budget_categories ORDER BY sort_order, name");
if ($budgetCatResult) {
    while ($row = pg_fetch_assoc($budgetCatResult)) {
        $budgetCategories[] = $row['name'];
    }
}

// Get unique accounts
$accounts = [];
$accResult = pg_query($database_connection, "SELECT DISTINCT account FROM transactions WHERE account IS NOT NULL ORDER BY account");
while ($row = pg_fetch_assoc($accResult)) {
    $accounts[] = $row['account'];
}
if (!in_array('MANUAL', $accounts)) {
    array_unshift($accounts, 'MANUAL');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Transaction - Mains Budget</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; }
        .banner { background-color: #333; color: white; text-align: center; padding: 20px; font-size: 2em; }
        nav { background-color: #444; padding: 10px; }
        nav ul { list-style: none; margin: 0; padding: 0; display: flex; justify-content: center; flex-wrap: wrap; }
        nav li { margin: 0 15px; }
        nav a { color: white; text-decoration: none; }
        .container { max-width: 800px; margin: 20px auto; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        .form-group input, .form-group select, .form-group textarea { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #ddd; 
            border-radius: 4px; 
            font-size: 1em;
            box-sizing: border-box;
        }
        .form-group input:focus, .form-group select:focus { 
            border-color: #4CAF50; 
            outline: none; 
        }
        
        .form-row { display: flex; gap: 15px; }
        .form-row .form-group { flex: 1; }
        
        .radio-group { display: flex; gap: 20px; margin-top: 5px; }
        .radio-group label { display: flex; align-items: center; font-weight: normal; cursor: pointer; }
        .radio-group input[type="radio"] { width: auto; margin-right: 5px; }
        
        .btn { 
            padding: 12px 24px; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            font-size: 1em; 
            margin-right: 10px;
        }
        .btn-primary { background-color: #4CAF50; color: white; }
        .btn-primary:hover { background-color: #45a049; }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn-secondary:hover { background-color: #5a6268; }
        .btn-danger { background-color: #dc3545; color: white; padding: 5px 10px; font-size: 0.8em; }
        .btn-danger:hover { background-color: #c82333; }
        
        .message { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .quick-amounts { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 5px; }
        .quick-amount { 
            padding: 5px 10px; 
            background: #e9e9e9; 
            border: 1px solid #ddd; 
            border-radius: 4px; 
            cursor: pointer; 
            font-size: 0.9em;
        }
        .quick-amount:hover { background: #d9d9d9; }
        
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .table-container { overflow-x: auto; }
        
        .debit { color: #dc3545; }
        .credit { color: #28a745; }
        
        h2 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
        
        @media (max-width: 768px) {
            .banner { font-size: 1.5em; padding: 15px; }
            nav ul { flex-direction: column; align-items: center; }
            nav li { margin: 5px 0; }
            .form-row { flex-direction: column; gap: 0; }
            .container { margin: 10px; padding: 15px; }
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
        <h2>Add New Transaction</h2>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_transaction">
            
            <div class="form-row">
                <div class="form-group">
                    <label for="date">Date *</label>
                    <input type="date" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="account">Account</label>
                    <select id="account" name="account">
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo htmlspecialchars($acc); ?>" <?php echo $acc === 'MANUAL' ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($acc); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="CASH">CASH</option>
                        <option value="OTHER">OTHER</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="description">Description *</label>
                <input type="text" id="description" name="description" placeholder="e.g., Grocery shopping at Walmart" required>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="amount">Amount *</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
                    <div class="quick-amounts">
                        <span class="quick-amount" onclick="document.getElementById('amount').value='5.00'">$5</span>
                        <span class="quick-amount" onclick="document.getElementById('amount').value='10.00'">$10</span>
                        <span class="quick-amount" onclick="document.getElementById('amount').value='20.00'">$20</span>
                        <span class="quick-amount" onclick="document.getElementById('amount').value='50.00'">$50</span>
                        <span class="quick-amount" onclick="document.getElementById('amount').value='100.00'">$100</span>
                    </div>
                </div>
                <div class="form-group">
                    <label>Transaction Type *</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="type" value="debit" checked> Expense (Debit)
                        </label>
                        <label>
                            <input type="radio" name="type" value="credit"> Income (Credit)
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="category">Transaction Category</label>
                    <select id="category" name="category">
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>">
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="budget_category">Budget Category</label>
                    <select id="budget_category" name="budget_category">
                        <option value="">-- Map to Budget --</option>
                        <?php foreach ($budgetCategories as $bcat): ?>
                            <option value="<?php echo htmlspecialchars($bcat); ?>">
                                <?php echo htmlspecialchars($bcat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="chkref">Check/Reference # (Optional)</label>
                <input type="text" id="chkref" name="chkref" placeholder="Check number or reference">
            </div>
            
            <div style="margin-top: 20px;">
                <button type="submit" class="btn btn-primary">Add Transaction</button>
                <button type="reset" class="btn btn-secondary">Clear Form</button>
            </div>
        </form>
        
        <h2 style="margin-top: 40px;">Recent Transactions</h2>
        <div class="table-container">
            <table>
                <tr>
                    <th>Date</th>
                    <th>Account</th>
                    <th>Description</th>
                    <th>Amount</th>
                    <th>Category</th>
                    <th>Action</th>
                </tr>
                <?php
                $result = pg_query($database_connection, "SELECT * FROM transactions ORDER BY date DESC, id DESC LIMIT 20");
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
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['date']); ?></td>
                        <td><?php echo htmlspecialchars($row['account']); ?></td>
                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                        <td class="<?php echo $amountClass; ?>"><?php echo $amount; ?></td>
                        <td><?php echo htmlspecialchars($row['category']); ?></td>
                        <td>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this transaction?');">
                                <input type="hidden" name="action" value="delete_transaction">
                                <input type="hidden" name="transaction_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" class="btn btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </table>
        </div>
    </div>
    
    <script>
        // Auto-focus on description field after page load
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('description').focus();
        });
    </script>
</body>
</html>
