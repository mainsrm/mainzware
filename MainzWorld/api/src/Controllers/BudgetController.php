<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\BudgetTransactions;
use MainzWorld\Data\BudgetCategories;
use MainzWorld\Data\BudgetCategoryRules;
use MainzWorld\Data\Budgets;
use MainzWorld\Data\Receipts;
use MainzWorld\Data\ReceiptItems;
use MainzWorld\Data\Users;
use MainzWorld\Support\Auth;
use MainzWorld\Support\Categorizer;
use MainzWorld\Support\ReceiptItemParser;
use MainzWorld\Support\ReceiptOcr;
use MainzWorld\Support\TransactionFileParser;
use Throwable;

final class BudgetController
{
    private const ALLOWED_EXTENSIONS = ['csv', 'xlsx', 'xls'];
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB
    private const ALLOWED_RECEIPT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'heic', 'pdf'];
    private const MAX_RECEIPT_BYTES = 10 * 1024 * 1024; // 10 MB

    public function index(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->selectedBudgetId($user['id']);
        if ($budgetId === null) return;
        echo json_encode(BudgetTransactions::all($budgetId), JSON_THROW_ON_ERROR);
    }

    public function summary(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->selectedBudgetId($user['id']);
        if ($budgetId === null) return;
        $month = $_GET['month'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->fail(400, 'Month must use YYYY-MM format.');
            return;
        }
        echo json_encode(BudgetTransactions::varianceForMonth($budgetId, $month), JSON_THROW_ON_ERROR);
    }

    public function categories(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->selectedBudgetId($user['id']);
        if ($budgetId === null) return;
        echo json_encode(BudgetCategories::all($budgetId), JSON_THROW_ON_ERROR);
    }

    public function rules(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->selectedBudgetId($user['id']);
        if ($budgetId === null) return;
        echo json_encode(BudgetCategoryRules::all($budgetId), JSON_THROW_ON_ERROR);
    }

    public function createRule(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->writableBudgetId($user['id']);
        if ($budgetId === null) return;
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $pattern = trim((string) ($body['pattern'] ?? ''));
        $category = trim((string) ($body['category'] ?? ''));
        $matchType = $body['match_type'] ?? 'merchant';
        if ($pattern === '' || $category === '' || !in_array($matchType, ['merchant', 'description'], true)) {
            $this->fail(400, 'Pattern, category, and valid match_type are required.');
            return;
        }
        BudgetCategoryRules::create($budgetId, $pattern, $matchType, $category);
        $updated = BudgetTransactions::updateMatchingBudgetCategories($budgetId, $pattern, $matchType, $category);
        echo json_encode(['ok' => true, 'updated' => $updated], JSON_THROW_ON_ERROR);
    }

    public function createCategory(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->writableBudgetId($user['id']);
        if ($budgetId === null) return;
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $name = trim((string) ($body['name'] ?? ''));
        $parentCategoryId = isset($body['parent_category_id']) && $body['parent_category_id'] !== ''
            ? (int) $body['parent_category_id']
            : null;
        if ($name === '') {
            $this->fail(400, 'Category name is required.');
            return;
        }
        try {
            echo json_encode(['id' => BudgetCategories::create($budgetId, $name, $parentCategoryId)], JSON_THROW_ON_ERROR);
        } catch (\InvalidArgumentException $error) {
            $this->fail(404, $error->getMessage());
            return;
        } catch (\PDOException $error) {
            if ($error->getCode() === '23505') {
                $this->fail(409, 'That category already exists in this budget.');
                return;
            }
            throw $error;
        }
    }

    public function updateTransactionCategory(int $transactionId): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->writableBudgetId($user['id']);
        if ($budgetId === null) return;
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $category = trim((string) ($body['category'] ?? ''));
        if ($category === '') {
            $this->fail(400, 'Category is required.');
            return;
        }
        try {
            BudgetTransactions::updateBudgetCategory($budgetId, $transactionId, $category);
        } catch (\InvalidArgumentException $error) {
            $this->fail(404, $error->getMessage());
            return;
        }
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }

    public function updateCategory(int $categoryId): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->writableBudgetId($user['id']);
        if ($budgetId === null) return;

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!isset($body['budgeted_amount']) || !is_numeric($body['budgeted_amount']) || (float) $body['budgeted_amount'] < 0) {
            $this->fail(400, 'A non-negative budgeted_amount is required.');
            return;
        }

        try {
            BudgetCategories::updateAmount($budgetId, $categoryId, (float) $body['budgeted_amount']);
        } catch (\InvalidArgumentException $error) {
            $this->fail(404, $error->getMessage());
            return;
        }
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }

    public function addManual(): void
    {
        header('Content-Type: application/json');

        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->writableBudgetId($user['id']);
        if ($budgetId === null) return;

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($body) || empty($body['date']) || empty($body['description']) || !isset($body['amount'])) {
            $this->fail(400, 'Fields "date", "description", and "amount" are required.');
            return;
        }

        $amount = (float) $body['amount'];
        $budgetCategory = $body['budget_category'] ?? Categorizer::budgetCategory((string) $body['description']);

        $id = BudgetTransactions::insertManual([
            'date' => $body['date'],
            'description' => $body['description'],
            'merchant' => $body['merchant'] ?? null,
            'amount' => $amount,
            'category' => $body['category'] ?? null,
            'budget_category' => $budgetCategory,
            'account' => $body['account'] ?? null,
        ], $budgetId);

        echo json_encode(['id' => $id], JSON_THROW_ON_ERROR);
    }

    public function import(): void
    {
        header('Content-Type: application/json');

        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->writableBudgetId($user['id']);
        if ($budgetId === null) return;

        $file = $_FILES['file'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $this->fail(400, 'No valid file uploaded (field name must be "file").');
            return;
        }

        if ($file['size'] > self::MAX_BYTES) {
            $this->fail(400, 'File exceeds the 5MB limit.');
            return;
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $this->fail(400, 'Only .csv, .xlsx, and .xls files are supported.');
            return;
        }

        try {
            $rows = TransactionFileParser::parse($file['tmp_name'], $file['name']);
        } catch (Throwable $e) {
            $this->fail(400, $e->getMessage());
            return;
        }

        foreach ($rows as &$row) {
            $merchant = Categorizer::merchant($row['description']);
            $ruleCategory = BudgetCategoryRules::match($budgetId, $row['description'], $merchant);
            $classification = $ruleCategory === null
                ? Categorizer::classify($row['description'], $row['category'] ?? null)
                : [
                    'category' => $ruleCategory,
                    'budget_category' => $ruleCategory,
                    'confidence' => 0.99,
                    'method' => 'user-rule',
                    'merchant' => $merchant,
                ];
            $row['category'] = $classification['category'];
            $row['budget_category'] = $classification['budget_category'];
            $row['normalized_merchant'] = $classification['merchant'];
            $row['category_confidence'] = $classification['confidence'];
            $row['categorization_method'] = $classification['method'];
        }
        unset($row);

        $importResult = BudgetTransactions::insertMany($rows, basename($file['name']), $budgetId);

        echo json_encode([
            'imported' => $importResult['inserted'],
            'skipped_duplicates' => $importResult['skipped_duplicates'],
        ], JSON_THROW_ON_ERROR);
    }

    // Stores an uploaded receipt photo/PDF and records it as 'pending'. Itemization (OCR
    // parsing into receipt_items) happens out-of-band via a background worker, not here.
    public function uploadReceipt(): void
    {
        header('Content-Type: application/json');

        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->writableBudgetId($user['id']);
        if ($budgetId === null) return;

        $file = $_FILES['file'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $this->fail(400, 'No valid file uploaded (field name must be "file").');
            return;
        }

        if ($file['size'] > self::MAX_RECEIPT_BYTES) {
            $this->fail(400, 'File exceeds the 10MB limit.');
            return;
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_RECEIPT_EXTENSIONS, true)) {
            $this->fail(400, 'Only JPG, PNG, HEIC, and PDF receipts are supported.');
            return;
        }

        $mimeType = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';

        $uploadsDir = __DIR__ . '/../../public/uploads/receipts/' . $budgetId;
        if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
            $this->fail(500, 'Could not create the upload directory.');
            return;
        }

        // Random filename avoids collisions and leaking the original filename in the URL.
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $uploadsDir . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $this->fail(500, 'Could not save the uploaded file.');
            return;
        }

        $storagePath = 'uploads/receipts/' . $budgetId . '/' . $storedName;
        $receiptId = Receipts::create(
            $budgetId,
            (int) $user['id'],
            basename($file['name']),
            $storagePath,
            $mimeType,
            (int) $file['size']
        );

        echo json_encode(['id' => $receiptId, 'status' => 'pending'], JSON_THROW_ON_ERROR);
    }

    public function receipts(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->selectedBudgetId($user['id']);
        if ($budgetId === null) return;
        echo json_encode(Receipts::forBudget($budgetId), JSON_THROW_ON_ERROR);
    }

    public function showReceipt(int $id): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->selectedBudgetId($user['id']);
        if ($budgetId === null) return;

        $receipt = Receipts::find($id, $budgetId);
        if ($receipt === null) {
            $this->fail(404, 'Receipt not found.');
            return;
        }

        echo json_encode([
            'id' => (int) $receipt['id'],
            'status' => $receipt['status'],
            'original_filename' => $receipt['original_filename'],
            'merchant' => $receipt['merchant'],
            'purchase_date' => $receipt['purchase_date'],
            'total_amount' => $receipt['total_amount'],
            'transaction_id' => $receipt['transaction_id'] !== null ? (int) $receipt['transaction_id'] : null,
            'items' => ReceiptItems::forReceipt($id),
        ], JSON_THROW_ON_ERROR);
    }

    // Runs OCR against the stored image and parses candidate line items. Results are
    // provisional — the caller reviews/edits items before confirmReceipt() turns them
    // into a budget transaction.
    public function processReceipt(int $id): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->writableBudgetId($user['id']);
        if ($budgetId === null) return;

        $receipt = Receipts::find($id, $budgetId);
        if ($receipt === null) {
            $this->fail(404, 'Receipt not found.');
            return;
        }

        Receipts::markProcessing($id);

        $imagePath = __DIR__ . '/../../public/' . $receipt['storage_path'];
        try {
            $rawText = ReceiptOcr::extractText($imagePath);
            $parsed = ReceiptItemParser::parse($rawText);
        } catch (Throwable $e) {
            Receipts::markFailed($id, $e->getMessage());
            $this->fail(422, 'Could not process receipt: ' . $e->getMessage());
            return;
        }

        foreach ($parsed['items'] as &$item) {
            $classification = Categorizer::classify($item['description']);
            $item['budget_category'] = $classification['budget_category'] ?? $classification['category'];
        }
        unset($item);

        ReceiptItems::insertMany($id, $parsed['items']);
        Receipts::markProcessed($id, $parsed['merchant'], $parsed['total'], $parsed['purchase_date'], ['text' => $rawText]);

        echo json_encode([
            'id' => $id,
            'status' => 'processed',
            'merchant' => $parsed['merchant'],
            'total' => $parsed['total'],
            'purchase_date' => $parsed['purchase_date'],
            'items' => ReceiptItems::forReceipt($id),
        ], JSON_THROW_ON_ERROR);
    }

    public function addReceiptItem(int $receiptId): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->writableBudgetId($user['id']);
        if ($budgetId === null) return;
        if (Receipts::find($receiptId, $budgetId) === null) {
            $this->fail(404, 'Receipt not found.');
            return;
        }

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $description = trim((string) ($body['description'] ?? ''));
        $amount = (float) ($body['amount'] ?? 0);
        if ($description === '' || $amount <= 0) {
            $this->fail(400, 'description and a positive amount are required.');
            return;
        }

        $itemId = ReceiptItems::append($receiptId, $description, $amount, $body['budget_category'] ?? null);
        echo json_encode(['id' => $itemId], JSON_THROW_ON_ERROR);
    }

    public function updateReceiptItem(int $receiptId, int $itemId): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->writableBudgetId($user['id']);
        if ($budgetId === null) return;
        if (Receipts::find($receiptId, $budgetId) === null || !ReceiptItems::belongsToReceipt($itemId, $receiptId)) {
            $this->fail(404, 'Receipt item not found.');
            return;
        }

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $description = trim((string) ($body['description'] ?? ''));
        $amount = (float) ($body['amount'] ?? 0);
        if ($description === '' || $amount <= 0) {
            $this->fail(400, 'description and a positive amount are required.');
            return;
        }

        ReceiptItems::update($itemId, $description, $amount, $body['budget_category'] ?? null);
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }

    public function deleteReceiptItem(int $receiptId, int $itemId): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->writableBudgetId($user['id']);
        if ($budgetId === null) return;
        if (Receipts::find($receiptId, $budgetId) === null || !ReceiptItems::belongsToReceipt($itemId, $receiptId)) {
            $this->fail(404, 'Receipt item not found.');
            return;
        }

        ReceiptItems::delete($itemId);
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }

    // Turns a reviewed receipt into a real budget transaction, linked back to the receipt.
    public function confirmReceipt(int $id): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $budgetId = $this->writableBudgetId($user['id']);
        if ($budgetId === null) return;

        $receipt = Receipts::find($id, $budgetId);
        if ($receipt === null) {
            $this->fail(404, 'Receipt not found.');
            return;
        }
        if ($receipt['transaction_id'] !== null) {
            $this->fail(409, 'Receipt is already linked to a transaction.');
            return;
        }

        $items = ReceiptItems::forReceipt($id);
        if (empty($items)) {
            $this->fail(400, 'Add at least one line item before confirming.');
            return;
        }

        $total = $receipt['total_amount'] !== null ? (float) $receipt['total_amount'] : ReceiptItems::totalFor($id);

        $categoryCounts = [];
        foreach ($items as $item) {
            $category = $item['budget_category'] ?? 'Misc';
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
        }
        arsort($categoryCounts);
        $dominantCategory = array_key_first($categoryCounts) ?? 'Misc';

        $transactionId = BudgetTransactions::insertManual([
            'date' => $receipt['purchase_date'] ?? date('Y-m-d'),
            'description' => 'Receipt: ' . ($receipt['merchant'] ?? $receipt['original_filename']),
            'merchant' => $receipt['merchant'],
            'amount' => -abs($total),
            'category' => $dominantCategory,
            'budget_category' => $dominantCategory,
            'account' => null,
        ], $budgetId);

        Receipts::linkTransaction($id, $transactionId);

        echo json_encode(['transaction_id' => $transactionId], JSON_THROW_ON_ERROR);
    }

    public function budgets(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        echo json_encode(Budgets::accessibleTo($user['id']), JSON_THROW_ON_ERROR);
    }

    public function createBudget(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            $this->fail(400, 'Budget name is required.');
            return;
        }
        echo json_encode(['id' => Budgets::create($user['id'], $name)], JSON_THROW_ON_ERROR);
    }

    public function deleteBudget(int $budgetId): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        try {
            Budgets::deleteOwned($budgetId, (int) $user['id']);
        } catch (\InvalidArgumentException $error) {
            $this->fail(403, $error->getMessage());
            return;
        }
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }

    public function renameBudget(int $budgetId): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            $this->fail(400, 'Budget name is required.');
            return;
        }
        try {
            Budgets::renameOwned($budgetId, (int) $user['id'], $name);
        } catch (\InvalidArgumentException $error) {
            $this->fail(403, $error->getMessage());
            return;
        }
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }

    public function share(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $budgetId = (int) ($body['budget_id'] ?? 0);
        if (Budgets::findPermission($budgetId, $user['id']) !== 'owner') {
            $this->fail(403, 'Only the budget owner can share it.');
            return;
        }
        $permission = $body['permission'] ?? 'viewer';
        if (!in_array($permission, ['editor', 'viewer'], true) || empty($body['username'])) {
            $this->fail(400, 'Username and permission (editor/viewer) are required.');
            return;
        }
        try {
            Budgets::share($budgetId, trim((string) $body['username']), $permission);
        } catch (\InvalidArgumentException $error) {
            $this->fail(404, $error->getMessage());
            return;
        }
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }

    public function members(int $budgetId): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        if (Budgets::findPermission($budgetId, (int) $user['id']) !== 'owner') {
            $this->fail(403, 'Only the budget owner can view sharing details.');
            return;
        }
        echo json_encode([
            'members' => Budgets::members($budgetId),
            'available_users' => Users::activeExcept((int) $user['id']),
        ], JSON_THROW_ON_ERROR);
    }

    public function unshare(int $budgetId, int $memberId): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        if (Budgets::findPermission($budgetId, (int) $user['id']) !== 'owner') {
            $this->fail(403, 'Only the budget owner can remove sharing access.');
            return;
        }
        try {
            Budgets::unshare($budgetId, $memberId);
        } catch (\InvalidArgumentException $error) {
            $this->fail(404, $error->getMessage());
            return;
        }
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }

    private function selectedBudgetId(int $userId): ?int
    {
        $requested = (int) ($_GET['budget_id'] ?? 0);
        $budgetId = $requested > 0 ? $requested : Budgets::defaultFor($userId);
        if ($budgetId === null || Budgets::findPermission($budgetId, $userId) === null) {
            $this->fail(404, 'No accessible budget selected.');
            return null;
        }
        return $budgetId;
    }

    private function writableBudgetId(int $userId): ?int
    {
        $budgetId = $this->selectedBudgetId($userId);
        if ($budgetId === null) return null;
        if (!in_array(Budgets::findPermission($budgetId, $userId), ['owner', 'editor'], true)) {
            $this->fail(403, 'You have read-only access to this budget.');
            return null;
        }
        return $budgetId;
    }

    private function fail(int $status, string $message): void
    {
        http_response_code($status);
        echo json_encode(['error' => $message], JSON_THROW_ON_ERROR);
    }
}
