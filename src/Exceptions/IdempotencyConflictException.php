<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

/** The same idempotency key was reused for a different payment. */
class IdempotencyConflictException extends WalletException
{
    protected $message = 'This idempotency key was already used for a different payment.';
}
