<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Tests\Feature;

use Hsemix\AdvancedWallet\Exceptions\InvalidAccountException;
use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;
use Hsemix\AdvancedWallet\Facades\Wallet;
use Hsemix\AdvancedWallet\Tests\TestCase;

class FeeScenariosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapLedger();
    }

    public function test_a_fee_can_itself_be_charged_a_fee(): void
    {
        // 10% fee on transfers; the fee transfer (wallet.fee) is itself charged a flat 0.10 by a "tax" generated type.
        Wallet::transferTypes()->create(['name' => 'Tax', 'internal_name' => 'tax', 'kind' => 'generated', 'from_account_type' => 'wallet', 'to_account_type' => 'fees']);
        Wallet::fees()->create(['name' => 'Fee', 'internal_name' => 'f1', 'transfer_type' => 'wallet.transfer', 'generated_transfer_type' => 'wallet.fee', 'charge_mode' => 'percentage', 'amount' => 10]);
        Wallet::fees()->create(['name' => 'Tax', 'internal_name' => 'f2', 'transfer_type' => 'wallet.fee', 'generated_transfer_type' => 'wallet.tax', 'charge_mode' => 'fixed', 'amount' => 0.10]);

        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob, $bobAcc] = $this->funded('Bob');

        $ann->payTo($bob, 'wallet.transfer', 50);

        $this->assertMoney(50, $this->bal($bobAcc));
        $this->assertMoney(100 - 50 - 5 - 0.10, $this->bal($annAcc));   // 5 fee + 0.10 fee-on-fee, both paid by the source
        $this->assertMoney(5.10, $this->bal($this->feeAccount()));
    }

    public function test_a_fee_can_be_paid_to_a_specific_account(): void
    {
        [$ann, $annAcc] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');
        [$partner, $partnerAcc] = $this->funded('Partner');
        Wallet::transferTypes()->create(['name' => 'Commission', 'internal_name' => 'commission', 'kind' => 'generated', 'from_account_type' => 'wallet', 'to_account_type' => 'wallet']);
        Wallet::fees()->create(['name' => 'Commission', 'internal_name' => 'com', 'transfer_type' => 'wallet.transfer', 'generated_transfer_type' => 'wallet.commission',
            'charge_mode' => 'fixed', 'amount' => 2, 'receiver' => 'fixed_account', 'receiver_account_id' => $partnerAcc->id]);

        $ann->payTo($bob, 'wallet.transfer', 10);

        $this->assertMoney(2, $this->bal($partnerAcc));
        $this->assertMoney(88, $this->bal($annAcc));
    }

    public function test_deducted_fees_larger_than_the_payment_are_refused(): void
    {
        Wallet::fees()->create(['name' => 'Cut', 'internal_name' => 'cut', 'transfer_type' => 'wallet.transfer', 'generated_transfer_type' => 'wallet.fee',
            'charge_mode' => 'fixed', 'amount' => 5, 'deduct_amount' => true]);
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $this->expectException(InvalidParameterException::class);
        $ann->payTo($bob, 'wallet.transfer', 5);   // fee would consume the whole payment
    }

    public function test_a_fee_whose_accounts_do_not_match_the_generated_type_is_refused(): void
    {
        // generated type is wallet -> fees, but the fee asks for a 'system' payer, which resolves to the *fees* system account
        Wallet::fees()->create(['name' => 'Bad', 'internal_name' => 'bad', 'transfer_type' => 'wallet.transfer', 'generated_transfer_type' => 'wallet.fee',
            'charge_mode' => 'fixed', 'amount' => 1, 'payer' => 'system']);
        [$ann] = $this->funded('Ann', 100);
        [$bob] = $this->funded('Bob');

        $this->expectException(InvalidAccountException::class);
        $ann->payTo($bob, 'wallet.transfer', 10);
    }
}
