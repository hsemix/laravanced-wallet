<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Events;

use Hsemix\AdvancedWallet\Models\Installment;
use Hsemix\AdvancedWallet\Models\Transaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after the surrounding database transaction commits. */
final class InstallmentFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Installment $installment) {}
}
