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
        return config('wallet.table_prefix', 'wallet_').'transfer_types';
    }

    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->boolean('allows_chargebacks')->default(true);
            $table->boolean('allows_forced_chargebacks')->default(true);
            $table->unsignedInteger('max_chargeback_time_amount')->nullable();   // null = no time limit
            $table->string('max_chargeback_time_field', 16)->nullable();         // minute|hour|day|week|month|year
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn(['allows_chargebacks', 'allows_forced_chargebacks', 'max_chargeback_time_amount', 'max_chargeback_time_field']);
        });
    }
};
