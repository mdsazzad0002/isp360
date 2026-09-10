<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Models\Branch;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class LoginController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    public function showLoginForm()
    {
        return \Inertia\Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $this->validate($request, [
            "username" => "required",
            "password" => "required",
        ], ['username.required' => 'Username is required', 'password.required' => 'Password is required']);

        try {
            $user = User::where('username', $request->username)->first();
            if (empty($user)) {
                return send_error("Unauthorized", ['username' => 'User not found'], 401);
            }
            if ($user->status == 'p') {
                return send_error("Unauthorized", ['username' => 'User Deactive'], 401);
            }

            if (Auth::guard()->attempt(credentials($request->username, $request->password))) {
                $this->branchset();
                Session::flash('success', 'Login successfully');
                return response()->json(['status' => true, 'message' => "Successfully Login"]);
            } else {
                return send_error("Unauthorized", ['username' => 'Username or password not valid'], 401);
            }
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    // branch set on session
    protected function branchset()
    {
        $user = Auth::user();

        $module = "dashboard";
        $branch = Branch::find($user->branch_id);
        Session::put('branch', $branch);
        Session::put('module', $module);

        // UserActivity::create([
        //     'user_id' => $user->id,
        //     'ip_address' => request()->ip(),
        //     'login_time' => Carbon::now(),
        //     'branch_id' => $user->branch_id,
        // ]);
        // return true;
    }
}
