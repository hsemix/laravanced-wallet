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
        Schema::table($this->t('transfer_types'), function (Blueprint $table) {
            $table->boolean('allows_payment_requests')->default(false);
        });

        Schema::create($this->t('payment_requests'), function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('from_account_id')->constrained($this->t('accounts'))->restrictOnDelete();  // payer
            $table->foreignId('to_account_id')->constrained($this->t('accounts'))->restrictOnDelete();    // payee / requester
            $table->foreignId('transfer_type_id')->constrained($this->t('transfer_types'))->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->string('description', 500)->nullable();
            $table->string('status', 16)->default('requested')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->nullableMorphs('requested_by');
            $table->timestamp('decided_at')->nullable();
            $table->nullableMorphs('decided_by');
            $table->boolean('decided_by_owner')->nullable();   // true = the account owner acted; false = someone on their behalf
            $table->string('decision_reason', 500)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained($this->t('transactions'))->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->t('payment_requests'));

        Schema::table($this->t('transfer_types'), function (Blueprint $table) {
            $table->dropColumn('allows_payment_requests');
        });
    }
};
