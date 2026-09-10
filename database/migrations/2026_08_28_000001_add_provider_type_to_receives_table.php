<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors 2026_08_27_000007_add_provider_type_to_payments_table.php on the
 * Receive side, so money coming back from a CareFlow provider (e.g. a
 * refunded/overpaid processing fee) can be logged the same way a customer
 * or supplier receipt is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receives', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable()->after('supplier_id');
        });

        DB::statement("ALTER TABLE receives MODIFY type ENUM('customer','supplier','provider') NOT NULL DEFAULT 'customer'");
    }

    public function down(): void
    {
        Schema::table('receives', function (Blueprint $table) {
            $table->dropColumn('provider_id');
        });

        DB::statement("ALTER TABLE receives MODIFY type ENUM('customer','supplier') NOT NULL DEFAULT 'customer'");
    }
};
