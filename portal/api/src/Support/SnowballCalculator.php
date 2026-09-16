<?php
declare(strict_types=1);

namespace MainzWorld\Support;

// Pure debt-snowball projection. Ported from the original
// Python/Debt Snowball Forecaster/MainsSnowball.py prototype:
//   - each month, the smallest-balance debt receives its minimum payment plus the
//     rolling snowball; every other debt receives only its minimum;
//   - when a debt is cleared, its minimum payment is rolled into the snowball;
//   - interest-bearing debts accrue one month of interest before payment.
// No I/O or database access lives here so the math stays unit-testable.
final class SnowballCalculator
{
    // Safety cap so a debt whose interest outpaces its payments cannot loop forever.
    private const MAX_MONTHS = 1200; // 100 years
    private const CENTS = 0.005;

    /**
     * @param array<int, array{name?: string, balance: float, min_payment: float, apr: float}> $debts
     * @param float $extraPayment starting extra applied on top of the smallest debt's minimum
     * @param string $startMonth  YYYY-MM the projection begins in
     * @return array<string, mixed>
     */
    public static function project(array $debts, float $extraPayment, string $startMonth): array
    {
        // Local working copy so callers keep their original balances.
        $working = [];
        foreach ($debts as $debt) {
            $working[] = [
                'name' => (string) ($debt['name'] ?? 'Debt'),
                'balance' => round((float) $debt['balance'], 2),
                'min_payment' => max(0.0, (float) $debt['min_payment']),
                'monthly_rate' => max(0.0, (float) $debt['apr']) / 1200.0, // annual APR percent -> monthly decimal rate (apr / 100 / 12)
                'payoff_month' => null,
            ];
        }

        $snowball = max(0.0, $extraPayment);
        $totalInterest = 0.0;
        $month = 0;
        $breakdown = [];
        $payable = true;

        while (self::totalBalance($working) > self::CENTS && $month < self::MAX_MONTHS) {
            $month++;
            $balanceBefore = self::totalBalance($working);

            // Prioritise the smallest live balance; paid debts (0) sink to the bottom.
            usort($working, static function ($a, $b) {
                return $a['balance'] <=> $b['balance'];
            });

            $snowballAtStart = $snowball;
            $snowballUsed = false;
            $payments = [];

            foreach ($working as $index => $debt) {
                if ($debt['balance'] <= self::CENTS) {
                    continue;
                }

                if ($debt['monthly_rate'] > 0) {
                    $interest = $debt['balance'] * $debt['monthly_rate'];
                    $debt['balance'] += $interest;
                    $totalInterest += $interest;
                }

                if (!$snowballUsed) {
                    $payment = min($debt['balance'], $debt['min_payment'] + $snowball);
                    $snowballUsed = true;
                } else {
                    $payment = min($debt['balance'], $debt['min_payment']);
                }

                $debt['balance'] -= $payment;

                if ($debt['balance'] <= self::CENTS) {
                    $debt['balance'] = 0.0;
                    $snowball += $debt['min_payment'];
                    if ($debt['payoff_month'] === null) {
                        $debt['payoff_month'] = $month;
                    }
                }

                $working[$index] = $debt;
                $payments[] = [
                    'debt' => $debt['name'],
                    'payment' => round($payment, 2),
                    'remaining_balance' => round($debt['balance'], 2),
                ];
            }

            $breakdown[] = [
                'month' => $month,
                'calendar_month' => self::addMonths($startMonth, $month - 1),
                'snowball_amount' => round($snowballAtStart, 2),
                'payments' => $payments,
            ];

            // No progress this month means the minimums cannot cover interest: unpayable.
            if (self::totalBalance($working) >= $balanceBefore - self::CENTS
                && self::totalBalance($working) > self::CENTS) {
                $payable = false;
                break;
            }
        }

        if ($month >= self::MAX_MONTHS && self::totalBalance($working) > self::CENTS) {
            $payable = false;
        }

        $payoffOrder = [];
        foreach ($working as $debt) {
            $payoffOrder[] = [
                'name' => $debt['name'],
                'payoff_month' => $debt['payoff_month'],
                'calendar_month' => $debt['payoff_month'] === null
                    ? null
                    : self::addMonths($startMonth, $debt['payoff_month'] - 1),
            ];
        }
        usort($payoffOrder, static function ($a, $b) {
            return ($a['payoff_month'] ?? PHP_INT_MAX) <=> ($b['payoff_month'] ?? PHP_INT_MAX);
        });

        return [
            'payable' => $payable,
            'extra_payment' => round(max(0.0, $extraPayment), 2),
            'total_months' => $payable ? $month : null,
            'debt_free_month' => $payable ? self::addMonths($startMonth, $month - 1) : null,
            'total_interest_paid' => round($totalInterest, 2),
            'payoff_order' => $payoffOrder,
            'monthly_breakdown' => $breakdown,
        ];
    }

    /** @param array<int, array{balance: float}> $debts */
    private static function totalBalance(array $debts): float
    {
        $total = 0.0;
        foreach ($debts as $debt) {
            $total += $debt['balance'];
        }
        return $total;
    }

    // Returns a YYYY-MM string offset by $count whole months from $startMonth.
    private static function addMonths(string $startMonth, int $count): string
    {
        [$year, $month] = array_map('intval', explode('-', $startMonth));
        $zeroBased = ($month - 1) + $count;
        $year += intdiv($zeroBased, 12);
        $monthOut = ($zeroBased % 12) + 1;
        return sprintf('%04d-%02d', $year, $monthOut);
    }
}
