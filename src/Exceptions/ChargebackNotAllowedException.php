<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

/** The transfer type's chargeback rules forbid this chargeback (disabled, window passed, or force not allowed). */
class ChargebackNotAllowedException extends WalletException
{
    protected $message = 'This transaction cannot be charged back.';
}
