<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet;

use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Services\AccountFeeService;
use Hsemix\AdvancedWallet\Services\AccountService;
use Hsemix\AdvancedWallet\Services\HistoryService;
use Hsemix\AdvancedWallet\Services\PaymentRequestService;
use Hsemix\AdvancedWallet\Services\PaymentService;
use Hsemix\AdvancedWallet\Services\ScheduledPaymentService;
use Hsemix\AdvancedWallet\Services\TransferFeeService;
use Hsemix\AdvancedWallet\Services\TransferTypeService;
use Hsemix\AdvancedWallet\Support\BalanceSummary;

/**
 * Entry point behind the Wallet facade.
 */
class WalletManager
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly TransferTypeService $transferTypes,
        private readonly TransferFeeService $fees,
        private readonly AccountFeeService $accountFees,
        private readonly PaymentService $payments,
        private readonly PaymentRequestService $requests,
        private readonly ScheduledPaymentService $scheduled,
        private readonly HistoryService $history,
    ) {}

    public function accounts(): AccountService
    {
        return $this->accounts;
    }

    public function transferTypes(): TransferTypeService
    {
        return $this->transferTypes;
    }

    public function fees(): TransferFeeService
    {
        return $this->fees;
    }

    /** Fees charged to an account itself: on opening, or recurring. See docs/account-fees.md. */
    public function accountFees(): AccountFeeService
    {
        return $this->accountFees;
    }

    public function payments(): PaymentService
    {
        return $this->payments;
    }

    public function requests(): PaymentRequestService
    {
        return $this->requests;
    }

    /** Decide who may accept / reject / cancel payment requests. See docs/payment-requests.md. */
    public function authorizeRequestsUsing(?callable $callback): void
    {
        $this->requests->authorizeUsing($callback);
    }

    /** Decide who may charge payments back. See docs/chargebacks.md. */
    public function authorizeChargebacksUsing(?callable $callback): void
    {
        $this->payments->authorizeChargebacksUsing($callback);
    }

    public function scheduled(): ScheduledPaymentService
    {
        return $this->scheduled;
    }

    public function history(): HistoryService
    {
        return $this->history;
    }

    /* Shortcuts for the most common calls. */

    public function pay(array $payment): Transaction
    {
        return $this->payments->perform($payment);
    }

    public function preview(array $payment): array
    {
        return $this->payments->preview($payment);
    }

    public function balance(Account $account): BalanceSummary
    {
        return $this->accounts->balance($account);
    }
}
