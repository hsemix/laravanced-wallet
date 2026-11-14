<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Console;

use Hsemix\AdvancedWallet\Services\PaymentRequestService;
use Hsemix\AdvancedWallet\Services\ScheduledPaymentService;
use Illuminate\Console\Command;

class ProcessScheduledPaymentsCommand extends Command
{
    protected $signature = 'wallet:process-scheduled {--date= : Process installments due up to this date (default: today)}';

    protected $description = 'Process due installments of scheduled and recurring wallet payments';

    public function handle(ScheduledPaymentService $scheduled, PaymentRequestService $requests): int
    {
        $result = $scheduled->processDue($this->option('date'));

        $this->info("Processed {$result['processed']} installment(s), {$result['failed']} failed, {$result['deferred']} deferred.");

        $expired = $requests->expireDue();
        $this->info("Expired {$expired} payment request(s).");

        return self::SUCCESS;
    }
}
