<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an employee be paid (e.g. bonus/reimbursement, outside the salary run)
 * through the existing generic Payment module, the same way a customer,
 * supplier, or provider is — by adding an `employee_id` column and an
 * `employee` type, rather than building a separate payment flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->after('provider_id');
        });

        DB::statement("ALTER TABLE payments MODIFY type ENUM('customer','supplier','careflow_order','provider','employee') NOT NULL DEFAULT 'supplier'");

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
        });

        DB::statement("ALTER TABLE payments MODIFY type ENUM('customer','supplier','careflow_order','provider') NOT NULL DEFAULT 'supplier'");
    }
};
