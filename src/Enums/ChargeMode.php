<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Enums;

enum ChargeMode: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
