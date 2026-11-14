<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Enums;

enum AccountKind: string
{
    case User = 'user';
    case System = 'system';
}
