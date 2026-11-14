<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet;

use Hsemix\AdvancedWallet\Console\ProcessScheduledPaymentsCommand;
use Hsemix\AdvancedWallet\Console\ReconcileBalancesCommand;
use Hsemix\AdvancedWallet\Services\AccountService;
use Hsemix\AdvancedWallet\Services\BalancePostingService;
use Hsemix\AdvancedWallet\Services\HistoryService;
use Hsemix\AdvancedWallet\Services\LimitGuard;
use Hsemix\AdvancedWallet\Services\PaymentRequestService;
use Hsemix\AdvancedWallet\Services\PaymentService;
use Hsemix\AdvancedWallet\Services\ScheduledPaymentService;
use Hsemix\AdvancedWallet\Services\TransferFeeService;
use Hsemix\AdvancedWallet\Services\TransferPlanner;
use Hsemix\AdvancedWallet\Services\TransferTypeService;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class WalletServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('wallet')
            ->hasConfigFile()
            ->hasMigrations([
                '2026_01_01_000000_create_wallet_tables',
                '2026_01_02_000000_add_idempotency_key_to_wallet_transactions',
                '2026_01_03_000000_add_decision_audit_to_wallet_transactions',
                '2026_01_04_000000_create_wallet_payment_requests',
                '2026_01_05_000000_add_reservation_to_wallet_payment_requests',
                '2026_01_06_000000_add_chargeback_rules_to_wallet_transfer_types',
            ])
            ->runsMigrations();
    }

    public function packageRegistered(): void
    {
        foreach (
            [
                AccountService::class,
                BalancePostingService::class,
                HistoryService::class,
                LimitGuard::class,
                PaymentRequestService::class,
                PaymentService::class,
                ScheduledPaymentService::class,
                TransferFeeService::class,
                TransferPlanner::class,
                TransferTypeService::class,
                WalletManager::class,
            ] as $class
        ) {
            $this->app->singleton($class);
        }
    }

    public function packageBooted(): void
    {
        if ($this->app->runningInConsole() && config('wallet.commands', true)) {
            $this->commands([ProcessScheduledPaymentsCommand::class, ReconcileBalancesCommand::class]);
        }
    }
}
