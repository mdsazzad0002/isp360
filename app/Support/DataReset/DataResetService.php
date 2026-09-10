<?php

namespace App\Support\DataReset;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DataResetService
{
    /**
     * Row counts per selected group, for the checklist/confirm screen.
     */
    public function countRows(array $groupKeys): array
    {
        $counts = [];
        foreach (ResetGroups::tablesFor($groupKeys) as $key => $tables) {
            $total = 0;
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    $total += DB::table($table)->count();
                }
            }
            $counts[$key] = $total;
        }
        return $counts;
    }

    /**
     * Truncate every table in the selected groups (children before parents,
     * per ResetGroups' listed order), then re-run InitialSetupSeeder so the
     * install matches a fresh migrate+seed baseline. One table failing does
     * not stop the rest — every table gets a report entry either way.
     */
    public function reset(array $groupKeys): array
    {
        $report = [];

        // The live schema does carry real FK constraints between some of these tables
        // (even though the raw migration files don't show them), so truncation order
        // alone isn't enough — disable checks for the duration of the wipe.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (ResetGroups::tablesFor($groupKeys) as $tables) {
                foreach ($tables as $table) {
                    if (!Schema::hasTable($table)) {
                        continue;
                    }
                    try {
                        DB::table($table)->truncate();
                        $report[] = ['table' => $table, 'status' => 'ok'];
                    } catch (Throwable $e) {
                        $report[] = ['table' => $table, 'status' => 'error', 'message' => $e->getMessage()];
                    }
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        Artisan::call('db:seed', ['--class' => 'InitialSetupSeeder', '--force' => true]);

        return $report;
    }
}
