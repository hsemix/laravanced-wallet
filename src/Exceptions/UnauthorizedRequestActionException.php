<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

class UnauthorizedRequestActionException extends UnauthorizedActionException
{
    protected $message = 'This actor may not act on this payment request.';
}
