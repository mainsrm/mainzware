<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;
use MainzWorld\Data\BudgetCategories;

final class Budgets
{
    public static function accessibleTo(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT b.id, b.name, b.owner_id, bm.permission
             FROM budgets b
             JOIN budget_members bm ON bm.budget_id = b.id
             WHERE bm.user_id = :user_id
             ORDER BY b.id'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public static function findPermission(int $budgetId, int $userId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT permission FROM budget_members WHERE budget_id = :budget_id AND user_id = :user_id'
        );
        $stmt->execute(['budget_id' => $budgetId, 'user_id' => $userId]);
        $permission = $stmt->fetchColumn();
        return $permission === false ? null : (string) $permission;
    }

    public static function defaultFor(int $userId): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT budget_id FROM budget_members WHERE user_id = :user_id ORDER BY budget_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public static function create(int $ownerId, string $name): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO budgets (owner_id, name) VALUES (:owner_id, :name) RETURNING id');
            $stmt->execute(['owner_id' => $ownerId, 'name' => $name]);
            $id = (int) $stmt->fetchColumn();
            $member = $pdo->prepare(
                "INSERT INTO budget_members (budget_id, user_id, permission) VALUES (:budget_id, :user_id, 'owner')"
            );
            $member->execute(['budget_id' => $id, 'user_id' => $ownerId]);
            BudgetCategories::seedDefaults($pdo, $id);
            $pdo->commit();
            return $id;
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    public static function share(int $budgetId, string $username, string $permission): void
    {
        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE username = :username AND is_active = TRUE');
        $stmt->execute(['username' => $username]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            throw new \InvalidArgumentException('Active user not found.');
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO budget_members (budget_id, user_id, permission)
             VALUES (:budget_id, :user_id, :permission)
             ON CONFLICT (budget_id, user_id) DO UPDATE SET permission = EXCLUDED.permission'
        );
        $stmt->execute(['budget_id' => $budgetId, 'user_id' => $userId, 'permission' => $permission]);
    }

    public static function unshare(int $budgetId, int $memberId): void
    {
        $stmt = Database::connection()->prepare(
            "DELETE FROM budget_members
             WHERE budget_id = :budget_id AND user_id = :user_id AND permission <> 'owner'"
        );
        $stmt->execute(['budget_id' => $budgetId, 'user_id' => $memberId]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Shared user not found, or owner access cannot be removed.');
        }
    }

    public static function deleteOwned(int $budgetId, int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM budgets WHERE id = :budget_id AND owner_id = :user_id'
        );
        $stmt->execute(['budget_id' => $budgetId, 'user_id' => $userId]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Budget not found or you are not the owner.');
        }
    }

    public static function renameOwned(int $budgetId, int $userId, string $name): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE budgets SET name = :name WHERE id = :budget_id AND owner_id = :user_id'
        );
        $stmt->execute(['name' => $name, 'budget_id' => $budgetId, 'user_id' => $userId]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Budget not found or you are not the owner.');
        }
    }

    public static function members(int $budgetId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.username, bm.permission FROM budget_members bm
             JOIN users u ON u.id = bm.user_id WHERE bm.budget_id = :budget_id ORDER BY u.username'
        );
        $stmt->execute(['budget_id' => $budgetId]);
        return $stmt->fetchAll();
    }
}
