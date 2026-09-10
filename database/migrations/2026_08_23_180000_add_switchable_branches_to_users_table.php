<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'switchable_branches')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // Comma-separated branch ids this user is allowed to switch into.
            // Null/empty means unrestricted — every branch stays selectable,
            // which keeps every existing user's behavior unchanged after
            // this rolls out.
            $table->text('switchable_branches')->nullable()->after('branch_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'switchable_branches')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('switchable_branches');
        });
    }
};
