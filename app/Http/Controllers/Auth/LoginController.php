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

    // login-page tab => guard
    protected $tabs = ['admin' => 'web', 'reseller' => 'reseller', 'customer' => 'customer'];

    protected $notFound = [
        'web' => 'No admin account found with this username',
        'reseller' => 'No reseller account found with this username',
        'customer' => 'No customer account found with this username',
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
            "portal" => "nullable|in:admin,reseller,customer",
        ], ['username.required' => 'Username is required', 'password.required' => 'Password is required']);

        try {
            $only = $request->portal ? $this->tabs[$request->portal] : null;
            [$guard, $account] = $this->findAccount($request->username, $only);
            if (empty($account)) {
                return send_error("Unauthorized", ['username' => $only ? $this->notFound[$only] : 'User not found'], 401);
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

    // find which of the 3 portals (user / reseller / customer) a username (or email) belongs to;
    // $only limits the search to one guard when the user picked a tab on the login page
    protected function findAccount($username, $only = null)
    {
        $column = array_key_first(credentials($username, ''));
        foreach ($this->portals as $guard => [$model, $redirect]) {
            if ($only && $guard !== $only) {
                continue;
            }
            $account = $model::where($column, $username)->first();
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
