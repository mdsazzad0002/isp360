<?php

namespace App\Traits;

use App\Models\AccountHead;
use App\Models\Bank;

/**
 * Shared by any controller that pays cash/bank out of the business (Payment)
 * — blocks the payment from draining the specific cash/bank account below
 * zero.
 */
trait ChecksAccountBalance
{
    protected function assertSufficientInvestBalance(float $requiredAmount, float $alreadyAccountedAmount = 0)
    {
        return null;
    }

    protected function assertSufficientAccountBalance(string $paymentMethod, $bankId, float $requiredAmount, float $alreadyAccountedAmount = 0)
    {
        $additional = $requiredAmount - $alreadyAccountedAmount;
        if ($additional <= 0) {
            return null;
        }

        if ($paymentMethod === 'bank') {
            if (empty($bankId)) {
                return null;
            }
            $bank = Bank::getBankBalance(['bankId' => $bankId]);
            $balance = (float) ($bank[0]->currentbalance ?? 0);
            if ($balance < $additional) {
                return 'Insufficient balance in the selected bank account. Available: ' . number_format($balance, 2) . ', required: ' . number_format($additional, 2);
            }
            return null;
        }

        $balance = (float) AccountHead::getCashBalance([])->cashbalance;
        if ($balance < $additional) {
            return 'Insufficient cash-in-hand balance. Available: ' . number_format($balance, 2) . ', required: ' . number_format($additional, 2);
        }
        return null;
    }
}
