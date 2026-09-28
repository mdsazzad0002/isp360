<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use App\Services\Isp\AuditLogger;
use App\Support\LoginSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
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
        $users = User::with('branch');
        if($this->branchId != 1){
            $users = $users->where('branch_id', $this->branchId);
        }
        
        $users = $users->latest()->get();
        
        return response()->json($users);
    }

    public function create()
    {
        if (!checkAccess('user')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/User/Entry', [
            'branches' => Branch::latest()->get(),
            'currentBranchId' => session('branch')->id,
            'showBranchField' => session('branch')->id == 1,
        ]);
    }

    public function profile()
    {
        return \Inertia\Inertia::render('Control/User/Profile', [
            'user' => auth()->user(),
        ]);
    }

    // fields staff may set on a user; everything else (2FA secrets, ids, audit columns) stays out of reach
    private const FIELDS = ['name', 'username', 'phone', 'email', 'role', 'status', 'is_employee', 'branch_id', 'region_id', 'switchable_branches'];

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required',
            'username' => [
                'required',
                Rule::unique('users')->whereNull('deleted_at'),
            ],
            'password' => ['required', Password::defaults()],
            'phone'    => 'required',
            'role'     => 'required',
            'email'    => 'required',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'region_id' => 'nullable|integer|exists:regions,id',
            'image' => $request->hasFile('image') ? \App\Support\Upload::rule() : 'nullable',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        if ($r = $this->privilegeGuard($request, null)) return $r;
        try {
            $check = User::where('username', $request->username)->withTrashed()->first();
            if (!empty($check) && $check->deleted_at != NULL) {
                if (!auth()->user()->canManage($check, $this->branchId)) {
                    return send_error('You cannot restore this user', null, 403);
                }
                $check->status = 'a';
                $check->deleted_at = NULL;
                $check->update();
                AuditLogger::log('user.restored', $check);
            } else {
                $data = new User();
                $data->code = generateCode('User', 'U', $request->branch_id);
                $data->fill($this->fields($request));
                if ($request->hasFile('image')) {
                    $data->image = imageUpload($request, 'image', 'uploads/user', $data->code . '_' . $this->branchId);
                }
                $data->password = Hash::make($request->password);
                $data->ipAddress = request()->ip();
                $data->save();
                AuditLogger::log('user.created', $data, null, $data->only(['username', 'role', 'branch_id', 'region_id', 'switchable_branches', 'status']));
            }

            return response()->json(['status' => true, 'message' => "User has created successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'       => 'required|integer',
            'name'     => 'required',
            'username' => [
                'required',
                Rule::unique('users')->ignore($request->id)->whereNull('deleted_at'),
            ],
            'password' => ['nullable', Password::defaults()],
            'phone'    => 'required',
            'role'     => 'required',
            'email'    => 'required',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'region_id' => 'nullable|integer|exists:regions,id',
            'image' => $request->hasFile('image') ? \App\Support\Upload::rule() : 'nullable',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        $data = User::find($request->id);
        if (!$data) return send_error('User not found', null, 404);
        if ($r = $this->privilegeGuard($request, $data)) return $r;
        try {
            $before = $data->only(['username', 'role', 'branch_id', 'region_id', 'switchable_branches', 'status']);
            $data->fill($this->fields($request));
            if ($request->hasFile('image')) {
                deleteUploadedFile($data->image);
                $data->image = imageUpload($request, 'image', 'uploads/user', $data->code . '_' . $this->branchId);
            }
            if (!empty($request->password)) {
                $data->password = Hash::make($request->password);
            }
            $data->ipAddress = request()->ip();
            $data->updated_at = Carbon::now();
            $data->update();
            // a password set by someone else, or a deactivated account: sign it out everywhere
            if ((!empty($request->password) && $data->id !== $this->userId) || $data->status === 'p') {
                LoginSessions::revoke('web', $data->id);
            }
            AuditLogger::log('user.updated', $data, $before, $data->only(array_keys($before)) + (!empty($request->password) ? ['password' => 'changed'] : []));

            return response()->json(['status' => true, 'message' => "User has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    // The signed-in user's own profile: name, contact, username, password and photo, never role or branch.
    public function updateProfile(Request $request)
    {
        $user = auth()->user();
        $validator = Validator::make($request->all(), [
            'name'     => 'required',
            'username' => ['required', Rule::unique('users')->ignore($user->id)->whereNull('deleted_at')],
            'phone'    => 'required',
            'email'    => 'nullable|email',
            'password' => ['nullable', Password::defaults()],
            'image' => $request->hasFile('image') ? \App\Support\Upload::rule() : 'nullable',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $user->fill($request->only(['name', 'username', 'phone', 'email']));
            if ($request->hasFile('image')) {
                deleteUploadedFile($user->image);
                $user->image = imageUpload($request, 'image', 'uploads/user', $user->code . '_' . $this->branchId);
            }
            if (!empty($request->password)) {
                $user->password = Hash::make($request->password);
                // a new password signs out every other browser
                LoginSessions::revoke('web', $user->id, null, LoginSessions::currentId($request, 'web'));
                AuditLogger::log('user.password_changed', $user);
            }
            $user->ipAddress = request()->ip();
            $user->update();
            return response()->json(['status' => true, 'message' => "Profile has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    private function fields(Request $request): array
    {
        $fields = $request->only(self::FIELDS);
        if (array_key_exists('is_employee', $fields)) {
            $fields['is_employee'] = filter_var($fields['is_employee'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        foreach (['region_id', 'branch_id'] as $key) {
            if (array_key_exists($key, $fields) && ($fields[$key] === '' || $fields[$key] === 'null')) {
                $fields[$key] = null;
            }
        }
        if (!auth()->user()->isHeadOffice()) {
            // outside head office a user stays in the actor's branch and its reach can't widen
            unset($fields['region_id'], $fields['switchable_branches']);
            $fields['branch_id'] = $this->branchId;
        }
        return $fields;
    }

    // Nobody hands out a role above their own or touches a user ranked higher; only head office
    // moves users between branches or assigns regions / switch lists.
    private function privilegeGuard(Request $request, ?User $target)
    {
        $actor = auth()->user();
        if ($target && !$actor->canManage($target, $this->branchId)) {
            return send_error('You cannot change this user', null, 403);
        }
        if (User::roleRank($request->role) > $actor->rank()) {
            return send_error('You cannot give a role above your own', null, 403);
        }
        if ($target && $target->id === $actor->id && $request->role !== $target->role) {
            return send_error('You cannot change your own role', null, 403);
        }
        if (!$actor->isHeadOffice()) {
            $sent = fn ($key, $current) => $request->has($key) && (string) ($request->input($key) ?? '') !== (string) ($current ?? '');
            if ($sent('region_id', $target?->region_id) || $sent('switchable_branches', $target?->switchable_branches)) {
                return send_error('Only head-office users can assign regions or branch switch lists', null, 403);
            }
            if ($request->filled('branch_id') && (int) $request->branch_id !== (int) $this->branchId) {
                return send_error('Only head-office users can move users to another branch', null, 403);
            }
        }
        return null;
    }

    public function destroy(Request $request)
    {
        $data = User::find($request->id);
        if (!$data) return send_error('User not found', null, 404);
        if ($data->id === $this->userId) return send_error('You cannot delete yourself', null, 403);
        if (!auth()->user()->canManage($data, $this->branchId)) return send_error('You cannot delete this user', null, 403);
        try {
            $data->status = 'd';
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            LoginSessions::revoke('web', $data->id);
            AuditLogger::log('user.deleted', $data, $data->only(['username', 'role', 'branch_id']));
            return response()->json(['status' => true, 'message' => "User has deleted successfully"]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    public function loginAs(Request $request, $id)
    {
        if (!checkAccess('userSwitch')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }

        if ($request->session()->has('impersonator_id')) {
            return redirect('/panel')->with('error', 'Already logged in as another user. Switch back first.');
        }

        $target = User::find($id);
        if (empty($target)) {
            return redirect('/panel')->with('error', 'User not found');
        }
        if ($target->id === $this->userId || !auth()->user()->canManage($target, $this->branchId)) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        AuditLogger::log('user.login_as', $target);

        $request->session()->put('impersonator_id', $this->userId);
        Auth::login($target);

        return redirect('/panel')->with('success', 'Now logged in as ' . $target->name);
    }

    public function switchBack(Request $request)
    {
        $impersonatorId = $request->session()->pull('impersonator_id');
        if (empty($impersonatorId)) {
            return redirect('/panel');
        }

        $original = User::find($impersonatorId);
        if (empty($original)) {
            return redirect('/panel');
        }

        Auth::login($original);

        return redirect('/user')->with('success', 'Switched back to ' . $original->name);
    }
}
