<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Services;

use Hsemix\AdvancedWallet\Enums\TimeUnit;
use Hsemix\AdvancedWallet\Enums\TransferKind;
use Hsemix\AdvancedWallet\Exceptions\DuplicateRecordException;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransferTypeException;
use Hsemix\AdvancedWallet\Exceptions\MissingParameterException;
use Hsemix\AdvancedWallet\Models\TransferType;

class TransferTypeService
{
    private const BOOLEANS = [
        'enabled', 'requires_description', 'requires_authorization', 'allows_scheduled_payments',
        'allows_recurring_payments', 'allows_payment_requests', 'reserve_on_payment_request', 'allows_chargebacks', 'allows_forced_chargebacks', 'reserve_total_amount_on_scheduled_payments', 'allows_interest',
        'interest_is_annual', 'notify_payment_received', 'notify_payment_sender',
    ];

    private const NUMBERS = [
        'min_amount', 'max_amount', 'max_amount_per_day', 'max_amount_per_week', 'max_amount_per_month',
        'max_transfers_per_day', 'max_transfers_per_week', 'max_transfers_per_month', 'max_installments',
        'interest_rate',
    ];

    public function __construct(private readonly AccountService $accounts) {}

    /**
     * @param  array<string,mixed>  $data  name, internal_name, from_account_type, to_account_type are required.
     *                                     kind defaults to "payment". Account types may be models, ids or internal names.
     */
    public function create(array $data): TransferType
    {
        foreach (['name', 'internal_name', 'from_account_type', 'to_account_type'] as $field) {
            if (empty($data[$field])) {
                throw new MissingParameterException("Missing parameter ({$field}).");
            }
        }

        $kind = $data['kind'] ?? TransferKind::Payment;
        $kind = $kind instanceof TransferKind ? $kind : TransferKind::tryFrom((string) $kind);
        if (! $kind) {
            throw new InvalidTransferTypeException('kind must be "payment" or "generated".');
        }

        $from = $this->accounts->resolveType($data['from_account_type']);
        $to = $this->accounts->resolveType($data['to_account_type']);

        if (TransferType::where('from_account_type_id', $from->id)->where('internal_name', $data['internal_name'])->exists()) {
            throw new DuplicateRecordException("Transfer type {$from->internal_name}.{$data['internal_name']} already exists.");
        }

        $attributes = [
            'name' => $data['name'],
            'internal_name' => $data['internal_name'],
            'description' => $data['description'] ?? null,
            'confirmation_message' => $data['confirmation_message'] ?? null,
            'kind' => $kind,
            'from_account_type_id' => $from->id,
            'to_account_type_id' => $to->id,
        ];

        foreach (self::BOOLEANS as $field) {
            if (array_key_exists($field, $data)) {
                if (! is_bool($data[$field])) {
                    throw new InvalidParameterException("{$field} must be a boolean.");
                }
                $attributes[$field] = $data[$field];
            }
        }

        foreach (self::NUMBERS as $field) {
            if (isset($data[$field])) {
                if (! is_numeric($data[$field]) || $data[$field] < 0) {
                    throw new InvalidParameterException("{$field} must be a non-negative number.");
                }
                $attributes[$field] = $data[$field];
            }
        }

        if (isset($attributes['min_amount'], $attributes['max_amount']) && $attributes['min_amount'] > $attributes['max_amount']) {
            throw new InvalidParameterException('min_amount cannot be greater than max_amount.');
        }

        $amount = $data['max_chargeback_time_amount'] ?? null;
        $field = $data['max_chargeback_time_field'] ?? null;
        if ($amount !== null || $field !== null) {
            $unit = $field instanceof TimeUnit ? $field : TimeUnit::tryFrom((string) $field);
            if (! is_numeric($amount) || (int) $amount < 1 || ! $unit) {
                throw new InvalidParameterException('max_chargeback_time_amount (>= 1) and max_chargeback_time_field (minute, hour, day, week, month or year) must be set together.');
            }
            $attributes['max_chargeback_time_amount'] = (int) $amount;
            $attributes['max_chargeback_time_field'] = $unit;
        }

        if (! empty($attributes['allows_interest']) && empty($attributes['allows_scheduled_payments'])) {
            throw new InvalidParameterException('Interest is only available on scheduled payments.');
        }

        return TransferType::create($attributes)->load(['fromAccountType', 'toAccountType']);
    }

    /**
     * Resolve "account_type.transfer_type", a bare unique internal name, an id, or a model.
     */
    public function resolve(TransferType|int|string $type): TransferType
    {
        if ($type instanceof TransferType) {
            return $type->loadMissing(['fromAccountType', 'toAccountType']);
        }

        $query = TransferType::with(['fromAccountType', 'toAccountType']);

        if (is_int($type)) {
            return $query->find($type) ?? throw new InvalidTransferTypeException("Unknown transfer type id {$type}.");
        }

        if (str_contains($type, '.')) {
            [$accountType, $name] = explode('.', $type, 2);
            $found = $query->where('internal_name', $name)
                ->whereHas('fromAccountType', fn ($q) => $q->where('internal_name', $accountType))
                ->first();

            return $found ?? throw new InvalidTransferTypeException("Unknown transfer type {$type}.");
        }

        $matches = $query->where('internal_name', $type)->get();

        return match ($matches->count()) {
            1 => $matches->first(),
            0 => throw new InvalidTransferTypeException("Unknown transfer type {$type}."),
            default => throw new InvalidTransferTypeException("Transfer type {$type} is ambiguous; use account_type.{$type}."),
        };
    }
}
