# Fees

A fee is a rule attached to a **transfer type**. When a payment of that type matches, the package adds an extra
**generated transfer** (the fee) to the same transaction.

```php
Wallet::fees()->create([
    'name' => 'Transfer fee',
    'internal_name' => 'transfer_fee',            // unique
    'transfer_type' => 'wallet.transfer',         // the payment type being charged
    'generated_transfer_type' => 'wallet.fee',    // must be kind = generated
    'charge_mode' => 'percentage',                // 'fixed' | 'percentage'
    'amount' => 1.5,                              // fixed value, or percent (max 100)
]);
```

## Options

| Option | Default | Meaning |
|---|---|---|
| `charge_mode` | required | `fixed`: charge `amount`. `percentage`: charge `amount`% of the payment. |
| `amount` | required | Positive. Percentages cannot exceed 100. |
| `min_amount` | none | Fee applies only when the payment is **≥** this. |
| `max_amount` | none | Fee applies only when the payment is **≤** this. Either bound may be omitted. |
| `payer` | `source` | Who pays the fee: `source`, `destination`, `system`, `fixed_account`. |
| `receiver` | `system` | Who receives it: same choices. |
| `payer_account_id` / `receiver_account_id` | none | Required with `fixed_account`. |
| `deduct_amount` | `false` | Take the fee **out of** the payment instead of on top. |
| `enabled` | `true` | Disabled fees are ignored. |
| `description` | none | |

A tiered schedule is just several fees with non-overlapping ranges:

```php
// 0.50 flat under 100; 1% from 100 up
Wallet::fees()->create([... 'internal_name' => 'small', 'charge_mode' => 'fixed',      'amount' => 0.5, 'max_amount' => 99.9999]);
Wallet::fees()->create([... 'internal_name' => 'large', 'charge_mode' => 'percentage', 'amount' => 1,   'min_amount' => 100]);
```

## Who pays and who receives

| Role | Resolves to |
|---|---|
| `source` | the paying account of the transfer the fee belongs to |
| `destination` | the receiving account of that transfer |
| `system` | the single system account of the generated type's from-account type (payer) or to-account type (receiver) |
| `fixed_account` | the account in `payer_account_id` / `receiver_account_id` |

**Account-type rule:** the resolved payer must be an account of the generated type's `from_account_type`, and the receiver
of its `to_account_type`. If not, the payment is refused with `InvalidAccountException`. Example: generated type
`wallet.fee` is `wallet → fees`, so the payer must be a wallet account (source, destination or a fixed wallet) and the
receiver the `fees` system account (`system`).

## On top vs deducted

Payment of 50, 10% fee:

| | Sender pays | Recipient gets | Fee receiver gets |
|---|---|---|---|
| default (on top) | 55 | 50 | 5 |
| `deduct_amount` | 50 | 45 | 5 |
| `payer = destination` | 50 | 50 − 5 = 45 (recipient pays fee from own balance) | 5 |

With `deduct_amount` the payer is forced to `source`, since the fee is carved out of the payment. A deducted fee that
would consume the whole payment throws `InvalidParameterException`.

## Fees on fees

A generated transfer type can have its own fees (attach a fee whose `transfer_type` is the generated type). They are
applied recursively, up to depth 5 (deeper throws `InvalidTransferTypeException`, which also catches cycles).

## Rounding and evaluation

- Fees are computed on the **gross** payment amount and rounded half-up to 4 decimals.
- A fee that rounds to 0 is skipped.
- Multiple fees on one type are all applied, in id order. Several `deduct_amount` fees are each computed on the gross amount.
- Fee transfers are children of the transfer that triggered them and are reversed together by a [chargeback](chargebacks.md).
- Fees count toward the payer's total debit and so must be affordable; `preview()` shows them.
