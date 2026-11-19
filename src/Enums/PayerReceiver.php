<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Enums;

enum PayerReceiver: string
{
    case Source = 'source';
    case Destination = 'destination';
    case System = 'system';
    /** The account of the required type owned by the same owner as the transfer's paying account (e.g. an agent's commission account). */
    case SourceOwner = 'source_owner';
    /** The account of the required type owned by the same owner as the transfer's receiving account. */
    case DestinationOwner = 'destination_owner';
    /** A specific account, given by payer_account_id / receiver_account_id. */
    case FixedAccount = 'fixed_account';
}
