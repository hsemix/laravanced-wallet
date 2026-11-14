<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Support;

final class BalanceSummary
{
    public readonly string $available;

    public function __construct(
        public readonly string $balance,
        public readonly string $reserved,
    ) {
        $this->available = Money::sub($balance, $reserved);
    }

    public function toArray(): array
    {
        return [
            'balance' => Money::round($this->balance),
            'reserved' => Money::round($this->reserved),
            'available' => Money::round($this->available),
        ];
    }
}
