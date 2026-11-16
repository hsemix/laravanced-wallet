<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Console;

use Hsemix\AdvancedWallet\Services\AccountFeeService;
use Hsemix\AdvancedWallet\Services\PaymentRequestService;
use Hsemix\AdvancedWallet\Services\ScheduledPaymentService;
use Illuminate\Console\Command;

class ProcessScheduledPaymentsCommand extends Command
{
    protected $signature = 'wallet:process-scheduled {--date= : Process installments due up to this date (default: today)}';

    protected $description = 'Process due installments, recurring account fees and expiring payment requests';

    public function handle(ScheduledPaymentService $scheduled, PaymentRequestService $requests, AccountFeeService $accountFees): int
    {
        $result = $scheduled->processDue($this->option('date'));

        $this->info("Processed {$result['processed']} installment(s), {$result['failed']} failed, {$result['deferred']} deferred.");

        $fees = $accountFees->processDue($this->option('date'));
        $this->info("Account fees: {$fees['charged']} charged, {$fees['skipped']} skipped, {$fees['waived']} waived, {$fees['pending']} pending.");

        $expired = $requests->expireDue();
        $this->info("Expired {$expired} payment request(s).");

        return self::SUCCESS;
    }
}
