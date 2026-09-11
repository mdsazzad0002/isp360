<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Models\Branch;
use App\Models\Reseller;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class LoginController extends Controller
{
    // guard => [model class, redirect path once logged in]
    protected $portals = [
        'web' => [User::class, '/panel/dashboard'],
        'reseller' => [Reseller::class, '/reseller/dashboard'],
        'customer' => [Customer::class, '/customer-portal/dashboard'],
    ];

    public function __construct()
    {
        $this->middleware('guest:web,reseller,customer')->except('logout');
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
            [$guard, $account] = $this->findAccount($request->username);
            if (empty($account)) {
                return send_error("Unauthorized", ['username' => 'User not found'], 401);
            }
            if ($account->status == 'p') {
                return send_error("Unauthorized", ['username' => 'User Deactive'], 401);
            }

            if (Auth::guard($guard)->attempt(credentials($request->username, $request->password))) {
                Session::put('portal', $guard);
                if ($guard === 'web') {
                    $this->branchset();
                }
                Session::flash('success', 'Login successfully');
                return response()->json(['status' => true, 'message' => "Successfully Login", 'redirect' => $this->portals[$guard][1]]);
            } else {
                return send_error("Unauthorized", ['username' => 'Username or password not valid'], 401);
            }
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    // find which of the 3 portals (user / reseller / customer) a username belongs to
    protected function findAccount($username)
    {
        foreach ($this->portals as $guard => [$model, $redirect]) {
            $account = $model::where('username', $username)->first();
            if ($account) {
                return [$guard, $account];
            }
        }

        return [null, null];
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
