<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

class InvalidAccountException extends WalletException
{
    protected $message = 'Invalid account.';
}
