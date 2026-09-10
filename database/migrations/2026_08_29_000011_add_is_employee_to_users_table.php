<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Employee" status used to be encoded as role='employee', overloading the
 * role column (which otherwise only drives system-access permissions).
 * That blocked an admin/manager/cashier account from also being an HR
 * employee. This decouples it: is_employee is independent of role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_employee')->default(false)->after('role');
        });

        DB::table('users')->where('role', 'employee')->update(['is_employee' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_employee');
        });
    }
};
