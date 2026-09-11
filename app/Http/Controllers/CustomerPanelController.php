<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class CustomerPanelController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:customer');
    }

    public function dashboard()
    {
        return \Inertia\Inertia::render('CustomerPortal/Dashboard', [
            'customer' => Auth::guard('customer')->user(),
        ]);
    }

    public function profile()
    {
        return \Inertia\Inertia::render('CustomerPortal/Profile', [
            'customer' => Auth::guard('customer')->user(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $customer = Auth::guard('customer')->user();

        $validator = Validator::make($request->all(), [
            'name'  => 'required',
            'phone' => 'required',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);

        try {
            $customer->name = $request->name;
            $customer->email = $request->email;
            $customer->phone = $request->phone;
            $customer->address = $request->address;
            if (!empty($request->password)) {
                $customer->password = Hash::make($request->password);
            }
            $customer->update();

            return response()->json(['status' => true, 'message' => "Profile has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function logout()
    {
        try {
            Auth::guard('customer')->logout();
            Session::forget('portal');
            Session::flash('success', 'Logout successfully');
            return redirect('/');
        } catch (\Throwable $e) {
            return send_error('Something went wrong', $e->getMessage());
        }
    }
}
