<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'username')) {
                $table->string('username')->nullable()->unique()->after('email');
            }
            if (! Schema::hasColumn('customers', 'password')) {
                $table->string('password')->nullable()->after('username');
            }
            if (! Schema::hasColumn('customers', 'reseller_id')) {
                $table->unsignedBigInteger('reseller_id')->nullable()->after('area_id');
                $table->index('reseller_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'reseller_id')) {
                $table->dropIndex(['reseller_id']);
                $table->dropColumn('reseller_id');
            }
            if (Schema::hasColumn('customers', 'password')) {
                $table->dropColumn('password');
            }
            if (Schema::hasColumn('customers', 'username')) {
                $table->dropColumn('username');
            }
        });
    }
};
