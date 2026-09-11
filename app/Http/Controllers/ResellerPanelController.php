<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class ResellerPanelController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:reseller');
    }

    public function dashboard()
    {
        $reseller = Auth::guard('reseller')->user();
        $customers = Customer::where('reseller_id', $reseller->id)->latest()->get();

        return \Inertia\Inertia::render('Reseller/Dashboard', [
            'customers' => $customers,
            'customerCount' => $customers->count(),
            'totalDue' => $customers->sum('previous_due'),
        ]);
    }

    public function profile()
    {
        return \Inertia\Inertia::render('Reseller/Profile', [
            'reseller' => Auth::guard('reseller')->user(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $reseller = Auth::guard('reseller')->user();

        $validator = Validator::make($request->all(), [
            'name'  => 'required',
            'phone' => 'required',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);

        try {
            $reseller->name = $request->name;
            $reseller->email = $request->email;
            $reseller->phone = $request->phone;
            $reseller->address = $request->address;
            if (!empty($request->password)) {
                $reseller->password = Hash::make($request->password);
            }
            $reseller->update();

            return response()->json(['status' => true, 'message' => "Profile has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function logout()
    {
        try {
            Auth::guard('reseller')->logout();
            Session::forget('portal');
            Session::flash('success', 'Logout successfully');
            return redirect('/');
        } catch (\Throwable $e) {
            return send_error('Something went wrong', $e->getMessage());
        }
    }
}
