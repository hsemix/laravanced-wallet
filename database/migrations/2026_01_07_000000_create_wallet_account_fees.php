<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('wallet.connection');
    }

    private function t(string $name): string
    {
        return config('wallet.table_prefix', 'wallet_').$name;
    }

    public function up(): void
    {
        Schema::table($this->t('accounts'), function (Blueprint $table) {
            $table->boolean('fees_waived')->default(false);   // exempt from every account fee
        });

        Schema::create($this->t('account_fees'), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('internal_name')->unique();
            $table->string('description', 500)->nullable();
            $table->foreignId('account_type_id')->constrained($this->t('account_types'))->restrictOnDelete();   // accounts charged
            $table->foreignId('transfer_type_id')->constrained($this->t('transfer_types'))->restrictOnDelete(); // account -> system account
            $table->string('trigger', 16);                         // opening | recurring
            $table->string('charge_mode', 16)->default('fixed');   // fixed | percentage (of the balance, recurring only)
            $table->decimal('amount', 20, 4);
            $table->decimal('min_amount', 20, 4)->nullable();      // floor of a percentage fee
            $table->decimal('max_amount', 20, 4)->nullable();      // cap of a percentage fee
            $table->unsignedInteger('every_amount')->nullable();   // recurring: every N ...
            $table->string('every_unit', 16)->nullable();          // ... day|week|month|year
            $table->timestamp('starts_at')->nullable();            // recurring: never charge periods before this
            $table->string('on_insufficient_funds', 16)->default('skip'); // skip | retry | allow_negative
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create($this->t('account_fee_charges'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_fee_id')->constrained($this->t('account_fees'))->restrictOnDelete();
            $table->foreignId('account_id')->constrained($this->t('accounts'))->restrictOnDelete();
            $table->unsignedInteger('period')->default(0);         // 0 = opening, n = the nth recurring period
            $table->date('due_date')->index();
            $table->string('status', 16)->default('pending')->index();
            $table->decimal('amount', 20, 4)->nullable();          // set once charged
            $table->foreignId('transaction_id')->nullable()->constrained($this->t('transactions'))->nullOnDelete();
            $table->string('reason', 500)->nullable();             // why it is pending / skipped / waived
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['account_fee_id', 'account_id', 'period'], $this->t('account_fee_charges_unique'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->t('account_fee_charges'));
        Schema::dropIfExists($this->t('account_fees'));

        Schema::table($this->t('accounts'), function (Blueprint $table) {
            $table->dropColumn('fees_waived');
        });
    }
};
