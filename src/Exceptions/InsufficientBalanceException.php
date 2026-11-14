<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

class InsufficientBalanceException extends WalletException
{
    protected $message = 'Not enough available balance.';
}
