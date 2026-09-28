<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    use Concerns\BranchScoped;

    // fields a request may set (audit C2: never every request field)
    private const FIELDS = ['name', 'details'];

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
        $company = Company::with('adUser', 'upUser', 'deUser')->latest()->get();
        return response()->json($company);
    }

    public function create()
    {
        if (!checkAccess('company')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/SimpleCrud', [
            'title' => 'Company Entry',
            'slug' => 'company',
            'deletedRecordUri' => checkAccess('companyRestore') ? '/deleted-company-record' : '',
        ]);
    }

    public function store(Request $request)
    {
        $branchId = $this->branchId;
        $validator = Validator::make($request->all(), [
            'name'     => [
                'required',
                Rule::unique('companies')
                    ->where(function ($query) use ($branchId) {
                        $query->where('branch_id', $branchId);
                    })
                    ->whereNull('deleted_at'),
            ],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $check = Company::where('name', $request->name)->where('branch_id', $this->branchId)->withTrashed()->first();
            if (!empty($check) && $check->deleted_at != NULL) {
                $check->status = 'a';
                $check->deleted_by = NULL;
                $check->deleted_at = NULL;
                $check->update();
            } else {
                $data = new Company();
                [$fields, $error] = $this->branchFields($request, self::FIELDS);
                if ($error) return $error;
                $data->forceFill($fields);
                $data->created_by = $this->userId;
                $data->branch_id  = $this->branchId;
                $data->ipAddress  = request()->ip();
                $data->save();
            }

            return response()->json(['status' => true, 'message' => "Company has created successfully"]);
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
                Rule::unique('companies')
                    ->ignore($request->id)
                    ->where(function ($query) use ($branchId) {
                        $query->where('branch_id', $branchId);
                    })
                    ->whereNull('deleted_at'),
            ],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $data = $this->findInBranch(Company::class, $request->id);
            if (!$data) return send_error('Record not found', null, 404);
            [$fields, $error] = $this->branchFields($request, self::FIELDS);
            if ($error) return $error;
            $data->forceFill($fields);
            $data->updated_at = Carbon::now();
            $data->updated_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->update();

            return response()->json(['status' => true, 'message' => "Company has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        try {
            $data = $this->findInBranch(Company::class, $request->id);
            if (!$data) return send_error('Record not found', null, 404);
            $data->deleted_by = $this->userId;
            $data->status = 'd';
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            return response()->json(['status' => true, 'message' => "Company has deleted successfully"]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    public function deletedRecord()
    {
        if (!checkAccess('companyRestore')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/SimpleCrudDeletedRecord', [
            'title' => 'Deleted Company Record',
            'slug' => 'company',
        ]);
    }

    public function getDeleted(Request $request)
    {
        $companies = Company::onlyTrashed()->with('deUser')->where('branch_id', $this->branchId)->latest('deleted_at')->get()->map(function ($company) {
            $company->deleted_by_name = $company->deUser->name ?? 'NA';
            return $company;
        });
        return response()->json($companies);
    }

    public function restore(Request $request)
    {
        if (!checkAccess('companyRestore')) {
            return send_error('You are not authorized to restore company', null, 403);
        }
        $company = Company::onlyTrashed()->where('branch_id', $this->branchId)->find($request->id);
        if (empty($company)) {
            return send_error('Deleted company not found', null, 404);
        }
        try {
            $company->restore();
            $company->status = 'a';
            $company->deleted_by = null;
            $company->ipAddress = request()->ip();
            $company->update();

            return response()->json(['status' => true, 'message' => "Company has restored successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }
}
