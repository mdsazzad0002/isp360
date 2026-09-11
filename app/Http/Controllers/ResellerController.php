<?php

namespace App\Http\Controllers;

use App\Models\Reseller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ResellerController extends Controller
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
        $resellers = Reseller::with('adUser', 'upUser', 'area')->where('branch_id', $this->branchId);
        if (!empty($request->resellerId)) {
            $resellers = $resellers->where('id', $request->resellerId);
        }
        if (!empty($request->areaId)) {
            $resellers = $resellers->where('area_id', $request->areaId);
        }
        if (!empty($request->search)) {
            $resellers = $resellers->where(function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('phone', 'like', '%' . $request->search . '%')
                    ->orWhere('code', 'like', '%' . $request->search . '%');
            });
        }

        if (!empty($request->forSearch)) {
            $resellers = $resellers->limit(50)->latest()->get();
        } else {
            if (!empty($request->per_page)) {
                $resellers = $resellers->latest()->paginate($request->per_page ?? 20);
            } else {
                $resellers = $resellers->latest()->get();
            }
        }

        return response()->json($resellers);
    }

    public function create()
    {
        if (!checkAccess('reseller')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/Reseller/Entry');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required',
            'phone'    => 'required',
            'username' => [
                'required',
                Rule::unique('resellers')->whereNull('deleted_at'),
            ],
            'password' => 'required',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $data = new Reseller();
            $data->code = generateCode('Reseller', 'RS');
            $dataKey = $request->except('id', 'image', 'password');
            foreach ($dataKey as $key => $value) {
                $data[$key] = $value;
            }
            $data->password = Hash::make($request->password);
            if ($request->hasFile('image')) {
                $data->image = imageUpload($request, 'image', 'uploads/reseller', $data->code . '_' . $this->branchId);
            }
            $data->created_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->save();

            return response()->json(['status' => true, 'message' => "Reseller has created successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required',
            'phone'    => 'required',
            'username' => [
                'required',
                Rule::unique('resellers')->ignore($request->id)->whereNull('deleted_at'),
            ],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $data = Reseller::find($request->id);
            $dataKey = $request->except('id', 'image', 'password');
            foreach ($dataKey as $key => $value) {
                $data[$key] = $value;
            }
            if (!empty($request->password)) {
                $data->password = Hash::make($request->password);
            }
            if ($request->hasFile('image')) {
                deleteUploadedFile($data->image);
                $data->image = imageUpload($request, 'image', 'uploads/reseller', $data->code . '_' . $this->branchId);
            }
            $data->updated_by = $this->userId;
            $data->updated_at = Carbon::now();
            $data->ipAddress = request()->ip();
            $data->update();

            return response()->json(['status' => true, 'message' => "Reseller has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        try {
            $data = Reseller::find($request->id);
            deleteUploadedFile($data->image);
            $data->status = 'd';
            $data->deleted_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            return response()->json(['status' => true, 'message' => "Reseller has deleted successfully"]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }
}
