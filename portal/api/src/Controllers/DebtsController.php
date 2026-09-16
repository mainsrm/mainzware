<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\Debts;
use MainzWorld\Support\Auth;
use MainzWorld\Support\SnowballCalculator;

final class DebtsController
{
    private const MAX_NAME_LENGTH = 255;
    private const MAX_MONEY = 100_000_000.0; // sanity ceiling for a single balance/payment
    private const MAX_APR = 1000.0;          // percent
    private const MAX_EXTRA = 1_000_000.0;
    private const MAX_DEBTS_PER_USER = 100;  // bounds the on-demand snowball projection size

    public function index(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;
        echo json_encode(Debts::allForUser((int) $user['id']), JSON_THROW_ON_ERROR);
    }

    public function create(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;

        if (count(Debts::allForUser((int) $user['id'])) >= self::MAX_DEBTS_PER_USER) {
            $this->fail(409, 'Debt limit reached (' . self::MAX_DEBTS_PER_USER . ' per user).');
            return;
        }

        $fields = $this->validatedFields();
        if ($fields === null) return;

        echo json_encode(
            Debts::create(
                (int) $user['id'],
                $fields['name'],
                $fields['balance'],
                $fields['min_payment'],
                $fields['apr']
            ),
            JSON_THROW_ON_ERROR
        );
    }

    public function update(int $id): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;

        $fields = $this->validatedFields();
        if ($fields === null) return;

        $updated = Debts::update(
            $id,
            (int) $user['id'],
            $fields['name'],
            $fields['balance'],
            $fields['min_payment'],
            $fields['apr']
        );
        if ($updated === null) {
            $this->fail(404, 'Debt not found.');
            return;
        }
        echo json_encode($updated, JSON_THROW_ON_ERROR);
    }

    public function delete(int $id): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;

        if (!Debts::delete($id, (int) $user['id'])) {
            $this->fail(404, 'Debt not found.');
            return;
        }
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }

    public function snowball(): void
    {
        header('Content-Type: application/json');
        $user = Auth::requireLogin();
        if ($user === null) return;

        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($body)) {
            $this->fail(400, 'Invalid request body.');
            return;
        }

        $extra = $body['extra_payment'] ?? 0;
        if (!is_numeric($extra) || (float) $extra < 0 || (float) $extra > self::MAX_EXTRA) {
            $this->fail(400, 'extra_payment must be a number between 0 and ' . self::MAX_EXTRA . '.');
            return;
        }

        $startMonth = (string) ($body['start_month'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $startMonth)) {
            $this->fail(400, 'start_month must use YYYY-MM format.');
            return;
        }

        $debts = Debts::allForUser((int) $user['id']);
        if ($debts === []) {
            $this->fail(400, 'Add at least one debt before running a projection.');
            return;
        }

        echo json_encode(
            SnowballCalculator::project($debts, (float) $extra, $startMonth),
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Reads and validates the shared debt fields from the JSON body.
     * Writes a 400 response and returns null on any validation failure.
     *
     * @return array{name: string, balance: float, min_payment: float, apr: float}|null
     */
    private function validatedFields(): ?array
    {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($body)) {
            $this->fail(400, 'Invalid request body.');
            return null;
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $this->fail(400, 'Debt name is required and must be at most ' . self::MAX_NAME_LENGTH . ' characters.');
            return null;
        }

        $balance = $this->money($body['balance'] ?? null);
        if ($balance === null) {
            $this->fail(400, 'balance must be a number between 0 and ' . self::MAX_MONEY . '.');
            return null;
        }

        $minPayment = $this->money($body['min_payment'] ?? null);
        if ($minPayment === null) {
            $this->fail(400, 'min_payment must be a number between 0 and ' . self::MAX_MONEY . '.');
            return null;
        }

        $apr = $body['apr'] ?? 0;
        if (!is_numeric($apr) || (float) $apr < 0 || (float) $apr > self::MAX_APR) {
            $this->fail(400, 'apr must be a number between 0 and ' . self::MAX_APR . '.');
            return null;
        }

        return [
            'name' => $name,
            'balance' => round((float) $balance, 2),
            'min_payment' => round((float) $minPayment, 2),
            'apr' => round((float) $apr, 3),
        ];
    }

    private function money(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if ($number < 0 || $number > self::MAX_MONEY) {
            return null;
        }
        return $number;
    }

    private function fail(int $status, string $message): void
    {
        http_response_code($status);
        echo json_encode(['error' => $message], JSON_THROW_ON_ERROR);
    }
}
