<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Enums;

/** What to do when an account cannot cover a fee. */
enum InsufficientFundsPolicy: string
{
    /** Forgive this charge; it is recorded as skipped. */
    case Skip = 'skip';
    /** Leave the charge pending and try again on every run until it can be paid. */
    case Retry = 'retry';
    /** Charge anyway: the account may go below zero, past its credit limit. */
    case AllowNegative = 'allow_negative';
}
