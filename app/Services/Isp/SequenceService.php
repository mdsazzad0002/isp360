<?php

namespace App\Services\Isp;

use Illuminate\Support\Facades\DB;

// Gap-safe document numbers (PREFIX + yy + 00001) per branch and year. The row lock
// serialises concurrent callers, unlike counting existing rows.
class SequenceService
{
    public static function next(int $branchId, string $name, string $prefix): string
    {
        return DB::transaction(function () use ($branchId, $name, $prefix) {
            $year = (int) now()->format('Y');
            DB::table('number_sequences')->insertOrIgnore([
                'branch_id' => $branchId, 'name' => $name, 'year' => $year, 'last_value' => 0,
            ]);
            $row = DB::table('number_sequences')
                ->where(['branch_id' => $branchId, 'name' => $name, 'year' => $year])
                ->lockForUpdate()
                ->first();
            $next = $row->last_value + 1;
            DB::table('number_sequences')->where('id', $row->id)->update(['last_value' => $next]);

            return $prefix . now()->format('y') . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        });
    }
}
