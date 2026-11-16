<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Enums;

enum AccountFeeTrigger: string
{
    /** Charged once, when the account is opened. */
    case Opening = 'opening';
    /** Charged every N days / weeks / months / years while the account exists. */
    case Recurring = 'recurring';
}
