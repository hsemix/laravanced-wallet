<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Enums;

enum PayerReceiver: string
{
    case Source = 'source';
    case Destination = 'destination';
    case System = 'system';
    /** A specific account, given by payer_account_id / receiver_account_id. */
    case FixedAccount = 'fixed_account';
}
