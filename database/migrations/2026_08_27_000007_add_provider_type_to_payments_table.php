<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a CareFlow provider (third-party vendor processing careflow orders)
 * be paid through the existing generic Payment module, the same way a
 * supplier is — by adding a `provider_id` column and a `provider` type,
 * rather than building a separate payment flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable()->after('supplier_id');
        });

        DB::statement("ALTER TABLE payments MODIFY type ENUM('customer','supplier','careflow_order','provider') NOT NULL DEFAULT 'supplier'");
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('provider_id');
        });

        DB::statement("ALTER TABLE payments MODIFY type ENUM('customer','supplier','careflow_order') NOT NULL DEFAULT 'supplier'");
    }
};
