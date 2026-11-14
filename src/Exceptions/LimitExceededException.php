<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

class LimitExceededException extends WalletException
{
    protected $message = 'Transfer limit exceeded.';
}
