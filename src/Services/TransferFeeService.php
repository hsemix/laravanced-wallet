<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Services;

use Hsemix\AdvancedWallet\Enums\ChargeMode;
use Hsemix\AdvancedWallet\Enums\PayerReceiver;
use Hsemix\AdvancedWallet\Exceptions\DuplicateRecordException;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransferTypeException;
use Hsemix\AdvancedWallet\Exceptions\MissingParameterException;
use Hsemix\AdvancedWallet\Models\TransferFee;

class TransferFeeService
{
    public function __construct(private readonly TransferTypeService $transferTypes) {}

    /**
     * @param  array<string,mixed>  $data  name, internal_name, transfer_type (the type charged), generated_transfer_type,
     *                                     charge_mode, amount. Optional: min_amount, max_amount, payer, receiver, payer_account_id,
     *                                     receiver_account_id, deduct_amount, enabled, description.
     */
    public function create(array $data): TransferFee
    {
        foreach (['name', 'internal_name', 'transfer_type', 'generated_transfer_type', 'charge_mode', 'amount'] as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                throw new MissingParameterException("Missing parameter ({$field}).");
            }
        }

        $mode = $data['charge_mode'] instanceof ChargeMode ? $data['charge_mode'] : ChargeMode::tryFrom((string) $data['charge_mode']);
        if (! $mode) {
            throw new InvalidParameterException('charge_mode must be "fixed" or "percentage".');
        }

        if (! is_numeric($data['amount']) || $data['amount'] <= 0) {
            throw new InvalidParameterException('amount must be a positive number.');
        }

        if ($mode === ChargeMode::Percentage && $data['amount'] > 100) {
            throw new InvalidParameterException('A percentage fee cannot exceed 100.');
        }

        $min = $data['min_amount'] ?? null;
        $max = $data['max_amount'] ?? null;
        if (($min !== null && ! is_numeric($min)) || ($max !== null && ! is_numeric($max))) {
            throw new InvalidParameterException('min_amount / max_amount must be numeric.');
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidParameterException('min_amount cannot be greater than max_amount.');
        }

        $original = $this->transferTypes->resolve($data['transfer_type']);
        $generated = $this->transferTypes->resolve($data['generated_transfer_type']);

        if (! $generated->isGenerated()) {
            throw new InvalidTransferTypeException('generated_transfer_type must have kind "generated".');
        }

        if (TransferFee::where('internal_name', $data['internal_name'])->exists()) {
            throw new DuplicateRecordException("Transfer fee {$data['internal_name']} already exists.");
        }

        $deduct = (bool) ($data['deduct_amount'] ?? false);
        $payer = $this->role($data['payer'] ?? PayerReceiver::Source, 'payer');
        $receiver = $this->role($data['receiver'] ?? PayerReceiver::System, 'receiver');

        if ($deduct) {
            // The fee is carved out of the payment, so the source always funds it.
            $payer = PayerReceiver::Source;
        }

        if ($payer === PayerReceiver::FixedAccount && empty($data['payer_account_id'])) {
            throw new MissingParameterException('payer_account_id is required for a fixed_account payer.');
        }
        if ($receiver === PayerReceiver::FixedAccount && empty($data['receiver_account_id'])) {
            throw new MissingParameterException('receiver_account_id is required for a fixed_account receiver.');
        }

        return TransferFee::create([
            'name' => $data['name'],
            'internal_name' => $data['internal_name'],
            'description' => $data['description'] ?? null,
            'original_transfer_type_id' => $original->id,
            'generated_transfer_type_id' => $generated->id,
            'charge_mode' => $mode,
            'amount' => $data['amount'],
            'min_amount' => $min,
            'max_amount' => $max,
            'payer' => $payer,
            'receiver' => $receiver,
            'payer_account_id' => $data['payer_account_id'] ?? null,
            'receiver_account_id' => $data['receiver_account_id'] ?? null,
            'deduct_amount' => $deduct,
            'enabled' => (bool) ($data['enabled'] ?? true),
        ]);
    }

    private function role(PayerReceiver|string $value, string $field): PayerReceiver
    {
        return $value instanceof PayerReceiver
            ? $value
            : (PayerReceiver::tryFrom($value) ?? throw new InvalidParameterException("Invalid {$field}: {$value}."));
    }
}
