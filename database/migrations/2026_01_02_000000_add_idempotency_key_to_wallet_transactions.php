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

    private function table(): string
    {
        return config('wallet.table_prefix', 'wallet_').'transactions';
    }

    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('reference');
            // Scoped to the payer: two different payers may reuse the same key.
            $table->unique(['from_account_id', 'idempotency_key'], 'wallet_transactions_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropUnique('wallet_transactions_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
