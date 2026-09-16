<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

// All queries are scoped by user_id so a user can only read or mutate their own
// debts (guards against IDOR on the /debts/{id} routes).
final class Debts
{
    public static function allForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, name, balance, min_payment, apr, sort_order
             FROM debts
             WHERE user_id = :user_id
             ORDER BY sort_order, balance, id'
        );
        $stmt->execute(['user_id' => $userId]);
        return array_map([self::class, 'castRow'], $stmt->fetchAll());
    }

    public static function create(
        int $userId,
        string $name,
        float $balance,
        float $minPayment,
        float $apr
    ): array {
        $stmt = Database::connection()->prepare(
            'INSERT INTO debts (user_id, name, balance, min_payment, apr)
             VALUES (:user_id, :name, :balance, :min_payment, :apr)
             RETURNING id, name, balance, min_payment, apr, sort_order'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'balance' => $balance,
            'min_payment' => $minPayment,
            'apr' => $apr,
        ]);
        return self::castRow($stmt->fetch());
    }

    // Returns the updated row, or null when the debt does not belong to the user.
    public static function update(
        int $id,
        int $userId,
        string $name,
        float $balance,
        float $minPayment,
        float $apr
    ): ?array {
        $stmt = Database::connection()->prepare(
            'UPDATE debts
             SET name = :name, balance = :balance, min_payment = :min_payment, apr = :apr
             WHERE id = :id AND user_id = :user_id
             RETURNING id, name, balance, min_payment, apr, sort_order'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'balance' => $balance,
            'min_payment' => $minPayment,
            'apr' => $apr,
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : self::castRow($row);
    }

    // Returns true when a row was deleted (i.e. it existed and belonged to the user).
    public static function delete(int $id, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM debts WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    // Postgres NUMERIC columns come back as strings via PDO; expose them as numbers.
    private static function castRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'balance' => (float) $row['balance'],
            'min_payment' => (float) $row['min_payment'],
            'apr' => (float) $row['apr'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }
}
