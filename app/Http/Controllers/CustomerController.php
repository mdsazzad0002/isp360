<?php

namespace App\Http\Controllers;

use App\Support\Money;
use App\Models\Area;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Services\Isp\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CustomerController extends Controller
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
        $customers = Customer::with('adUser', 'upUser', 'area', 'reseller')->where('branch_id', $this->branchId);
        if (!empty($request->customerId)) {
            $customers = $customers->where('id', $request->customerId);
        }
        if (!empty($request->customer_type)) {
            $customers = $customers->where('type', $request->customer_type);
        }
        if (!empty($request->areaId)) {
            $customers = $customers->where('area_id', $request->areaId);
        }
        if (!empty($request->search)) {
            $customers = $customers->where(function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('phone', 'like', '%' . $request->search . '%')
                    ->orWhere('code', 'like', '%' . $request->search . '%');
            });
        }
        
        if (!empty($request->forSearch)) {
            $customers = $customers->limit(50)->latest()->get();
        } else {
            if (!empty($request->per_page)) {
                $customers = $customers->latest()->paginate($request->per_page ?? 20);
            } else {
                $customers = $customers->latest()->get();
            }
        }

        if (empty($request->per_page)) {
            $customers = $customers->map(function ($item) {
                $item->display_name = $item->name . ' - ' . $item->phone . ' - ' . $item->code;
                return $item;
            });
        }

        return response()->json($customers);
    }

    public function create()
    {
        if (!checkAccess('customer')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/Customer/Entry');
    }

    public function store(Request $request)
    {
        $branchId = $this->branchId;
        normalizePhone($request);
        $validator = Validator::make($request->all(), [
            'name'     => 'required',
            'phone' => [
                'required',
                new \App\Rules\PhoneNumber,
                Rule::unique('customers')
                    ->where(function ($query) use ($branchId) {
                        $query->where('branch_id', $branchId);
                    })
                    ->whereNull('deleted_at'),
            ],
            'username' => [
                'nullable',
                Rule::unique('customers')->whereNull('deleted_at'),
            ],
            // required where the country pack says so (IN PIN code, US ZIP, UK postcode...)
            'postcode' => (\App\Support\CountryPack::current()['address']['postcode_required'] ? 'required' : 'nullable') . '|max:20',
            'city' => 'nullable|max:100',
            'language' => 'nullable|in:en,bn,hi,ar',
            'state' => 'nullable|max:100',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $check = Customer::where('phone', $request->phone)->withTrashed()->first();
            if (!empty($check) && $check->deleted_at != NULL) {
                $check->status = 'a';
                $check->deleted_at = NULL;
                $check->update();
            } else {
                $data = new Customer();
                $data->code = generateCode('Customer', 'CI');
                $dataKey = $request->except('id', 'image', 'password', 'ledger_balance');
                foreach ($dataKey as $key => $value) {
                    $data[$key] = $value;
                }
                if (!empty($request->password)) {
                    $data->password = Hash::make($request->password);
                }
                if ($request->hasFile('image')) {
                    $data->image = imageUpload($request, 'image', 'uploads/customer', $data->code . '_' . $this->branchId);
                }
                $data->created_by = $this->userId;
                $data->ipAddress = request()->ip();
                $data->branch_id = $this->branchId;
                $data->save();

                // An opening due becomes an "opening balance" invoice so payments can settle it
                // like any other bill and the ledger stays consistent with invoices.
                if ((float) $data->previous_due > 0) {
                    \App\Services\Isp\BillingService::createManual($data, ['ledger_type' => 'opening'], [[
                        'description' => 'Opening balance (previous due)',
                        'unit_price' => (float) $data->previous_due,
                        'quantity' => 1,
                    ]]);
                }
            }

            return response()->json(['status' => true, 'message' => "Customer has created successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(Request $request)
    {
        $branchId = $this->branchId;
        normalizePhone($request);
        $validator = Validator::make($request->all(), [
            'name'     => 'required',
            'phone' => [
                'required',
                new \App\Rules\PhoneNumber,
                Rule::unique('customers')
                    ->ignore($request->id)
                    ->where(function ($query) use ($branchId) {
                        $query->where('branch_id', $branchId);
                    })
                    ->whereNull('deleted_at'),
            ],
            'username' => [
                'nullable',
                Rule::unique('customers')->ignore($request->id)->whereNull('deleted_at'),
            ],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);
        try {
            $data = Customer::find($request->id);
            $dataKey = $request->except('id', 'image', 'password', 'ledger_balance');
            foreach ($dataKey as $key => $value) {
                $data[$key] = $value;
            }
            if (!empty($request->password)) {
                $data->password = Hash::make($request->password);
            }
            if ($request->hasFile('image')) {
                deleteUploadedFile($data->image);
                $data->image = imageUpload($request, 'image', 'uploads/customer', $data->code . '_' . $this->branchId);
            }
            $data->updated_by = $this->userId;
            $data->updated_at = Carbon::now();
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->update();

            return response()->json(['status' => true, 'message' => "Customer has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        try {
            $data = Customer::find($request->id);
            deleteUploadedFile($data->image);
            $data->status = 'd';
            $data->deleted_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            return response()->json(['status' => true, 'message' => "Customer has deleted successfully"]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    public function deletedCustomerRecord()
    {
        if (!checkAccess('customerRestore')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/Customer/DeletedRecord');
    }

    public function getDeletedCustomer(Request $request)
    {
        $customers = Customer::onlyTrashed()->with('deUser', 'area')->where('branch_id', $this->branchId);
        if (!empty($request->search)) {
            $customers = $customers->where(function ($query) use ($request) {
                $query->where('code', 'like', '%' . $request->search . '%')
                    ->orWhere('name', 'like', '%' . $request->search . '%')
                    ->orWhere('phone', 'like', '%' . $request->search . '%');
            });
        }
        $customers = $customers->latest('deleted_at')->get()->map(function ($customer) {
            $customer->area_name = $customer->area->name ?? 'NA';
            $customer->deleted_by_name = $customer->deUser->name ?? 'NA';
            return $customer;
        });
        return response()->json($customers);
    }

    public function restoreCustomer(Request $request)
    {
        if (!checkAccess('customerRestore')) {
            return send_error('You are not authorized to restore customer', null, 403);
        }
        $customer = Customer::onlyTrashed()->where('branch_id', $this->branchId)->find($request->id);
        if (empty($customer)) {
            return send_error('Deleted customer not found', null, 404);
        }
        try {
            $customer->restore();
            $customer->status = 'a';
            $customer->deleted_by = null;
            $customer->ipAddress = request()->ip();
            $customer->update();

            return response()->json(['status' => true, 'message' => "Customer has restored successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    private function filteredCustomerQuery(Request $request)
    {
        return Customer::with('area')
            ->where('branch_id', $this->branchId)
            ->when($request->customer_type, fn ($q) => $q->where('type', $request->customer_type))
            ->when($request->areaId, fn ($q) => $q->where('area_id', $request->areaId))
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($qq) use ($request) {
                    $qq->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('phone', 'like', '%' . $request->search . '%')
                        ->orWhere('code', 'like', '%' . $request->search . '%');
                });
            });
    }

    public function exportExcel(Request $request)
    {
        if (!checkAccess('customer')) {
            abort(403);
        }

        $customers = $this->filteredCustomerQuery($request)->latest()->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Customer List');

        $headers = ['Sl', 'Code', 'Name', 'Owner', 'Type', 'Phone', 'Email', 'Area', 'Address', 'Prev. Balance', 'Credit Limit'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        $row = 2;
        foreach ($customers as $index => $item) {
            $sheet->fromArray([
                $index + 1, $item->code, $item->name, $item->owner, $item->type, $item->phone, $item->email,
                optional($item->area)->name, $item->address, $item->previous_due, $item->credit_limit,
            ], null, 'A' . $row);
            $row++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = 'customer-list-' . now()->format('Y-m-d') . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function importBatch(Request $request)
    {
        if (!checkAccess('customer')) {
            return response()->json(['status' => false, 'message' => 'Forbidden'], 403);
        }

        $rows = $request->input('rows', []);
        $inserted = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $row = array_change_key_case((array) $row, CASE_LOWER);
            $rowLabel = 'Row ' . ($i + 1);

            $name = trim($row['name'] ?? '');
            $phone = trim($row['phone'] ?? '');
            if ($name === '' || $phone === '') {
                $skipped++;
                $errors[] = "$rowLabel: name and phone are required";
                continue;
            }
            if (! ($normalized = \App\Support\Phone::normalize($phone))) {
                $skipped++;
                $errors[] = "$rowLabel: phone \"$phone\" is not a valid number";
                continue;
            }
            $phone = $normalized;

            $exists = Customer::where('branch_id', $this->branchId)->where('phone', $phone)->exists();
            if ($exists) {
                $skipped++;
                $errors[] = "$rowLabel: phone \"$phone\" already exists";
                continue;
            }

            $areaName = trim($row['area'] ?? '');
            $area = $areaName !== '' ? Area::firstOrCreate(
                ['name' => $areaName, 'branch_id' => $this->branchId],
                ['status' => 'a', 'created_by' => $this->userId, 'ipAddress' => request()->ip()]
            ) : null;

            $type = strtolower(trim($row['type'] ?? '')) === 'wholesale' ? 'wholesale' : 'retail';

            $customer = new Customer();
            $customer->code = trim($row['code'] ?? '') !== '' ? trim($row['code']) : generateCode('Customer', 'CI');
            $customer->name = $name;
            $customer->owner = $row['owner'] ?? '';
            $customer->phone = $phone;
            $customer->type = $type;
            $customer->email = $row['email'] ?? '';
            $customer->address = $row['address'] ?? '';
            $customer->area_id = $area?->id;
            $customer->previous_due = $row['prev. balance'] ?? $row['previous_due'] ?? 0;
            $customer->credit_limit = $row['credit limit'] ?? $row['credit_limit'] ?? 0;
            $customer->status = 'a';
            $customer->created_by = $this->userId;
            $customer->ipAddress = request()->ip();
            $customer->branch_id = $this->branchId;
            $customer->save();
            // same as a new customer: the opening due is an invoice, so it reaches the ledger and the due report
            if ((float) $customer->previous_due > 0) {
                \App\Services\Isp\BillingService::createManual($customer, ['ledger_type' => 'opening'], [[
                    'description' => 'Opening balance (previous due)',
                    'unit_price' => (float) $customer->previous_due,
                    'quantity' => 1,
                ]]);
            }

            $inserted++;
        }

        return response()->json(['status' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors]);
    }

    // customer due
    public function customerDue(Request $request)
    {
        if (!checkAccess('customerDue')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }

        $allowedPerPage = [20, 50, 100, 200, 500];
        $perPage = in_array((int) $request->perPage, $allowedPerPage) ? (int) $request->perPage : 20;
        $date = $request->date ?: now()->format('Y-m-d');

        $dues = Customer::customerDuePaginated(
            $request,
            $date,
            $request->search ?? '',
            $request->sortBy ?? 'name',
            $request->sortDir ?? 'asc',
            $perPage,
            $request->page ?? 1
        );

        return \Inertia\Inertia::render('Report/Due', [
            'mode' => 'customer',
            'dues' => $dues,
            'filters' => [
                'search' => $request->search ?? '',
                'customerId' => $request->customerId ?? '',
                'dueStatus' => $request->dueStatus ?? '',
                'date' => $date,
                'sortBy' => $request->sortBy ?? 'name',
                'sortDir' => $request->sortDir === 'desc' ? 'desc' : 'asc',
                'perPage' => $perPage,
            ],
        ]);
    }

    public function getCustomerDue(Request $request)
    {
        $date = $request->date ? $request->date : null;
        $dues = Customer::customerDue($request, $date);
        return response()->json($dues);
    }

    public function customerDueExportExcel(Request $request)
    {
        if (!checkAccess('customerDue')) {
            abort(403);
        }

        $date = $request->date ?: now()->format('Y-m-d');
        $result = Customer::customerDuePaginated($request, $date, $request->search ?? '', $request->sortBy ?? 'name', $request->sortDir ?? 'asc', PHP_INT_MAX, 1);
        $dues = $result['data'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Customer Due');

        $headers = ['Sl', 'Code', 'Name', 'Mobile', 'Address', 'Due'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        $row = 2;
        $total = 0;
        foreach ($dues as $index => $item) {
            $sheet->fromArray([$index + 1, $item->code, $item->name, $item->phone, $item->address, Money::round((float) $item->due)], null, 'A' . $row);
            $total += (float) $item->due;
            $row++;
        }
        $sheet->fromArray(['', '', '', '', 'Total', Money::round($total)], null, 'A' . $row);
        $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = 'customer-due-' . now()->format('Y-m-d') . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function customerLedger(Request $request)
    {
        if (!checkAccess('customerLedger')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Report/Ledger', ['mode' => 'customer', 'preselectId' => $request->customerId]);
    }

    public function getCustomerLedger(Request $request)
    {
        $branchId = (int) $this->branchId;
        $request->merge(['customerId' => empty($request->customerId) ? null : (int) $request->customerId]);
        $query = "select
                'c' as sequence,
                cp.id,
                cp.date,
                cp.created_at,
                concat('Customer Payment - ', cp.invoice) as description,
                0 as bill,
                0 as paid,
                0 as due,
                cp.amount as cash_payment,
                0 as cash_receive,
                0 as return_amount,
                0 as balance
                from payments cp
                left join customers c on c.id = cp.customer_id
                where cp.status = 'a'
                and cp.type = 'customer'
                and cp.refund_id is null
                " . (empty($request->customerId) ? "" : " and cp.customer_id = '$request->customerId'") . "
                " . ($branchId == null ? "" : " and cp.branch_id = '$branchId'") . "

                UNION
                select
                'd' as sequence,
                cp.id,
                cp.date,
                cp.created_at,
                concat('Customer Receive - ', cp.invoice) as description,
                0 as bill,
                0 as paid,
                0 as due,
                0 as cash_payment,
                cp.amount as cash_receive,
                0 as return_amount,
                0 as balance
                from receives cp
                left join customers c on c.id = cp.customer_id
                where cp.status = 'a'
                and cp.type = 'customer'
                and cp.customer_payment_id is null
                " . (empty($request->customerId) ? "" : " and cp.customer_id = '$request->customerId'") . "
                " . ($branchId == null ? "" : " and cp.branch_id = '$branchId'") . "

                UNION
                -- ISP billing: bills, payments, voids, notes, refunds and the opening balance
                select
                'l' as sequence,
                le.id,
                le.entry_date as date,
                le.created_at,
                le.description,
                case when le.type in ('opening', 'invoice', 'debit_note') then le.debit else 0 end as bill,
                0 as paid,
                0 as due,
                case when le.type in ('payment_reversal', 'refund') then le.debit else 0 end as cash_payment,
                case when le.type = 'payment' then le.credit else 0 end as cash_receive,
                case when le.type in ('invoice_void', 'credit_note') then le.credit else 0 end as return_amount,
                0 as balance
                from ledger_entries le
                where 1 = 1
                " . (empty($request->customerId) ? "" : " and le.customer_id = '$request->customerId'") . "
                " . ($branchId == null ? "" : " and le.branch_id = '$branchId'") . "

                order by date asc, created_at asc, sequence asc, id asc";

        $ledgers = DB::select($query);

        // the previous due is already in the ledger as the opening balance bill
        $previousBalance = 0;

        $ledgers = collect($ledgers)->map(function ($ledger, $key) use ($previousBalance, $ledgers) {
            $lastBalance = $key == 0 ? $previousBalance : $ledgers[$key - 1]->balance;
            $ledger->balance = ($lastBalance + $ledger->bill + $ledger->cash_payment) - ($ledger->paid + $ledger->cash_receive + $ledger->return_amount);
            return $ledger;
        });

        $previousLedger = collect($ledgers)->filter(function ($ledger) use ($request) {
            return $ledger->date < $request->dateFrom;
        });
        $previousBalance = count($previousLedger) > 0 ? $previousLedger[count($previousLedger) - 1]->balance : $previousBalance;

        if (!empty($request->dateFrom) && !empty($request->dateTo)) {
            $ledgers = $ledgers->filter(function ($ledger) use ($request) {
                return $ledger->date >= $request->dateFrom && $ledger->date <= $request->dateTo;
            })->values();
        }


        return response()->json(['previousBalance' => $previousBalance, 'ledgers' => $ledgers]);
    }

    // Opens the customer portal as this customer (no password needed). The admin's own
    // session stays logged in; the customer portal shows a banner to go back.
    public function loginAs(Request $request, $id)
    {
        if (!checkAccess('customerLoginAs')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }

        $customer = Customer::where('branch_id', $this->branchId)->find($id);
        if (empty($customer)) {
            return redirect('/customer')->with('error', 'Customer not found');
        }

        Auth::guard('customer')->login($customer);
        $request->session()->put('customer_impersonator_id', $this->userId);
        AuditLogger::log('customer.login_as', $customer, null, ['code' => $customer->code, 'name' => $customer->name]);

        return redirect('/customer-portal/dashboard');
    }
}
