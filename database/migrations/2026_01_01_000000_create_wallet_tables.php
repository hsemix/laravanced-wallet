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
        Schema::create($this->t('account_types'), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('internal_name')->unique();
            $table->text('description')->nullable();
            $table->string('kind', 16);                       // user | system
            $table->string('system_limit_type', 16)->nullable(); // limited | unlimited (system only)
            $table->boolean('generate_account_number')->default(false);
            $table->string('account_number_prefix', 16)->nullable()->unique();
            $table->string('currency', 3)->default('USD');
            $table->timestamps();
        });

        Schema::create($this->t('accounts'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_type_id')->constrained($this->t('account_types'))->restrictOnDelete();
            $table->nullableMorphs('owner');                  // null owner = system account
            $table->string('number')->nullable()->unique();
            $table->decimal('credit_limit', 20, 4)->default(0); // allowed overdraft (positive number)
            $table->timestamp('negative_since')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['account_type_id', 'owner_type', 'owner_id'], 'wallet_accounts_owner_type_unique');
        });

        Schema::create($this->t('account_balances'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained($this->t('accounts'))->cascadeOnDelete();
            $table->decimal('balance', 20, 4)->default(0);
            $table->decimal('reserved', 20, 4)->default(0);
            $table->timestamps();
        });

        Schema::create($this->t('transfer_types'), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('internal_name');
            $table->text('description')->nullable();
            $table->string('kind', 16)->default('payment');  // payment | generated
            $table->foreignId('from_account_type_id')->constrained($this->t('account_types'))->restrictOnDelete();
            $table->foreignId('to_account_type_id')->constrained($this->t('account_types'))->restrictOnDelete();
            $table->boolean('enabled')->default(true);
            $table->boolean('requires_description')->default(false);
            $table->boolean('requires_authorization')->default(false);
            $table->string('confirmation_message')->nullable();

            // Per-payment and period limits
            $table->decimal('min_amount', 20, 4)->nullable();
            $table->decimal('max_amount', 20, 4)->nullable();
            $table->decimal('max_amount_per_day', 20, 4)->nullable();
            $table->decimal('max_amount_per_week', 20, 4)->nullable();
            $table->decimal('max_amount_per_month', 20, 4)->nullable();
            $table->unsignedInteger('max_transfers_per_day')->nullable();
            $table->unsignedInteger('max_transfers_per_week')->nullable();
            $table->unsignedInteger('max_transfers_per_month')->nullable();

            // Scheduled / recurring / interest
            $table->boolean('allows_scheduled_payments')->default(false);
            $table->boolean('allows_recurring_payments')->default(false);
            $table->boolean('reserve_total_amount_on_scheduled_payments')->default(false);
            $table->unsignedInteger('max_installments')->nullable();
            $table->boolean('allows_interest')->default(false);
            $table->decimal('interest_rate', 10, 4)->nullable();   // percent
            $table->boolean('interest_is_annual')->default(false);

            // Notification flags (consumed by listeners)
            $table->boolean('notify_payment_received')->default(false);
            $table->boolean('notify_payment_sender')->default(false);
            $table->timestamps();

            $table->unique(['from_account_type_id', 'internal_name'], 'wallet_transfer_types_internal_unique');
        });

        Schema::create($this->t('transfer_fees'), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('internal_name')->unique();
            $table->text('description')->nullable();
            $table->foreignId('original_transfer_type_id')->constrained($this->t('transfer_types'))->cascadeOnDelete();
            $table->foreignId('generated_transfer_type_id')->constrained($this->t('transfer_types'))->restrictOnDelete();
            $table->string('charge_mode', 16);               // fixed | percentage
            $table->decimal('amount', 20, 4);                // fixed value or percent
            $table->decimal('min_amount', 20, 4)->nullable(); // fee applies when payment >= min
            $table->decimal('max_amount', 20, 4)->nullable(); // fee applies when payment <= max
            $table->string('payer', 24)->default('source');
            $table->string('receiver', 24)->default('system');
            $table->foreignId('payer_account_id')->nullable()->constrained($this->t('accounts'))->nullOnDelete();
            $table->foreignId('receiver_account_id')->nullable()->constrained($this->t('accounts'))->nullOnDelete();
            $table->boolean('deduct_amount')->default(false); // take the fee out of the payment instead of on top
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create($this->t('transactions'), function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('from_account_id')->constrained($this->t('accounts'))->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained($this->t('accounts'))->restrictOnDelete();
            $table->foreignId('transfer_type_id')->constrained($this->t('transfer_types'))->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->decimal('reserved_amount', 20, 4)->default(0); // held on the payer while pending/scheduled
            $table->text('description')->nullable();
            $table->string('status', 16);
            $table->nullableMorphs('by');                    // who performed it
            $table->foreignId('chargeback_of_id')->nullable()->constrained($this->t('transactions'))->nullOnDelete();

            // Scheduled / recurring
            $table->boolean('scheduled')->default(false);
            $table->boolean('first_installment_immediate')->default(false);
            $table->unsignedInteger('installments_count')->nullable();
            $table->unsignedInteger('frequency_rate')->nullable();
            $table->string('frequency_unit', 8)->nullable();
            $table->date('process_date')->nullable();

            $table->json('meta')->nullable();
            $table->timestamp('decided_at')->nullable();     // authorized / denied / cancelled
            $table->timestamps();

            $table->index(['status', 'scheduled']);
        });

        Schema::create($this->t('transfers'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained($this->t('transactions'))->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained($this->t('transfers'))->cascadeOnDelete();
            $table->foreignId('transfer_type_id')->constrained($this->t('transfer_types'))->restrictOnDelete();
            $table->foreignId('from_account_id')->constrained($this->t('accounts'))->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained($this->t('accounts'))->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->foreignId('transfer_fee_id')->nullable()->constrained($this->t('transfer_fees'))->nullOnDelete();
            $table->foreignId('installment_id')->nullable();
            $table->foreignId('chargeback_of_id')->nullable()->constrained($this->t('transfers'))->nullOnDelete();
            $table->timestamps();

            $table->index(['from_account_id', 'created_at']);
            $table->index(['to_account_id', 'created_at']);
        });

        Schema::create($this->t('installments'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained($this->t('transactions'))->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->decimal('amount', 20, 4);
            $table->decimal('principal', 20, 4)->default(0);
            $table->decimal('interest', 20, 4)->default(0);
            $table->decimal('balance', 20, 4)->default(0);   // principal outstanding after this installment
            $table->date('due_date');
            $table->string('status', 16);
            $table->timestamp('processed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['transaction_id', 'number']);
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        foreach (['installments', 'transfers', 'transactions', 'transfer_fees', 'transfer_types', 'account_balances', 'accounts', 'account_types'] as $table) {
            Schema::dropIfExists($this->t($table));
        }
    }
};
