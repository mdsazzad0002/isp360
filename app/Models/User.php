<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Models\Concerns\HasTwoFactor;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, HasTwoFactor, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = ['id'];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
    ];

    public function adUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'id')->select('id', 'name', 'username')->withTrashed();
    }
    public function upUser()
    {
        return $this->belongsTo(User::class, 'updated_by', 'id')->select('id', 'name', 'username')->withTrashed();
    }
    public function deUser()
    {
        return $this->belongsTo(User::class, 'deleted_by', 'id')->select('id', 'name', 'username')->withTrashed();
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id')->select('id', 'name', 'title')->withTrashed();
    }

    /**
     * Branch ids this user may switch into, or null when unrestricted (every
     * branch stays selectable). switchable_branches is kept as a plain
     * comma-separated string on the column rather than a cast so the existing
     * generic request->column assignment loop in UserController keeps working.
     * A regional manager (region_id set) is limited to that region's branches,
     * narrowed further by switchable_branches when both are set.
     */
    public function allowedBranchIds(): ?array
    {
        $raw = trim((string) $this->switchable_branches);
        $listed = $raw === '' ? null : collect(explode(',', $raw))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->values()
            ->all();

        if (! $this->region_id) {
            return $listed;
        }
        $region = Branch::where('region_id', $this->region_id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        return $listed === null ? $region : array_values(array_intersect($region, $listed));
    }

    // Head-office user: sees every branch (no region and no switch list).
    public function seesAllBranches(): bool
    {
        return $this->allowedBranchIds() === null;
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }
}
