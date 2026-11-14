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
            $table->boolean('reserve_on_payment_request')->default(false);
        });

        Schema::table($this->t('payment_requests'), function (Blueprint $table) {
            $table->decimal('reserved_amount', 20, 4)->nullable();   // held on the payer while the request is open
        });
    }

    public function down(): void
    {
        Schema::table($this->t('payment_requests'), fn (Blueprint $table) => $table->dropColumn('reserved_amount'));
        Schema::table($this->t('transfer_types'), fn (Blueprint $table) => $table->dropColumn('reserve_on_payment_request'));
    }
};
