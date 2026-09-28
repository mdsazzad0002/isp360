<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    use Concerns\BranchScoped;

    // fields a request may set (audit C2: never every request field)
    private const FIELDS = ['code', 'name', 'title', 'address', 'phone'];

    protected $userId;
    protected $branchId;
    public function __construct()
    {
        $this->middleware('auth');

        $this->middleware(function ($request, $next) {
            $this->branchId = $request->session()->get('branch')->id;
            $this->userId = auth()->user()->id;
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $query = Branch::with('adUser', 'upUser', 'deUser')->latest();

        // Used by the branch switcher only — the Branch management page
        // still needs the full list to administer every branch, so this
        // narrowing is opt-in via forSwitch rather than baked into index().
        if ($request->boolean('forSwitch')) {
            $allowedBranchIds = auth()->user()?->allowedBranchIds();
            if ($allowedBranchIds !== null) {
                $query->whereIn('id', $allowedBranchIds);
            }
        }

        return response()->json($query->get());
    }

    public function create()
    {
        if (!checkAccess('branch')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/SimpleCrud', [
            'title' => 'Branch Entry',
            'slug' => 'branch',
        ]);
    }

    public function store(Request $request)
    {
        if ($r = $this->headOfficeOnly()) return $r;
        $validator = Validator::make($request->all(), [
            'name'     => [
                'required',
                Rule::unique('branches')->whereNull('deleted_at'),
            ],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);

        try {
            $check = Branch::where('name', $request->name)->withTrashed()->first();
            if (!empty($check) && $check->deleted_at != NULL) {
                $check->status = 'a';
                $check->deleted_by = NULL;
                $check->deleted_at = NULL;
                $check->update();
            } else {
                $data = new Branch();
                [$fields, $error] = $this->branchFields($request, self::FIELDS);
                if ($error) return $error;
                $data->forceFill($fields);
                $data->created_by = $this->userId;
                $data->ipAddress  = request()->ip();
                $data->save();
            }

            return response()->json(['status' => true, 'message' => "Branch has created successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(Request $request)
    {
        if ($r = $this->headOfficeOnly()) return $r;
        $validator = Validator::make($request->all(), [
            'name'     => [
                'required',
                Rule::unique('branches')->ignore($request->id)->whereNull('deleted_at'),
            ],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $data = Branch::find($request->id);
            [$fields, $error] = $this->branchFields($request, self::FIELDS);
            if ($error) return $error;
            $data->forceFill($fields);
            $data->updated_at = Carbon::now();
            $data->updated_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->update();

            return response()->json(['status' => true, 'message' => "Branch has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    // branches belong to the whole company: a branch or regional user can't add, rename or remove them
    private function headOfficeOnly()
    {
        return auth()->user()->isHeadOffice() ? null : send_error('Only head-office users can manage branches', null, 403);
    }

    public function destroy(Request $request)
    {
        if ($r = $this->headOfficeOnly()) return $r;
        try {
            $data = Branch::find($request->id);
            if (!$data) {
                return send_error('Not found', 'Branch not found', 404);
            }

            if ($this->branchHasData($data->id)) {
                return send_error(
                    'Branch has data',
                    'This branch has existing data (products, sales, purchases, etc.) and cannot be deleted.',
                    422
                );
            }

            $data->deleted_by = $this->userId;
            $data->status = 'd';
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            return response()->json(['status' => true, 'message' => "Branch has deleted successfully"]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    /**
     * True if any other table that carries a branch_id column has a row
     * pointing at this branch — checked dynamically off the live schema so
     * it stays correct as new branch-scoped tables get added, rather than
     * relying on a hardcoded list that drifts out of date.
     */
    private function branchHasData(int $branchId): bool
    {
        $tables = collect(\Illuminate\Support\Facades\DB::select(
            "SELECT TABLE_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'branch_id'"
        ))->pluck('TABLE_NAME')->reject(fn ($table) => $table === 'branches');

        foreach ($tables as $table) {
            if (\Illuminate\Support\Facades\DB::table($table)->where('branch_id', $branchId)->exists()) {
                return true;
            }
        }

        return false;
    }
}
