<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Enums;

enum ScheduleUnit: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function periodsPerYear(): int
    {
        return match ($this) {
            self::Day => 365,
            self::Week => 52,
            self::Month => 12,
            self::Year => 1,
        };
    }
}
