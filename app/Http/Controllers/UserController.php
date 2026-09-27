<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required',
            'username' => [
                'required',
                Rule::unique('users')->whereNull('deleted_at'),
            ],
            'password' => 'required',
            'phone'    => 'required',
            'role'     => 'required',
            'email'    => 'required',
            'region_id' => 'nullable|integer|exists:regions,id',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        if ($r = $this->regionGuard($request)) return $r;
        try {
            $check = User::where('username', $request->username)->withTrashed()->first();
            if (!empty($check) && $check->deleted_at != NULL) {
                $check->status = 'a';
                $check->deleted_at = NULL;
                $check->update();
            } else {
                $data = new User();
                $data->code = generateCode('User', 'U', $request->branch_id);
                $dataKey = $request->except('id', 'image');
                foreach ($dataKey as $key => $value) {
                    $data[$key] = $value;
                }
                if ($request->hasFile('image')) {
                    $data->image = imageUpload($request, 'image', 'uploads/user', $data->code . '_' . $this->branchId);
                }
                $data->password = Hash::make($request->password);
                // $data->branch_id = $this->branchId;
                $data->ipAddress = request()->ip();
                $data->save();
            }

            return response()->json(['status' => true, 'message' => "User has created successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required',
            'username' => [
                'required',
                Rule::unique('users')->ignore($request->id)->whereNull('deleted_at'),
            ],
            'phone'    => 'required',
            'role'     => 'required',
            'email'    => 'required',
            'region_id' => 'nullable|integer|exists:regions,id',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        if ($r = $this->regionGuard($request)) return $r;
        try {
            $data = User::find($request->id);
            $dataKey = $request->except('id', 'image', 'password');
            foreach ($dataKey as $key => $value) {
                $data[$key] = $value;
            }
            if ($request->hasFile('image')) {
                deleteUploadedFile($data->image);
                $data->image = imageUpload($request, 'image', 'uploads/user', $data->code . '_' . $this->branchId);
            }
            if (!empty($request->password)) {
                $data->password = Hash::make($request->password);
            }
            // $data->branch_id = $this->branchId;
            $data->ipAddress = request()->ip();
            $data->updated_at = Carbon::now();
            $data->update();

            return response()->json(['status' => true, 'message' => "User has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    // Only head-office users hand out regions, so a regional manager cannot widen anyone's reach.
    private function regionGuard(Request $request)
    {
        if (!$request->has('region_id') || auth()->user()->seesAllBranches()) {
            return null;
        }
        $current = $request->id ? User::find($request->id)?->region_id : null;
        return (int) $request->region_id === (int) $current ? null : send_error('Only head-office users can assign regions', null, 403);
    }

    public function destroy(Request $request)
    {
        try {
            $data = User::find($request->id);
            $data->status = 'd';
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
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
