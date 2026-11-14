<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

class UnauthorizedChargebackException extends UnauthorizedActionException
{
    protected $message = 'This actor may not charge back this transaction.';
}
