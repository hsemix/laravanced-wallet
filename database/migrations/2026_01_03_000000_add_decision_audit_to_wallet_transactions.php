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
            $table->nullableMorphs('decided_by');           // who authorized / denied / cancelled
            $table->string('decision_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropMorphs('decided_by');
            $table->dropColumn('decision_reason');
        });
    }
};
