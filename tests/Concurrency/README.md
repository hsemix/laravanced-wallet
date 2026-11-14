# Concurrency checks (MySQL, opt-in)

PHPUnit here runs on in-memory SQLite in a single process, so it cannot prove the row locking works.
These scripts run real parallel PHP processes against MySQL. They are **not** part of `vendor/bin/phpunit`.

```bash
mysql -uroot -p -e "CREATE DATABASE wallet_concurrency"
export WALLET_APP_PATH=/path/to/your/laravel/app   # needs DB_* in its .env; app has User with HasWallet
php tests/Concurrency/setup.php                    # migrate:fresh on the scratch DB + seed 6 users

php tests/Concurrency/run.php overspend 10 3       # 10 workers x 3 tries draining a 100 balance
php tests/Concurrency/run.php crosspay 12 40       # overlapping/opposing transfers with a fee (deadlocks?)
php tests/Concurrency/run.php idem 12 3            # same idempotency key from every worker
php tests/Concurrency/run.php chargeback 10 1      # same transaction charged back by every worker
php tests/Concurrency/run.php decide 10 1          # authorize vs deny race on one pending transaction
php tests/Concurrency/run.php accept 10 1          # same payment request accepted by every worker
php tests/Concurrency/verify.php                   # cached balances == ledger, all balances sum to 0, no negatives
```

Re-run `setup.php` between scenarios (the seeded balances are consumed). Expected results:

| scenario     | expected |
|--------------|----------|
| `overspend`  | exactly 3 ok (3 × 25.25 incl. 1% fee), rest insufficient; balance 24.25 |
| `crosspay`   | all ok, 0 deadlocks, no drift, sum of balances 0 |
| `idem`       | every call ok, but exactly **one** new transaction |
| `chargeback` | exactly 1 ok, the rest rejected |
| `decide`     | exactly 1 ok, the rest rejected |
| `accept`     | exactly 1 ok (payee receives 20 once), the rest rejected |
