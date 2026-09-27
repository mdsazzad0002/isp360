<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A package with a reseller_id belongs to that reseller: they manage it from the
    // reseller portal and it can only be used on that reseller's customers.
    public function up(): void
    {
        if (! Schema::hasColumn('packages', 'reseller_id')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->unsignedBigInteger('reseller_id')->nullable()->after('branch_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('packages', 'reseller_id')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->dropIndex(['reseller_id']);
                $table->dropColumn('reseller_id');
            });
        }
    }
};
