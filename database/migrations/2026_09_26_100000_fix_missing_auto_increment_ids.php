<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The imported 2024 schema created these tables' primary keys without AUTO_INCREMENT,
// so every insert got id 0 and the second insert failed with a duplicate key.
return new class extends Migration
{
    private array $tables = ['banks', 'bank_transactions', 'payments', 'receives', 'transactions', 'user_accesses', 'user_activities'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $extra = DB::selectOne(
                "select extra from information_schema.columns where table_schema = database() and table_name = ? and column_name = 'id'",
                [$table]
            );
            if ($extra && ! str_contains((string) $extra->extra, 'auto_increment')) {
                DB::statement("ALTER TABLE `$table` MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT");
            }
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: removing AUTO_INCREMENT would re-break inserts.
    }
};
