<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'resignation_date')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE `users`
  ADD COLUMN `resignation_date` date DEFAULT NULL AFTER `join_date`,
  ADD COLUMN `resignation_reason` text DEFAULT NULL AFTER `resignation_date`;
SQL
        );
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'resignation_date')) {
            Schema::table('users', function ($table) {
                $table->dropColumn(['resignation_date', 'resignation_reason']);
            });
        }
    }
};
