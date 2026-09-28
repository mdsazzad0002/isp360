<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// For the older controllers (audit C2): records are only found inside the current branch, and a
// write takes only the fields it names — never every request field — with the ids of related
// records (bank, account head, customer, area...) checked to belong to the same branch.
// Expects $this->branchId (set by the controller's middleware).
trait BranchScoped
{
    // related id => its branch-scoped table
    private static array $branchRelations = [
        'bank_id' => 'banks',
        'account_id' => 'account_heads',
        'customer_id' => 'customers',
        'area_id' => 'areas',
        'zone_id' => 'zones',
        'box_id' => 'boxes',
        'reseller_id' => 'resellers',
        'referred_by_id' => 'customers',
    ];

    // The record with this id in the current branch, or null.
    protected function findInBranch(string $model, $id, bool $withTrashed = false)
    {
        if (!$id) {
            return null;
        }
        $query = $model::query()->where('branch_id', $this->branchId);
        if ($withTrashed) {
            $query->withTrashed();
        }
        return $query->find($id);
    }

    // [fields, error response|null]: the request's values for $fields ('' ids become null).
    protected function branchFields(Request $request, array $fields): array
    {
        $values = $request->only($fields);
        foreach (self::$branchRelations as $key => $table) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            if ($values[$key] === '' || $values[$key] === 'null') {
                $values[$key] = null;
                continue;
            }
            if ($values[$key] !== null && !DB::table($table)->where('id', $values[$key])->where('branch_id', $this->branchId)->exists()) {
                return [null, send_error("The selected {$key} is not in this branch", ['field' => [$key]], 422)];
            }
        }
        return [$values, null];
    }
}
