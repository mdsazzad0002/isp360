<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\Isp\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
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
        $role = Role::with('adUser', 'upUser', 'deUser')->latest()->get();
        return response()->json($role);
    }

    public function create()
    {
        if (!checkAccess('role')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/Role/Entry');
    }

    public function roleAccess($id)
    {
        if (!checkAccess('role')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        $data['role'] = Role::withTrashed()->find($id);
        return \Inertia\Inertia::render('Control/Role/Access', $data);
    }

    public function getRoleAccess(Request $request)
    {
        $access = Role::where('id', $request->id)->value('access');
        return response()->json(['access' => empty($access) ? [] : json_decode($access, true)]);
    }

    public function saveRoleAccess(Request $request)
    {
        $validator = Validator::make($request->all(), ['id' => 'required|integer', 'access' => 'present|array', 'access.*' => 'string|max:100']);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        $role = Role::find($request->id);
        if (!$role) return send_error('Role not found', null, 404);
        $access = array_values(array_unique($request->access));
        // below admin, a user can only hand out permissions they hold themselves
        if (auth()->user()->rank() < 2) {
            $old = json_decode((string) $role->access, true) ?: [];
            $added = array_diff($access, $old);
            $missing = array_values(array_filter($added, fn ($name) => !checkAccess($name)));
            if ($missing) {
                return send_error('You cannot grant permissions you do not have: ' . implode(', ', $missing), null, 403);
            }
        }
        try {
            $old = json_decode((string) $role->access, true) ?: [];
            Role::where('id', $role->id)->update([
                'access' => json_encode($access),
            ]);
            AuditLogger::log('role.access_changed', $role, ['access' => $old], ['access' => $access]);

            return response()->json(['status' => true, 'message' => "Role access has been saved successfully"]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    public function store(Request $request)
    {
        $branchId = $this->branchId;
        $validator = Validator::make($request->all(), [
            'name'     => [
                'required',
                Rule::unique('roles')
                    ->where(function ($query) use ($branchId) {
                        $query->where('branch_id', $branchId);
                    })
                    ->whereNull('deleted_at'),
            ],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        if ($r = $this->reservedName($request)) return $r;
        try {
            $check = Role::where('name', $request->name)->withTrashed()->first();
            if (!empty($check) && $check->deleted_at != NULL) {
                $check->status = 'a';
                $check->deleted_by = NULL;
                $check->deleted_at = NULL;
                $check->update();
            } else {
                $data = new Role();
                $data->name = $request->name;
                $data->created_by = $this->userId;
                $data->branch_id  = $this->branchId;
                $data->ipAddress  = request()->ip();
                $data->save();
            }

            return response()->json(['status' => true, 'message' => "Role has created successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(Request $request)
    {
        $branchId = $this->branchId;
        $validator = Validator::make($request->all(), [
            'name'     => [
                'required',
                Rule::unique('roles')
                    ->ignore($request->id)
                    ->where(function ($query) use ($branchId) {
                        $query->where('branch_id', $branchId);
                    })
                    ->whereNull('deleted_at'),
            ],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        if ($r = $this->reservedName($request)) return $r;
        try {
            $data = Role::find($request->id);
            if (!$data) return send_error('Role not found', null, 404);
            $data->name = $request->name;
            $data->updated_at = Carbon::now();
            $data->updated_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->update();

            return response()->json(['status' => true, 'message' => "Role has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    // "Superadmin" and "admin" pass every permission check by name, so no role may be called that
    private function reservedName(Request $request)
    {
        return in_array(strtolower(trim((string) $request->name)), ['superadmin', 'admin'], true)
            ? send_error('This role name is reserved', null, 422) : null;
    }

    public function destroy(Request $request)
    {
        try {
            $data = Role::find($request->id);
            $data->deleted_by = $this->userId;
            $data->status = 'd';
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            return response()->json(['status' => true, 'message' => "Role has deleted successfully"]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }
}
