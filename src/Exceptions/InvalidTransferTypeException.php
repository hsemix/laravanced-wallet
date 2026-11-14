<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

class InvalidTransferTypeException extends WalletException
{
    protected $message = 'Invalid transfer type.';
}
