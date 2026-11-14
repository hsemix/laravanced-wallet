<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Events;

use Hsemix\AdvancedWallet\Models\MoneyRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Dispatched after the surrounding database transaction commits. Request was rejected. */
final class PaymentRequestRejected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly MoneyRequest $request) {}
}
