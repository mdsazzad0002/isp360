<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Company changes to a base package no longer flow into reseller copies on their own:
// the copy keeps the company price the reseller accepted (base_price) and its old
// speed/profile/cycle until the reseller reviews the change. Reseller edits go live at once.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('packages', 'base_price')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->decimal('base_price', 14, 2)->nullable()->after('base_package_id');
            });
        }
        DB::statement('UPDATE packages p JOIN packages b ON b.id = p.base_package_id SET p.base_price = b.price WHERE p.base_price IS NULL');
        DB::table('packages')->whereNotNull('reseller_id')->update([
            'approval_status' => 'approved',
            'pending_changes' => null,
            'approved_at' => DB::raw('coalesce(approved_at, now())'),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('packages', 'base_price')) {
            Schema::table('packages', fn (Blueprint $table) => $table->dropColumn('base_price'));
        }
    }
};
