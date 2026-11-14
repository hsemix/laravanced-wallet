<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

class InvalidParameterException extends WalletException
{
    protected $message = 'Invalid parameter.';
}
