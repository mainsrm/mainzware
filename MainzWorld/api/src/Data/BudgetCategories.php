<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class BudgetCategories
{
    public static function all(int $budgetId): array
    {
        $stmt = Database::connection()->prepare(
                'SELECT category.id, category.name, category.budgeted_amount, category.sort_order,
                    category.parent_category_id, parent.name AS parent_category
                 FROM budget_categories category
                 LEFT JOIN budget_categories parent ON parent.id = category.parent_category_id
                 WHERE category.budget_id = :budget_id
                 ORDER BY COALESCE(category.parent_category_id, category.id), category.parent_category_id NULLS FIRST, category.sort_order, category.name'
        );
        $stmt->execute(['budget_id' => $budgetId]);
        return $stmt->fetchAll();
    }

    public static function create(int $budgetId, string $name, ?int $parentCategoryId = null): int
    {
        if ($parentCategoryId !== null && !self::isTopLevelInBudget($budgetId, $parentCategoryId)) {
            throw new \InvalidArgumentException('Parent category not found.');
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO budget_categories (budget_id, name, budgeted_amount, parent_category_id)
             VALUES (:budget_id, :name, 0, :parent_category_id) RETURNING id'
        );
        $stmt->execute(['budget_id' => $budgetId, 'name' => $name, 'parent_category_id' => $parentCategoryId]);
        return (int) $stmt->fetchColumn();
    }

    private static function isTopLevelInBudget(int $budgetId, int $categoryId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM budget_categories
             WHERE id = :id AND budget_id = :budget_id AND parent_category_id IS NULL'
        );
        $stmt->execute(['id' => $categoryId, 'budget_id' => $budgetId]);
        return $stmt->fetchColumn() !== false;
    }

    public static function updateAmount(int $budgetId, int $categoryId, float $amount): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE budget_categories
             SET budgeted_amount = :amount, updated_at = now()
             WHERE id = :id AND budget_id = :budget_id'
        );
        $stmt->execute(['amount' => $amount, 'id' => $categoryId, 'budget_id' => $budgetId]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Budget category not found.');
        }
    }
}
