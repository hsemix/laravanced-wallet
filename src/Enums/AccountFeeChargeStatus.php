<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Enums;

enum AccountFeeChargeStatus: string
{
    case Pending = 'pending';
    case Charged = 'charged';
    case Skipped = 'skipped';
    /** Nothing was owed: the account is exempt, or the computed fee was zero. */
    case Waived = 'waived';
}
