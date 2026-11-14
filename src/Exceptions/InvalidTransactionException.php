<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

class InvalidTransactionException extends WalletException
{
    protected $message = 'Invalid transaction.';
}
