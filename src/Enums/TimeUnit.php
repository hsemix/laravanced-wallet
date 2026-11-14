<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Enums;

enum TimeUnit: string
{
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
