<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Support;

use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Exceptions\MissingParameterException;
use Illuminate\Database\Eloquent\Model;

/**
 * Validated input for a payment.
 *
 * from / to : Account | owner Model | "system" | account id
 * type      : TransferType | id | "account_type.transfer_type"
 */
final class PaymentRequest
{
    public function __construct(
        public readonly mixed $from,
        public readonly mixed $to,
        public readonly mixed $type,
        public readonly string $amount,
        public readonly ?string $description = null,
        public readonly ?Model $by = null,
        public readonly array $meta = [],
        public readonly ?string $idempotencyKey = null,
        /** Internal: skip the sufficient-funds check (account fees that may overdraw). Not accepted from array input. */
        public readonly bool $allowOverdraft = false,
    ) {}

    public static function from(array|self $input): self
    {
        if ($input instanceof self) {
            return $input;
        }

        foreach (['from', 'to', 'type', 'amount'] as $field) {
            if (! isset($input[$field]) || $input[$field] === '') {
                throw new MissingParameterException("Missing parameter ({$field}).");
            }
        }

        $key = isset($input['idempotency_key']) && $input['idempotency_key'] !== '' ? (string) $input['idempotency_key'] : null;
        if ($key !== null && strlen($key) > 100) {
            throw new InvalidParameterException('idempotency_key cannot be longer than 100 characters.');
        }

        return new self(
            $input['from'],
            $input['to'],
            $input['type'],
            Money::normalize($input['amount']),
            isset($input['description']) ? (string) $input['description'] : null,
            $input['by'] ?? null,
            $input['meta'] ?? [],
            $key,
        );
    }
}
