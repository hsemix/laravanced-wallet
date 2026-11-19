<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Exceptions\InvalidAccountException;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Models\Account;
use Hsemix\AdvancedWallet\Models\Transaction;
use Hsemix\AdvancedWallet\Tests\TestCase;
use Hsemix\AdvancedWallet\Tests\TestUser;

/**
 * Agent banking: the bank pays an agent commission on cash-in / cash-out, into the agent's own commission
 * account. The agent has a float account (which moves the cash) and a commission account (which earns).
 */
class OwnerRoleFeesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapLedger();

        $a = Wallet::accounts();
        $a->createAccountType(['name' => 'Agent float', 'internal_name' => 'agent_float', 'kind' => 'user']);
        $a->createAccountType(['name' => 'Agent commission', 'internal_name' => 'agent_commission', 'kind' => 'user']);
        $a->createAccountType(['name' => 'Bank commissions', 'internal_name' => 'bank_commissions', 'kind' => 'system', 'system_limit_type' => 'unlimited']);

        $t = Wallet::transferTypes();
        $t->create(['name' => 'Float top-up', 'internal_name' => 'float_topup', 'from_account_type' => 'treasury', 'to_account_type' => 'agent_float']);
        $t->create(['name' => 'Cash in', 'internal_name' => 'cash_in', 'from_account_type' => 'agent_float', 'to_account_type' => 'wallet']);
        $t->create(['name' => 'Cash out', 'internal_name' => 'cash_out', 'from_account_type' => 'wallet', 'to_account_type' => 'agent_float']);
        $t->create(['name' => 'Commission', 'internal_name' => 'commission', 'kind' => 'generated', 'from_account_type' => 'bank_commissions', 'to_account_type' => 'agent_commission']);
    }

    /** @return array{0:TestUser, 1:Account, 2:Account} agent, float account, commission account */
    private function agent(string $name, int $float = 1000, bool $withCommissionAccount = true): array
    {
        $user = $this->user($name);
        $floatAccount = $user->openAccount('agent_float');
        $commission = $withCommissionAccount ? $user->openAccount('agent_commission') : null;
        if ($float > 0) {
            Wallet::pay(['from' => 'system', 'to' => $user, 'type' => 'treasury.float_topup', 'amount' => $float]);
        }

        return [$user, $floatAccount->fresh(), $commission];
    }

    private function commissionFee(string $role, string $on, array $overrides = []): void
    {
        Wallet::fees()->create($overrides + [
            'name' => 'Agent commission', 'internal_name' => 'agent_commission_'.$on, 'transfer_type' => $on,
            'generated_transfer_type' => 'bank_commissions.commission',
            'charge_mode' => 'percentage', 'amount' => 1, 'payer' => 'system', 'receiver' => $role,
        ]);
    }

    private function pool(): Account
    {
        return Wallet::accounts()->systemAccount('bank_commissions');
    }

    public function test_cash_in_pays_the_commission_into_the_agents_own_commission_account(): void
    {
        $this->commissionFee('source_owner', 'agent_float.cash_in');
        [$agent, $float, $commission] = $this->agent('Agent');
        [$customer, $wallet] = $this->funded('Customer');

        Wallet::pay(['from' => $agent, 'to' => $customer, 'type' => 'agent_float.cash_in', 'amount' => 200]);

        $this->assertMoney(800, $this->bal($float));        // the agent's float pays the cash, not the commission
        $this->assertMoney(200, $this->bal($wallet));       // the customer receives the full amount and pays nothing
        $this->assertMoney(2, $this->bal($commission));     // 1% of 200, into the commission account
        $this->assertMoney(-2, $this->bal($this->pool()));  // paid by the bank
    }

    public function test_each_agent_earns_into_their_own_commission_account(): void
    {
        $this->commissionFee('source_owner', 'agent_float.cash_in');
        [$one, , $commissionOne] = $this->agent('One');
        [$two, , $commissionTwo] = $this->agent('Two');
        [$customer] = $this->funded('Customer');

        Wallet::pay(['from' => $one, 'to' => $customer, 'type' => 'agent_float.cash_in', 'amount' => 100]);
        Wallet::pay(['from' => $two, 'to' => $customer, 'type' => 'agent_float.cash_in', 'amount' => 300]);

        $this->assertMoney(1, $this->bal($commissionOne));
        $this->assertMoney(3, $this->bal($commissionTwo));
    }

    public function test_cash_out_pays_the_receiving_agent_with_destination_owner(): void
    {
        $this->commissionFee('destination_owner', 'wallet.cash_out');
        [$agent, $float, $commission] = $this->agent('Agent', 0);
        [$customer, $wallet] = $this->funded('Customer', 500);

        Wallet::pay(['from' => $customer, 'to' => $agent, 'type' => 'wallet.cash_out', 'amount' => 100]);

        $this->assertMoney(400, $this->bal($wallet));
        $this->assertMoney(100, $this->bal($float));
        $this->assertMoney(1, $this->bal($commission));
    }

    public function test_the_fee_is_part_of_the_payment_and_shows_in_the_preview(): void
    {
        $this->commissionFee('source_owner', 'agent_float.cash_in');
        [$agent] = $this->agent('Agent');
        [$customer] = $this->funded('Customer');

        $preview = Wallet::preview(['from' => $agent, 'to' => $customer, 'type' => 'agent_float.cash_in', 'amount' => 200]);

        $this->assertMoney(200, $preview['total_amount']);   // the agent is not charged the commission
        $this->assertCount(1, $preview['fees']);
        $this->assertMoney(2, $preview['fees'][0]['amount']);
    }

    public function test_a_chargeback_takes_the_commission_back(): void
    {
        $this->commissionFee('source_owner', 'agent_float.cash_in');
        [$agent, $float, $commission] = $this->agent('Agent');
        [$customer, $wallet] = $this->funded('Customer');
        $tx = Wallet::pay(['from' => $agent, 'to' => $customer, 'type' => 'agent_float.cash_in', 'amount' => 200]);

        Wallet::payments()->chargeback($tx);

        $this->assertMoney(1000, $this->bal($float));
        $this->assertMoney(0, $this->bal($wallet));
        $this->assertMoney(0, $this->bal($commission));
        $this->assertMoney(0, $this->bal($this->pool()));
    }

    public function test_a_fixed_commission_with_tiers_works_through_the_same_role(): void
    {
        $this->commissionFee('source_owner', 'agent_float.cash_in', ['internal_name' => 'small', 'charge_mode' => 'fixed', 'amount' => 0.5, 'max_amount' => 99.9999]);
        $this->commissionFee('source_owner', 'agent_float.cash_in', ['internal_name' => 'large', 'min_amount' => 100]);
        [$agent, , $commission] = $this->agent('Agent');
        [$customer] = $this->funded('Customer');

        Wallet::pay(['from' => $agent, 'to' => $customer, 'type' => 'agent_float.cash_in', 'amount' => 50]);    // flat 0.50
        Wallet::pay(['from' => $agent, 'to' => $customer, 'type' => 'agent_float.cash_in', 'amount' => 400]);   // 1% = 4

        $this->assertMoney(4.5, $this->bal($commission));
    }

    public function test_an_agent_without_a_commission_account_blocks_the_payment_and_books_nothing(): void
    {
        $this->commissionFee('source_owner', 'agent_float.cash_in');
        [$agent, $float] = $this->agent('Agent', 1000, false);
        [$customer, $wallet] = $this->funded('Customer');

        try {
            Wallet::pay(['from' => $agent, 'to' => $customer, 'type' => 'agent_float.cash_in', 'amount' => 200]);
            $this->fail('paid without a commission account');
        } catch (InvalidAccountException $e) {
            $this->assertStringContainsString('has no agent_commission account', $e->getMessage());
        }

        $this->assertMoney(1000, $this->bal($float));
        $this->assertMoney(0, $this->bal($wallet));
        $this->assertSame(1, Transaction::count());   // only the float top-up
    }

    public function test_an_inactive_commission_account_blocks_the_payment(): void
    {
        $this->commissionFee('source_owner', 'agent_float.cash_in');
        [$agent, , $commission] = $this->agent('Agent');
        [$customer] = $this->funded('Customer');
        $commission->update(['active' => false]);

        $this->expectException(InvalidAccountException::class);
        $this->expectExceptionMessage('is inactive');
        Wallet::pay(['from' => $agent, 'to' => $customer, 'type' => 'agent_float.cash_in', 'amount' => 200]);
    }

    public function test_a_payment_from_a_system_account_has_no_owner_to_look_up(): void
    {
        Wallet::transferTypes()->create(['name' => 'Float top-up with commission', 'internal_name' => 'float_topup2', 'from_account_type' => 'treasury', 'to_account_type' => 'agent_float']);
        $this->commissionFee('source_owner', 'treasury.float_topup2');
        $agent = $this->user('Agent');
        $agent->openAccount('agent_float');

        $this->expectException(InvalidAccountException::class);
        $this->expectExceptionMessage('has no owner');
        Wallet::pay(['from' => 'system', 'to' => $agent, 'type' => 'treasury.float_topup2', 'amount' => 100]);
    }

    public function test_an_owner_role_needs_a_user_account_type_on_that_side(): void
    {
        // generated type ends at a system account, so "the owner's account of that type" cannot exist
        $this->expectException(InvalidParameterException::class);
        Wallet::fees()->create(['name' => 'x', 'internal_name' => 'x', 'transfer_type' => 'agent_float.cash_in', 'generated_transfer_type' => 'wallet.fee',
            'charge_mode' => 'fixed', 'amount' => 1, 'receiver' => 'source_owner']);
    }

    public function test_an_owner_role_is_refused_as_payer_when_the_payer_side_is_a_system_account(): void
    {
        $this->expectException(InvalidParameterException::class);
        $this->commissionFee('source_owner', 'agent_float.cash_in', ['payer' => 'destination_owner']);   // bank_commissions is a system type
    }

    public function test_the_existing_roles_are_unchanged(): void
    {
        // source / destination / system / fixed_account behave as before (see FeeScenariosTest); here: system receiver still works.
        Wallet::fees()->create(['name' => 'Fee', 'internal_name' => 'pct', 'transfer_type' => 'wallet.transfer', 'generated_transfer_type' => 'wallet.fee', 'charge_mode' => 'percentage', 'amount' => 10]);
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $ann->payTo($bob, 'wallet.transfer', 50);

        $this->assertMoney(45, $this->bal($annAcc));
        $this->assertMoney(5, $this->bal($this->feeAccount()));
    }
}
