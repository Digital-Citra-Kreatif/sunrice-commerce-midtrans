<?php

declare(strict_types=1);

namespace Workbench\App\Commerce;

use Sunrice\Commerce\Pricing\CalculationState;
use Sunrice\Commerce\Pricing\Contracts\CalculationStage;

/** Tests (Sunrice Commerce 0.3+): asks a deposit of 30 % of the total, the balance due in 7 days. */
class TestDeposit implements CalculationStage
{
    public function run(CalculationState $state): void
    {
        $state->deposit = ['amount' => intdiv($state->totals['total'] * 30, 100), 'balance_due_at' => now()->addDays(7)->startOfDay()->toIso8601String(), 'label' => null];
    }
}
