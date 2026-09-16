<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class ReceiptItems
{
    public static function insertMany(int $receiptId, array $items): void
    {
        if (empty($items)) {
            return;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO receipt_items (receipt_id, description, quantity, unit_price, amount, budget_category, sort_order)
             VALUES (:receipt_id, :description, :quantity, :unit_price, :amount, :budget_category, :sort_order)'
        );
        foreach (array_values($items) as $index => $item) {
            $stmt->execute([
                'receipt_id' => $receiptId,
                'description' => $item['description'],
                'quantity' => $item['quantity'] ?? 1,
                'unit_price' => $item['unit_price'] ?? null,
                'amount' => $item['amount'],
                'budget_category' => $item['budget_category'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    public static function forReceipt(int $receiptId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, description, quantity, unit_price, amount, budget_category, sort_order
             FROM receipt_items WHERE receipt_id = :receipt_id ORDER BY sort_order, id'
        );
        $stmt->execute(['receipt_id' => $receiptId]);
        return $stmt->fetchAll();
    }

    public static function belongsToReceipt(int $itemId, int $receiptId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM receipt_items WHERE id = :id AND receipt_id = :receipt_id'
        );
        $stmt->execute(['id' => $itemId, 'receipt_id' => $receiptId]);
        return $stmt->fetchColumn() !== false;
    }

    public static function append(int $receiptId, string $description, float $amount, ?string $budgetCategory): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO receipt_items (receipt_id, description, quantity, unit_price, amount, budget_category, sort_order)
             VALUES (:receipt_id, :description, 1, :amount, :amount, :budget_category,
                (SELECT COALESCE(MAX(sort_order), -1) + 1 FROM receipt_items WHERE receipt_id = :receipt_id_sub))
             RETURNING id'
        );
        $stmt->execute([
            'receipt_id' => $receiptId,
            'description' => $description,
            'amount' => $amount,
            'budget_category' => $budgetCategory,
            'receipt_id_sub' => $receiptId,
        ]);
        return (int) $stmt->fetchColumn();
    }

    public static function update(int $itemId, string $description, float $amount, ?string $budgetCategory): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE receipt_items SET description = :description, amount = :amount, budget_category = :budget_category
             WHERE id = :id'
        );
        $stmt->execute([
            'description' => $description,
            'amount' => $amount,
            'budget_category' => $budgetCategory,
            'id' => $itemId,
        ]);
    }

    public static function delete(int $itemId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM receipt_items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
    }

    public static function totalFor(int $receiptId): float
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM receipt_items WHERE receipt_id = :receipt_id'
        );
        $stmt->execute(['receipt_id' => $receiptId]);
        return (float) $stmt->fetchColumn();
    }
}
