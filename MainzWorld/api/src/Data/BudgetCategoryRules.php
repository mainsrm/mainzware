<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class BudgetCategoryRules
{
    public static function all(int $budgetId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, pattern, match_type, category, priority, is_active
             FROM budget_category_rules WHERE budget_id = :budget_id ORDER BY priority, pattern'
        );
        $stmt->execute(['budget_id' => $budgetId]);
        return $stmt->fetchAll();
    }

    public static function create(int $budgetId, string $pattern, string $matchType, string $category): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO budget_category_rules (budget_id, pattern, match_type, category)
             VALUES (:budget_id, :pattern, :match_type, :category)
             ON CONFLICT (budget_id, pattern, match_type)
             DO UPDATE SET category = EXCLUDED.category, is_active = TRUE'
        );
        $stmt->execute([
            'budget_id' => $budgetId,
            'pattern' => strtolower(trim($pattern)),
            'match_type' => $matchType,
            'category' => $category,
        ]);
    }

    public static function match(int $budgetId, string $description, string $merchant): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT pattern, match_type, category FROM budget_category_rules
             WHERE budget_id = :budget_id AND is_active = TRUE ORDER BY priority, id'
        );
        $stmt->execute(['budget_id' => $budgetId]);
        $description = strtolower($description);
        $merchant = strtolower($merchant);
        foreach ($stmt->fetchAll() as $rule) {
            $haystack = $rule['match_type'] === 'description' ? $description : $merchant;
            if (str_contains($haystack, strtolower($rule['pattern']))) {
                return $rule['category'];
            }
        }
        return null;
    }
}
