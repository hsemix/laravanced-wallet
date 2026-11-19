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
| `payer` | `source` | Who pays the fee: `source`, `destination`, `system`, `fixed_account`, `source_owner`, `destination_owner`. |
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
| `source_owner` | the account of the generated type's account type **owned by the same owner as the paying account** |
| `destination_owner` | the same, for the owner of the receiving account |

**Account-type rule:** the resolved payer must be an account of the generated type's `from_account_type`, and the receiver
of its `to_account_type`. If not, the payment is refused with `InvalidAccountException`. Example: generated type
`wallet.fee` is `wallet → fees`, so the payer must be a wallet account (source, destination or a fixed wallet) and the
receiver the `fees` system account (`system`).

## Paying someone's other account (agent commission)

`source` and `destination` are the transfer's own accounts, so they can only reach an account of the *same type*. To reach the
same person's account of a *different* type, use `source_owner` or `destination_owner`.

The classic case is agent banking: the agent moves cash through a **float** account and earns into a separate **commission**
account. The bank, not the customer, pays the commission, and each agent is paid into their own commission account:

```php
Wallet::accounts()->createAccountType(['name' => 'Agent float',      'internal_name' => 'agent_float',      'kind' => 'user']);
Wallet::accounts()->createAccountType(['name' => 'Agent commission', 'internal_name' => 'agent_commission', 'kind' => 'user']);
Wallet::accounts()->createAccountType(['name' => 'Bank commissions', 'internal_name' => 'bank_commissions', 'kind' => 'system',
    'system_limit_type' => 'unlimited']);                       // or 'limited' and keep it funded

Wallet::transferTypes()->create(['name' => 'Cash in', 'internal_name' => 'cash_in',
    'from_account_type' => 'agent_float', 'to_account_type' => 'wallet']);          // the customer pays nothing
Wallet::transferTypes()->create(['name' => 'Commission', 'internal_name' => 'commission', 'kind' => 'generated',
    'from_account_type' => 'bank_commissions', 'to_account_type' => 'agent_commission']);

Wallet::fees()->create([
    'name' => 'Cash-in commission', 'internal_name' => 'cash_in_commission',
    'transfer_type' => 'agent_float.cash_in',
    'generated_transfer_type' => 'bank_commissions.commission',
    'charge_mode' => 'percentage', 'amount' => 1,               // or fixed, with min_amount / max_amount tiers
    'payer' => 'system',                                        // the bank pays
    'receiver' => 'source_owner',                               // the paying agent's own commission account
]);
```

For a **cash-out**, where the agent *receives* the customer's money, use `'receiver' => 'destination_owner'`.

The commission is booked in the same transaction as the cash movement, shows in both accounts' history, and is reversed
together with it by a [chargeback](chargebacks.md). The agent is not charged the commission, and the customer pays no fee.

Rules for the owner roles:

- The side they apply to must be a **user** account type (a system account has no owner). `create()` throws
  `InvalidParameterException` otherwise.
- The owner must have an **active** account of that type, or the whole payment is refused with `InvalidAccountException` and
  nothing is booked. A payment never goes through with its commission silently dropped.
- A payment from or to a system account has no owner to look up, so it is refused the same way.
- If the bank pays from a `limited` system account, that account needs funds; the commission is refused like any other
  payment the payer can't afford.

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
