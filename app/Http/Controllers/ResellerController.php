<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Reseller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Services\Isp\AuditLogger;
use Illuminate\Support\Facades\Validator;

class ResellerController extends Controller
{
    use Concerns\BranchScoped;

    // fields a request may set (audit C2: never every request field)
    private const FIELDS = ['name', 'phone', 'email', 'username', 'address', 'area_id', 'status'];

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
        normalizePhone($request);
        $validator = Validator::make($request->all(), [
            'name'     => 'required',
            'phone'    => ['required', new \App\Rules\PhoneNumber],
            'username' => [
                'required',
                Rule::unique('resellers')->whereNull('deleted_at'),
            ],
            'password' => ['required', \Illuminate\Validation\Rules\Password::defaults()],
            'image' => $request->hasFile('image') ? \App\Support\Upload::rule() : 'nullable',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $data = new Reseller();
            $data->code = generateCode('Reseller', 'RS');
            [$fields, $error] = $this->branchFields($request, self::FIELDS);
            if ($error) return $error;
            $data->forceFill($fields);
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
        normalizePhone($request);
        $validator = Validator::make($request->all(), [
            'name'     => 'required',
            'phone'    => ['required', new \App\Rules\PhoneNumber],
            'password' => ['nullable', \Illuminate\Validation\Rules\Password::defaults()],
            'username' => [
                'required',
                Rule::unique('resellers')->ignore($request->id)->whereNull('deleted_at'),
            ],
            'image' => $request->hasFile('image') ? \App\Support\Upload::rule() : 'nullable',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $data = $this->findInBranch(Reseller::class, $request->id);
            if (!$data) return send_error('Record not found', null, 404);
            [$fields, $error] = $this->branchFields($request, self::FIELDS);
            if ($error) return $error;
            $data->forceFill($fields);
            if (!empty($request->password)) {
                $data->password = Hash::make($request->password);
                \App\Support\LoginSessions::revoke('reseller', $data->id); // set by staff: sign the reseller out everywhere
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
            $data = $this->findInBranch(Reseller::class, $request->id);
            if (!$data) return send_error('Record not found', null, 404);
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

    public function exportExcel(Request $request)
    {
        if (!checkAccess('reseller')) {
            abort(403);
        }

        $resellers = Reseller::with('area')
            ->where('branch_id', $this->branchId)
            ->when($request->search, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', "%$term%")->orWhere('phone', 'like', "%$term%")->orWhere('code', 'like', "%$term%");
                });
            })
            ->latest()
            ->get();

        $customerCounts = Customer::whereIn('reseller_id', $resellers->pluck('id'))->groupBy('reseller_id')->selectRaw('reseller_id, count(*) as total')->pluck('total', 'reseller_id');
        $packageCounts = Package::whereIn('reseller_id', $resellers->pluck('id'))->groupBy('reseller_id')->selectRaw('reseller_id, count(*) as total')->pluck('total', 'reseller_id');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Reseller List');

        // Column names match the import template, so an export can be edited and re-imported
        // elsewhere. Passwords are never exported.
        $headers = ['sl', 'code', 'name', 'phone', 'username', 'email', 'area', 'address', 'status', 'customers', 'packages', 'created_at'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        $row = 2;
        foreach ($resellers as $index => $item) {
            $sheet->fromArray([
                $index + 1, $item->code, $item->name, $item->phone, $item->username, $item->email,
                optional($item->area)->name, $item->address, $item->status === 'a' ? 'active' : 'inactive',
                (int) ($customerCounts[$item->id] ?? 0), (int) ($packageCounts[$item->id] ?? 0),
                optional($item->created_at)->format('Y-m-d'),
            ], null, 'A' . $row);
            // keep phone numbers as text so Excel doesn't drop the leading zero
            $sheet->setCellValueExplicit('D' . $row, (string) $item->phone, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $row++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = 'reseller-list-' . now()->format('Y-m-d') . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // Imports one batch of rows sent by the import panel. Rows with a blank password get a
    // generated one, returned in `credentials` so the admin can hand them out.
    public function importBatch(Request $request)
    {
        if (!checkAccess('reseller')) {
            return response()->json(['status' => false, 'message' => 'Forbidden'], 403);
        }

        $rows = $request->input('rows', []);
        if (!is_array($rows) || count($rows) > 200) {
            return send_error('Send at most 200 rows per batch', null, 422);
        }
        $offset = (int) $request->input('offset', 0);
        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $credentials = [];

        $areas = Area::where('branch_id', $this->branchId)->get(['id', 'name'])
            ->keyBy(fn ($a) => mb_strtolower(trim($a->name)));

        foreach ($rows as $i => $row) {
            $row = array_change_key_case((array) $row, CASE_LOWER);
            $rowLabel = 'Row ' . ($offset + $i + 1);

            $name = trim((string) ($row['name'] ?? ''));
            $phone = trim((string) ($row['phone'] ?? $row['mobile'] ?? ''));
            $username = trim((string) ($row['username'] ?? ''));
            $password = trim((string) ($row['password'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));

            if ($name === '' || $phone === '' || $username === '') {
                $skipped++;
                $errors[] = "$rowLabel: name, phone and username are required";
                continue;
            }
            if (! ($normalized = \App\Support\Phone::normalize($phone))) {
                $skipped++;
                $errors[] = "$rowLabel: phone \"$phone\" is not a valid number";
                continue;
            }
            $phone = $normalized;
            if (!preg_match('/^[A-Za-z0-9._@-]{3,50}$/', $username)) {
                $skipped++;
                $errors[] = "$rowLabel: username \"$username\" may only contain letters, numbers and . _ @ - (3-50 characters)";
                continue;
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $errors[] = "$rowLabel: email \"$email\" is not valid";
                continue;
            }
            if ($password !== '' && strlen($password) < 6) {
                $skipped++;
                $errors[] = "$rowLabel: password must be at least 6 characters (or leave it blank to auto-generate)";
                continue;
            }
            if (Reseller::where('username', $username)->exists()) {
                $skipped++;
                $errors[] = "$rowLabel: username \"$username\" already exists";
                continue;
            }
            if (Reseller::where('branch_id', $this->branchId)->where('phone', $phone)->exists()) {
                $skipped++;
                $errors[] = "$rowLabel: phone \"$phone\" already exists";
                continue;
            }

            $areaName = trim((string) ($row['area'] ?? ''));
            $area = $areaName !== '' ? $areas->get(mb_strtolower($areaName)) : null;
            if ($areaName !== '' && !$area) {
                $errors[] = "$rowLabel: area \"$areaName\" not found — imported without an area";
            }

            $status = strtolower(trim((string) ($row['status'] ?? '')));
            $generated = $password === '';
            if ($generated) {
                $password = Str::password(10, symbols: false);
            }

            try {
                $reseller = new Reseller();
                $reseller->code = generateCode('Reseller', 'RS');
                $reseller->name = $name;
                $reseller->phone = mb_substr($phone, 0, 15);
                $reseller->email = $email ?: null;
                $reseller->username = $username;
                $reseller->password = Hash::make($password);
                $reseller->address = trim((string) ($row['address'] ?? '')) ?: null;
                $reseller->area_id = $area?->id;
                $reseller->status = in_array($status, ['inactive', 'deactive', 'p'], true) ? 'p' : 'a';
                $reseller->created_by = $this->userId;
                $reseller->ipAddress = request()->ip();
                $reseller->branch_id = $this->branchId;
                $reseller->save();
            } catch (\Throwable $th) {
                $skipped++;
                $errors[] = "$rowLabel: could not be saved";
                continue;
            }

            if ($generated) {
                $credentials[] = ['code' => $reseller->code, 'name' => $name, 'username' => $username, 'password' => $password];
            }
            $inserted++;
        }

        return response()->json(['status' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors, 'credentials' => $credentials]);
    }

    // Opens the reseller portal as this reseller (no password needed). The admin's own
    // session stays logged in; the reseller portal shows a banner to go back.
    public function loginAs(Request $request, $id)
    {
        if (!checkAccess('resellerLoginAs')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }

        $reseller = Reseller::where('branch_id', $this->branchId)->find($id);
        if (empty($reseller)) {
            return redirect('/reseller')->with('error', 'Reseller not found');
        }

        Auth::guard('reseller')->login($reseller);
        $request->session()->put('reseller_impersonator_id', $this->userId);
        AuditLogger::log('reseller.login_as', $reseller, null, ['code' => $reseller->code, 'name' => $reseller->name]);

        return redirect('/reseller/dashboard');
    }
}
