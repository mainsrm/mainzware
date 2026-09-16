<?php
declare(strict_types=1);

namespace MainzWorld\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

// Parses uploaded CSV/XLS/XLSX bank exports using normalized, case-insensitive headers.
final class TransactionFileParser
{
    private const HEADER_ALIASES = [
        'date' => ['date', 'transaction date', 'posted date', 'posting date', 'post date'],
        'description' => ['description', 'transaction description', 'memo', 'details', 'name'],
        'merchant' => ['merchant', 'payee', 'vendor'],
        'amount' => ['amount', 'transaction amount'],
        'debit' => ['debit', 'withdrawal', 'withdrawals', 'money out'],
        'credit' => ['credit', 'deposit', 'deposits', 'money in'],
        'account' => ['account', 'account number', 'account name'],
        'category' => ['category', 'transaction category'],
        'chkref' => ['chkref', 'check ref', 'check reference', 'reference', 'reference number'],
        'balance' => ['balance', 'running balance'],
    ];

    public static function parse(string $filePath, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $rows = $extension === 'csv'
            ? self::parseCsv($filePath)
            : self::parseSpreadsheet($filePath);

        if (empty($rows)) {
            return [];
        }

        $header = array_map([self::class, 'normalizeHeader'], array_shift($rows));
        $index = array_flip($header);

        $dateCol = self::findColumn($index, 'date');
        $descCol = self::findColumn($index, 'description');
        $merchantCol = self::findColumn($index, 'merchant');
        $amountCol = self::findColumn($index, 'amount');
        $debitCol = self::findColumn($index, 'debit');
        $creditCol = self::findColumn($index, 'credit');
        $accountCol = self::findColumn($index, 'account');
        $categoryCol = self::findColumn($index, 'category');
        $chkrefCol = self::findColumn($index, 'chkref');
        $balanceCol = self::findColumn($index, 'balance');

        if ($dateCol === null || $descCol === null || ($amountCol === null && $debitCol === null && $creditCol === null)) {
            throw new RuntimeException('File must have Date, Description, and either an Amount column or Debit/Credit columns.');
        }

        $toNumber = static fn($value) => (float) str_replace(['$', ',', ' '], '', (string) ($value ?? ''));

        $parsed = [];
        foreach ($rows as $row) {
            if (empty(array_filter($row, static fn($v) => $v !== null && $v !== ''))) {
                continue; // skip blank rows
            }

            $debit = $debitCol !== null && $row[$debitCol] !== '' ? $toNumber($row[$debitCol]) : null;
            $credit = $creditCol !== null && $row[$creditCol] !== '' ? $toNumber($row[$creditCol]) : null;

            if ($amountCol !== null) {
                $amount = $toNumber($row[$amountCol] ?? '0');
            } else {
                // Debit/credit style export: spending is positive, refunds/deposits negative.
                $amount = ($debit ?? 0.0) - ($credit ?? 0.0);
            }

            $parsed[] = [
                'date' => trim((string) ($row[$dateCol] ?? '')),
                'description' => trim((string) ($row[$descCol] ?? '')),
                'merchant' => $merchantCol !== null ? trim((string) ($row[$merchantCol] ?? '')) : null,
                'amount' => $amount,
                'account' => $accountCol !== null ? trim((string) ($row[$accountCol] ?? '')) : null,
                'category' => $categoryCol !== null ? trim((string) ($row[$categoryCol] ?? '')) : null,
                'chkref' => $chkrefCol !== null ? trim((string) ($row[$chkrefCol] ?? '')) : null,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balanceCol !== null && $row[$balanceCol] !== '' ? $toNumber($row[$balanceCol]) : null,
            ];
        }

        return $parsed;
    }

    private static function normalizeHeader(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($header)));
    }

    private static function findColumn(array $index, string $field): ?int
    {
        foreach (self::HEADER_ALIASES[$field] as $name) {
            $normalizedName = self::normalizeHeader($name);
            if (isset($index[$normalizedName])) {
                return $index[$normalizedName];
            }
        }
        return null;
    }

    private static function parseCsv(string $filePath): array
    {
        $rows = [];
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Could not read uploaded file.');
        }
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private static function parseSpreadsheet(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        return $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
    }
}
