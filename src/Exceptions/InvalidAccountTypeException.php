<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

class InvalidAccountTypeException extends WalletException
{
    protected $message = 'Invalid account type.';
}
