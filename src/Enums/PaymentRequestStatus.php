<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Enums;

enum PaymentRequestStatus: string
{
    case Requested = 'requested';   // waiting for the payer
    case Accepted = 'accepted';     // payer (or someone on their behalf) accepted; payment performed
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';   // withdrawn by the requester
    case Expired = 'expired';
}
