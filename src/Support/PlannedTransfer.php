<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Support;

use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\TransferFee;
use Hsemix\AdvancedWallet\Models\TransferType;

/**
 * An in-memory transfer (plus the fee transfers it triggers) that has not been
 * written yet. Preview and perform share the same plan, so what a user is
 * shown is exactly what gets booked.
 */
final class PlannedTransfer
{
    /** @var list<PlannedTransfer> */
    public array $children = [];

    public function __construct(
        public readonly TransferType $type,
        public readonly Account $from,
        public readonly Account $to,
        public string $amount,
        public readonly ?TransferFee $fee = null,
    ) {}

    /** @return list<PlannedTransfer> this transfer followed by all descendants */
    public function flatten(): array
    {
        $all = [$this];

        foreach ($this->children as $child) {
            array_push($all, ...$child->flatten());
        }

        return $all;
    }

    /** Net amount leaving $account across the whole plan (negative = net receiver). */
    public function netDebitFor(Account $account): string
    {
        $net = '0';

        foreach ($this->flatten() as $transfer) {
            if ($transfer->from->is($account)) {
                $net = Money::add($net, $transfer->amount);
            }
            if ($transfer->to->is($account)) {
                $net = Money::sub($net, $transfer->amount);
            }
        }

        return $net;
    }

    /** Sum of fees the source pays on top of the payment amount. */
    public function feesPaidBy(Account $account): string
    {
        $sum = '0';

        foreach ($this->children as $child) {
            if ($child->from->is($account)) {
                $sum = Money::add($sum, $child->amount);
            }
        }

        return $sum;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->internal_name,
            'from_account_id' => $this->from->id,
            'to_account_id' => $this->to->id,
            'amount' => Money::round($this->amount),
            'fee' => $this->fee?->internal_name,
            'children' => array_map(fn (self $c) => $c->toArray(), $this->children),
        ];
    }
}
