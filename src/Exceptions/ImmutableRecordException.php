<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Exceptions;

/** Ledger entries are append-only; correct them with a chargeback. */
class ImmutableRecordException extends WalletException
{
    protected $message = 'Transfers are immutable. Use a chargeback to correct a payment.';
}
