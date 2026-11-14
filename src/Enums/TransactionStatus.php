<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Enums;

enum TransactionStatus: string
{
    case Pending = 'pending';       // waiting for authorization
    case Successful = 'successful';
    case Failed = 'failed';
    case Denied = 'denied';
    case Cancelled = 'cancelled';
    case Open = 'open';             // scheduled payment with installments left
    case Closed = 'closed';         // scheduled payment fully processed
}
