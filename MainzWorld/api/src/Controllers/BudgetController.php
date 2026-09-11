<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\BudgetTransactions;
use MainzWorld\Data\BudgetCategories;
use MainzWorld\Data\BudgetCategoryRules;
use MainzWorld\Data\Budgets;
use MainzWorld\Data\Users;
use MainzWorld\Support\Auth;
use MainzWorld\Support\Categorizer;
use MainzWorld\Support\TransactionFileParser;
use Throwable;

final class BudgetController
{
    private const ALLOWED_EXTENSIONS = ['csv', 'xlsx', 'xls'];
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

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
