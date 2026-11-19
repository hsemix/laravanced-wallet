<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Services;

use Hsemix\AdvancedWallet\Enums\PayerReceiver;
use Hsemix\AdvancedWallet\Exceptions\InvalidAccountException;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Exceptions\InvalidTransferTypeException;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\AccountType;
use Hsemix\AdvancedWallet\Models\TransferFee;
use Hsemix\AdvancedWallet\Models\TransferType;
use Hsemix\AdvancedWallet\Support\Money;
use Hsemix\AdvancedWallet\Support\PlannedTransfer;

/**
 * Builds the tree of transfers (payment + fees + fees-of-fees) for a payment
 * without touching the database.
 */
class TransferPlanner
{
    private const MAX_DEPTH = 5;

    public function __construct(private readonly AccountService $accounts) {}

    public function plan(TransferType $type, Account $from, Account $to, string $amount, int $depth = 0): PlannedTransfer
    {
        $amount = Money::round($amount);

        if (! Money::isPositive($amount)) {
            throw new InvalidParameterException('Amount must be greater than zero.');
        }

        $root = new PlannedTransfer($type, $from, $to, $amount);

        $this->applyFees($root, $depth);

        return $root;
    }

    private function applyFees(PlannedTransfer $parent, int $depth): void
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new InvalidTransferTypeException('Transfer fees are nested too deeply (possible cycle).');
        }

        $base = $parent->amount; // fees are computed on the gross amount
        $fees = $parent->type->transferFees()
            ->where('enabled', true)
            ->with(['generatedTransferType.fromAccountType', 'generatedTransferType.toAccountType'])
            ->orderBy('id')
            ->get();

        foreach ($fees as $fee) {
            $feeAmount = $fee->calculate($base);

            if (! Money::isPositive($feeAmount)) {
                continue;
            }

            $generated = $fee->generatedTransferType;
            if (! $generated || ! $generated->isGenerated()) {
                throw new InvalidTransferTypeException("Fee {$fee->internal_name} has an invalid generated transfer type.");
            }

            $payer = $this->resolve($fee->payer, $fee, $parent, $generated->fromAccountType, true);
            $receiver = $this->resolve($fee->receiver, $fee, $parent, $generated->toAccountType, false);

            $child = new PlannedTransfer($generated, $payer, $receiver, $feeAmount, $fee);
            $this->applyFees($child, $depth + 1);

            if ($fee->deduct_amount) {
                $parent->amount = Money::sub($parent->amount, $feeAmount);
            }

            $parent->children[] = $child;
        }

        if (! Money::isPositive($parent->amount)) {
            throw new InvalidParameterException('Fees deducted from the payment exceed the payment amount.');
        }
    }

    /**
     * The account of type $expected that belongs to the same owner as $account. This is how a fee reaches
     * "the same person's other account", e.g. an agent's commission account when the agent's float account pays.
     */
    private function siblingAccount(Account $account, AccountType $expected, TransferFee $fee): Account
    {
        $owner = $account->owner;

        if ($owner === null) {
            throw new InvalidAccountException("Fee {$fee->internal_name}: account {$account->id} has no owner, so it has no {$expected->internal_name} account.");
        }

        $sibling = $this->accounts->findFor($owner, $expected);

        if ($sibling === null) {
            throw new InvalidAccountException("Fee {$fee->internal_name}: the owner of account {$account->id} has no {$expected->internal_name} account.");
        }

        if (! $sibling->active) {
            throw new InvalidAccountException("Fee {$fee->internal_name}: account {$sibling->id} ({$expected->internal_name}) is inactive.");
        }

        return $sibling;
    }

    private function resolve(PayerReceiver $role, TransferFee $fee, PlannedTransfer $parent, AccountType $expected, bool $isPayer): Account
    {
        $account = match ($role) {
            PayerReceiver::Source => $parent->from,
            PayerReceiver::Destination => $parent->to,
            PayerReceiver::System => $this->accounts->systemAccount($expected),
            PayerReceiver::SourceOwner => $this->siblingAccount($parent->from, $expected, $fee),
            PayerReceiver::DestinationOwner => $this->siblingAccount($parent->to, $expected, $fee),
            PayerReceiver::FixedAccount => Account::with('accountType')->find($isPayer ? $fee->payer_account_id : $fee->receiver_account_id),
        };

        if (! $account) {
            throw new InvalidAccountException("Fee {$fee->internal_name} points to a missing account.");
        }

        $account->loadMissing('accountType');

        if ($account->account_type_id !== $expected->id) {
            throw new InvalidAccountException(sprintf(
                'Fee %s: the %s account must be of type %s (got %s).',
                $fee->internal_name,
                $isPayer ? 'payer' : 'receiver',
                $expected->internal_name,
                $account->accountType->internal_name,
            ));
        }

        return $account;
    }
}
