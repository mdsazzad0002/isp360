<?php

namespace App\Http\Controllers;

use App\Models\Area;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class AreaController extends Controller
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
        $unit = Area::with('adUser', 'upUser', 'deUser', 'zone')->withCount(['boxes', 'customers'])->latest()->get();
        return response()->json($unit);
    }

    public function create()
    {
        if (!checkAccess('area')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/SimpleCrud', [
            'title' => 'Area Entry',
            'slug' => 'area',
            'deletedRecordUri' => checkAccess('areaRestore') ? '/deleted-area-record' : '',
        ]);
    }

    public function store(Request $request)
    {
        if (!checkAccess('area')) {
            return send_error('You are not authorized for this action', null, 403);
        }
        $branchId = $this->branchId;
        $validator = Validator::make($request->all(), [
            'name'     => [
                'required',
                Rule::unique('areas')
                    ->where(function ($query) use ($branchId) {
                        $query->where('branch_id', $branchId);
                    })
                    ->whereNull('deleted_at'),
            ],
            'zone_id' => 'nullable|integer|exists:zones,id',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $check = Area::where('name', $request->name)->where('branch_id', $this->branchId)->withTrashed()->first();
            if (!empty($check) && $check->deleted_at != NULL) {
                $check->status = 'a';
                $check->deleted_by = NULL;
                $check->deleted_at = NULL;
                if ($request->filled('zone_id')) {
                    $check->zone_id = $request->zone_id;
                }
                $check->update();
                $data = $check;
            } else {
                $data = new Area();
                $dataKey = $request->except('id');
                foreach ($dataKey as $key => $value) {
                    $data[$key] = $value;
                }
                $data->created_by = $this->userId;
                $data->branch_id  = $this->branchId;
                $data->ipAddress  = request()->ip();
                $data->save();
            }

            return response()->json(['status' => true, 'message' => "Area has created successfully", 'id' => $data->id]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(Request $request)
    {
        if (!checkAccess('area')) {
            return send_error('You are not authorized for this action', null, 403);
        }
        $branchId = $this->branchId;
        $validator = Validator::make($request->all(), [
            'name'     => [
                'required',
                Rule::unique('areas')
                    ->ignore($request->id)
                    ->where(function ($query) use ($branchId) {
                        $query->where('branch_id', $branchId);
                    })
                    ->whereNull('deleted_at'),
            ],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $data = Area::find($request->id);
            $dataKey = $request->except('id');
            foreach ($dataKey as $key => $value) {
                $data[$key] = $value;
            }
            $data->updated_at = Carbon::now();
            $data->updated_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->update();

            return response()->json(['status' => true, 'message' => "Area has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        if (!checkAccess('area')) {
            return send_error('You are not authorized for this action', null, 403);
        }
        try {
            $data = Area::find($request->id);
            if ($data && ($data->boxes()->exists() || \App\Models\Customer::where('area_id', $data->id)->exists())) {
                return send_error('This area still has boxes or customers. Move them first.', null, 422);
            }
            $data->deleted_by = $this->userId;
            $data->status = 'd';
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            return response()->json(['status' => true, 'message' => "Area has deleted successfully"]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    public function deletedRecord()
    {
        if (!checkAccess('areaRestore')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/SimpleCrudDeletedRecord', [
            'title' => 'Deleted Area Record',
            'slug' => 'area',
        ]);
    }

    public function getDeleted(Request $request)
    {
        $areas = Area::onlyTrashed()->with('deUser')->latest('deleted_at')->get()->map(function ($area) {
            $area->deleted_by_name = $area->deUser->name ?? 'NA';
            return $area;
        });
        return response()->json($areas);
    }

    public function restore(Request $request)
    {
        if (!checkAccess('areaRestore')) {
            return send_error('You are not authorized to restore area', null, 403);
        }
        $area = Area::onlyTrashed()->find($request->id);
        if (empty($area)) {
            return send_error('Deleted area not found', null, 404);
        }
        try {
            $area->restore();
            $area->status = 'a';
            $area->deleted_by = null;
            $area->ipAddress = request()->ip();
            $area->update();

            return response()->json(['status' => true, 'message' => "Area has restored successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }
}
