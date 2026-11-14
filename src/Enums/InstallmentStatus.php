<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Enums;

enum InstallmentStatus: string
{
    case Scheduled = 'scheduled';
    case Processed = 'processed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
