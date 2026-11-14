<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

/** The actor is not permitted to do this (as decided by an authorizer callback). Map to HTTP 403. */
class UnauthorizedActionException extends WalletException
{
    protected $message = 'This actor is not permitted to do this.';
}
