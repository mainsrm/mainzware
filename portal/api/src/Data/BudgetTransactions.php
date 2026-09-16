<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class BudgetTransactions
{
    public static function all(int $budgetId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, transaction_date, description, merchant, normalized_merchant, amount, category, budget_category, account, is_manual
             FROM budget_transactions WHERE budget_id = :budget_id
             ORDER BY transaction_date DESC, id DESC'
        );
        $stmt->execute(['budget_id' => $budgetId]);

        return $stmt->fetchAll();
    }

    // Parameterized batch insert — never string-concatenate parsed file data into SQL.
    public static function insertMany(array $rows, string $sourceFile, int $budgetId): array
    {
        if (empty($rows)) {
            return ['inserted' => 0, 'skipped_duplicates' => 0];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO budget_transactions
                     (transaction_date, description, merchant, normalized_merchant, amount, category, budget_category, category_confidence, categorization_method, account, chkref, debit, credit, balance, source_file, is_manual, budget_id, import_fingerprint)
             VALUES
                     (:transaction_date, :description, :merchant, :normalized_merchant, :amount, :category, :budget_category, :category_confidence, :categorization_method, :account, :chkref, :debit, :credit, :balance, :source_file, FALSE, :budget_id, :import_fingerprint)
             ON CONFLICT DO NOTHING'
        );

        $inserted = 0;
        $skippedDuplicates = 0;
        foreach ($rows as $row) {
            $fingerprint = hash('md5', implode("\x1f", [
                (string) $row['date'],
                (string) $row['description'],
                (string) ($row['merchant'] ?? ''),
                (string) $row['amount'],
                (string) ($row['account'] ?? ''),
                (string) ($row['chkref'] ?? ''),
                (string) ($row['debit'] ?? ''),
                (string) ($row['credit'] ?? ''),
            ]));
            $stmt->execute([
                'transaction_date' => $row['date'],
                'description' => $row['description'],
                'merchant' => $row['merchant'] ?: null,
                'normalized_merchant' => $row['normalized_merchant'] ?? null,
                'amount' => $row['amount'],
                'category' => $row['category'],
                'budget_category' => $row['budget_category'] ?? null,
                'category_confidence' => $row['category_confidence'] ?? null,
                'categorization_method' => $row['categorization_method'] ?? null,
                'account' => $row['account'] ?: null,
                'chkref' => $row['chkref'] ?? null,
                'debit' => $row['debit'] ?? null,
                'credit' => $row['credit'] ?? null,
                'balance' => $row['balance'] ?? null,
                'source_file' => $sourceFile,
                'budget_id' => $budgetId,
                'import_fingerprint' => $fingerprint,
            ]);
            if ($stmt->rowCount() === 1) $inserted++;
            else $skippedDuplicates++;
        }

        return ['inserted' => $inserted, 'skipped_duplicates' => $skippedDuplicates];
    }

    public static function insertManual(array $row, int $budgetId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO budget_transactions
                     (transaction_date, description, merchant, amount, category, budget_category, account, is_manual, budget_id)
             VALUES
                     (:transaction_date, :description, :merchant, :amount, :category, :budget_category, :account, TRUE, :budget_id)'
        );
        $stmt->execute([
            'transaction_date' => $row['date'],
            'description' => $row['description'],
            'merchant' => $row['merchant'] ?: null,
            'amount' => $row['amount'],
            'category' => $row['category'] ?: null,
            'budget_category' => $row['budget_category'] ?: null,
            'account' => $row['account'] ?: null,
            'budget_id' => $budgetId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function updateBudgetCategory(int $budgetId, int $transactionId, string $category): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE budget_transactions SET budget_category = :category, category = :category
             WHERE id = :id AND budget_id = :budget_id'
        );
        $stmt->execute(['category' => $category, 'id' => $transactionId, 'budget_id' => $budgetId]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Transaction not found.');
        }
    }

    public static function updateMatchingBudgetCategories(int $budgetId, string $pattern, string $matchType, string $category): int
    {
        $column = $matchType === 'description' ? 'description' : 'normalized_merchant';
        $stmt = Database::connection()->prepare(
            "UPDATE budget_transactions
             SET budget_category = :category, category = :category
             WHERE budget_id = :budget_id
               AND POSITION(:pattern IN lower(COALESCE({$column}, ''))) > 0"
        );
        $stmt->execute([
            'budget_id' => $budgetId,
            'pattern' => strtolower($pattern),
            'category' => $category,
        ]);

        return $stmt->rowCount();
    }

    // Mirrors the legacy budget_vs_actual view: budgeted amount vs. amount spent this
    // calendar month per budget_category.
    public static function varianceForMonth(int $budgetId, string $month): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT
                bc.id AS category_id,
                bc.name AS category,
                bc.parent_category_id,
                parent.name AS parent_category,
                bc.budgeted_amount,
                COALESCE(SUM(t.amount), 0) AS actual_spent,
                bc.budgeted_amount - COALESCE(SUM(t.amount), 0) AS variance
                 FROM budget_categories bc
             LEFT JOIN budget_categories parent ON parent.id = bc.parent_category_id
             LEFT JOIN budget_transactions t
                     ON (t.budget_category = bc.name OR EXISTS (
                         SELECT 1 FROM budget_categories child
                         WHERE child.parent_category_id = bc.id AND child.name = t.budget_category
                     )) AND t.budget_id = bc.budget_id
                AND t.amount > 0
                AND t.transaction_date >= CAST(:month_start AS date)
                AND t.transaction_date < CAST(:month_end AS date)
               WHERE bc.budget_id = :budget_id
                         GROUP BY bc.id, bc.name, bc.parent_category_id, parent.name, bc.budgeted_amount, bc.sort_order
                             ORDER BY COALESCE(bc.parent_category_id, bc.id), bc.parent_category_id NULLS FIRST, bc.sort_order, bc.name"
        );
        $monthStart = $month . '-01';
        $monthEnd = (new \DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d');
        $stmt->execute([
            'budget_id' => $budgetId,
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
        ]);

        return $stmt->fetchAll();
    }
}
