<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class Receipts
{
    public static function create(
        int $budgetId,
        int $uploadedBy,
        string $originalFilename,
        string $storagePath,
        string $mimeType,
        int $sizeBytes
    ): int {
        $stmt = Database::connection()->prepare(
            'INSERT INTO receipts (budget_id, uploaded_by, original_filename, storage_path, mime_type, size_bytes)
             VALUES (:budget_id, :uploaded_by, :original_filename, :storage_path, :mime_type, :size_bytes)
             RETURNING id'
        );
        $stmt->execute([
            'budget_id' => $budgetId,
            'uploaded_by' => $uploadedBy,
            'original_filename' => $originalFilename,
            'storage_path' => $storagePath,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
        ]);
        return (int) $stmt->fetchColumn();
    }

    public static function forBudget(int $budgetId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, original_filename, status, merchant, purchase_date, total_amount, transaction_id, created_at
             FROM receipts WHERE budget_id = :budget_id ORDER BY created_at DESC'
        );
        $stmt->execute(['budget_id' => $budgetId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id, int $budgetId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM receipts WHERE id = :id AND budget_id = :budget_id'
        );
        $stmt->execute(['id' => $id, 'budget_id' => $budgetId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function markProcessing(int $id): void
    {
        $stmt = Database::connection()->prepare("UPDATE receipts SET status = 'processing', updated_at = now() WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    public static function markProcessed(int $id, ?string $merchant, ?float $total, ?string $purchaseDate, array $ocrRaw): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE receipts SET status = 'processed', merchant = :merchant, total_amount = :total_amount,
                purchase_date = :purchase_date, ocr_raw = :ocr_raw, updated_at = now() WHERE id = :id"
        );
        $stmt->execute([
            'merchant' => $merchant,
            'total_amount' => $total,
            'purchase_date' => $purchaseDate,
            'ocr_raw' => json_encode($ocrRaw, JSON_THROW_ON_ERROR),
            'id' => $id,
        ]);
    }

    public static function markFailed(int $id, string $reason): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE receipts SET status = 'failed', ocr_raw = :ocr_raw, updated_at = now() WHERE id = :id"
        );
        $stmt->execute(['ocr_raw' => json_encode(['error' => $reason], JSON_THROW_ON_ERROR), 'id' => $id]);
    }

    public static function linkTransaction(int $id, int $transactionId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE receipts SET transaction_id = :transaction_id, updated_at = now() WHERE id = :id'
        );
        $stmt->execute(['transaction_id' => $transactionId, 'id' => $id]);
    }
}
