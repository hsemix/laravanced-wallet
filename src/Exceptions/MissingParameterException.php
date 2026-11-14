<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

class MissingParameterException extends WalletException
{
    protected $message = 'Missing parameter.';
}
