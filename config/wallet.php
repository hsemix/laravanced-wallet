<?php

declare(strict_types=1);

return [

    /*
    | Prefix applied to every table the package creates.
    */
    'table_prefix' => 'wallet_',

    /*
    | Default ISO currency code stored on new account types.
    */
    'currency' => 'USD',

    /*
    | Database connection used by the package (null = application default).
    */
    'connection' => null,

    /*
    | Minimum digits used when generating account numbers (prefix + padded counter).
    */
    'account_number_length' => 6,

    /*
    | Most payment requests a single payer account may have open at once (null = unlimited).
    | Worth setting when a transfer type uses reserve_on_payment_request.
    */
    'max_open_requests_per_payer' => null,

    /*
    | Register the wallet:process-scheduled / wallet:reconcile console commands.
    */
    'commands' => true,
];
