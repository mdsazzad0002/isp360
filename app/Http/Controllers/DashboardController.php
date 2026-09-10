<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;

class DashboardController extends Controller
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

    public function index()
    {
        Session::forget('panel');
        Session::put('panel', 'dashboard');
        return Inertia::render('Dashboard');
    }

    public function panel($panel)
    {
        Session::forget('panel');
        Session::put('panel', $panel);
        return Inertia::render('Dashboard');
    }

    // admin logout
    public function Logout()
    {
        try {
            Auth::guard('web')->logout();
            Session::forget(['branch', 'panel']);
            Session::flash('success', 'Logout successfully');
            return redirect('/');
        } catch (\Throwable $e) {
            return send_error('Something went wrong', $e->getMessage());
        }
    }

    // branch set on session
    protected function branchset($id)
    {
        $branch = Branch::find($id);
        Session::put('branch', $branch);
        return back();
    }

    public function companyProfile()
    {
        return \Inertia\Inertia::render('Control/CompanyProfile', [
            'company' => CompanyProfile::first(),
        ]);
    }

    public function getcompanyProfile()
    {
        return response()->json(CompanyProfile::first());
    }

    public function updatecompanyProfile(Request $request)
    {
        $this->validate($request, [
            'name' => 'required',
            'title' => 'required',
            'phone' => 'required'
        ]);

        try {
            $data = CompanyProfile::first();
            if ($request->logo == 'null') {
                deleteUploadedFile($data->logo);
                $data->logo = NULL;
            }
            if ($request->favicon == 'null') {
                deleteUploadedFile($data->favicon);
                $data->favicon = NULL;
            }
            $dataKeys = $request->except('id', 'logo', 'favicon');
            foreach ($dataKeys as $key => $value) {
                $data[$key] = $value;
            }

            if ($request->hasFile('logo')) {
                deleteUploadedFile($data->logo);
                $data->logo = imageUpload($request, 'logo', 'uploads/logo', 'logo');
            }
            if ($request->favicon == NULL) {
                deleteUploadedFile($data->favicon);
                $data->favicon = NULL;
            }
            if ($request->hasFile('favicon')) {
                deleteUploadedFile($data->favicon);
                $data->favicon = imageUpload($request, 'favicon', 'uploads/favicon', 'favicon');
            }

            // Regenerate the favicon/PWA icon set (16/32/180/192/512) from
            // whichever image was actually uploaded this request — a manual
            // favicon upload wins over the logo so an admin can still pick a
            // distinct icon, but a plain logo update is enough on its own to
            // keep every icon size in sync without a separate favicon upload.
            $iconSource = $request->hasFile('favicon') ? $data->favicon : ($request->hasFile('logo') ? $data->logo : null);
            if ($iconSource) {
                \App\Support\FaviconGenerator::delete($data->favicon_sizes);
                $data->favicon_sizes = \App\Support\FaviconGenerator::generate(public_path($iconSource));
            }

            $data->updated_by = $this->userId;
            $data->updated_at = Carbon::now();
            $data->ipAddress = request()->ip();
            $data->update();
            clearCompanyCache();

            return response()->json(['status' => true, 'message' => 'Company profile update successfully']);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => 'Something went wrong! ' . $th->getMessage()]);
        }
    }

    public function branchManage()
    {
        return \Inertia\Inertia::render('Control/BranchManage', [
            'settings' => CompanyProfile::first(),
        ]);
    }

    public function updateBranchManage(Request $request)
    {
        $this->validate($request, [
            'multi_branch_status' => 'required|in:active,inactive',
        ]);

        $data = CompanyProfile::first();

        // Multi-branch is meaningless with a single branch on record — block
        // turning it on until a second branch actually exists.
        if ($request->multi_branch_status === 'active' && $data->multi_branch_status !== 'active') {
            if (Branch::count() <= 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Create at least one more branch before enabling multi-branch.',
                ], 422);
            }
        }

        try {
            $data->multi_branch_status = $request->multi_branch_status;
            $data->updated_by = $this->userId;
            $data->updated_at = Carbon::now();
            $data->ipAddress = request()->ip();
            $data->update();
            clearCompanyCache();

            return response()->json(['status' => true, 'message' => 'Branch management settings updated successfully']);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => 'Something went wrong! ' . $th->getMessage()]);
        }
    }

    public function switchBranch(Request $request)
    {
        $this->validate($request, [
            'branch_id' => 'required|exists:branches,id',
        ]);

        $company = CompanyProfile::first();
        if (!$company || $company->multi_branch_status !== 'active') {
            return response()->json(['status' => false, 'message' => 'Multi branch is not enabled']);
        }

        if (!in_array(auth()->user()->role, ['Superadmin', 'admin'])) {
            return response()->json(['status' => false, 'message' => 'You are not allowed to switch branch']);
        }

        $allowedBranchIds = auth()->user()->allowedBranchIds();
        if ($allowedBranchIds !== null && !in_array((int) $request->branch_id, $allowedBranchIds, true)) {
            return response()->json(['status' => false, 'message' => 'You are not allowed to switch to this branch']);
        }

        $branch = Branch::findOrFail($request->branch_id);
        Session::put('branch', $branch);

        return response()->json(['status' => true, 'message' => 'Branch switched successfully']);
    }

    public function globalSearch(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['customers' => []]);
        }

        $customers = checkAccess('customer')
            ? Customer::where('branch_id', $this->branchId)
                ->where(function ($query) use ($q) {
                    $query->where('name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%");
                })
                ->limit(5)->get(['id', 'code', 'name', 'phone'])
            : [];

        return response()->json([
            'customers' => $customers,
        ]);
    }

    public function getHeaderInfo()
    {
        return \Inertia\Inertia::render('HeaderInfo');
    }
}
