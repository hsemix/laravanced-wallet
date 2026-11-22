<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Facades;

use Hsemix\AdvancedWallet\WalletManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Hsemix\AdvancedWallet\Services\AccountService accounts()
 * @method static \Hsemix\AdvancedWallet\Services\TransferTypeService transferTypes()
 * @method static \Hsemix\AdvancedWallet\Services\TransferFeeService fees()
 * @method static \Hsemix\AdvancedWallet\Services\PaymentService payments()
 * @method static \Hsemix\AdvancedWallet\Services\ScheduledPaymentService scheduled()
 * @method static \Hsemix\AdvancedWallet\Services\HistoryService history()
 * @method static \Hsemix\AdvancedWallet\Models\Transaction pay(array $payment)
 * @method static mixed onConnection(string $connection, \Closure $callback)
 * @method static array preview(array $payment)
 * @method static \Hsemix\AdvancedWallet\Support\BalanceSummary balance(\Hsemix\AdvancedWallet\Models\Account $account)
 *
 * @see WalletManager
 */
class Wallet extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WalletManager::class;
    }
}
