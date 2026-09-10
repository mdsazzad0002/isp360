<?php

namespace App\Http\Controllers;

use App\Models\Receive;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Http\Requests\ReceiveRequest;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReceiveController extends Controller
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
        $transactions = Receive::with('adUser', 'upUser', 'bank', 'customer')->where('branch_id', $this->branchId);
        if (!empty($request->transactionId)) {
            $transactions->where('id', $request->transactionId);
        }
        if (!empty($request->customerId)) {
            $transactions->where('customer_id', $request->customerId);
        }
        if (!empty($request->supplierId)) {
            $transactions->where('supplier_id', $request->supplierId);
        }
        if (!empty($request->providerId)) {
            $transactions->where('provider_id', $request->providerId);
        }
        if (!empty($request->bankId)) {
            $transactions->where('bank_id', $request->bankId);
        }
        if (!empty($request->type)) {
            $transactions->where('type', $request->type);
        }
        if (!empty($request->dateFrom) && !empty($request->dateTo)) {
            $transactions->whereBetween('date', [$request->dateFrom, $request->dateTo]);
        }
        $transactions = $transactions->latest()->get();
        return response()->json($transactions);
    }

    public function create(Request $request)
    {
        if (!checkAccess('receive')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Account/PaymentReceive', [
            'mode' => 'receive',
            'role' => auth()->user()->role,
            'customerId' => $request->customerId,
            'providerId' => $request->providerId,
        ]);
    }

    public function getInvoice(Request $request)
    {
        $type = in_array($request->type, ['supplier', 'provider']) ? $request->type : 'customer';
        return response()->json(['invoice' => transactionInvoice('Receive', 'R', $this->branchId, $type)]);
    }

    // Single record with relations loaded, for the print offcanvas.
    public function show(Request $request)
    {
        $data = Receive::with('adUser', 'bank', 'customer')
            ->where('branch_id', $this->branchId)
            ->find($request->id);
        if (!$data) {
            return send_error('Not found', 'Receive record not found', 404);
        }
        return response()->json($data);
    }

    public function exportExcel(Request $request)
    {
        if (!checkAccess('receive')) {
            abort(403);
        }

        $rows = Receive::with('bank', 'customer')
            ->where('branch_id', $this->branchId)
            ->where('status', 'a')
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->dateFrom && $request->dateTo, fn ($q) => $q->whereBetween('date', [$request->dateFrom, $request->dateTo]))
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($qq) use ($request) {
                    $qq->where('invoice', 'like', '%' . $request->search . '%')
                        ->orWhere('note', 'like', '%' . $request->search . '%');
                });
            })
            ->latest()
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Receive List');

        $headers = ['Sl', 'Invoice', 'Date', 'Type', 'Customer/Supplier', 'Method', 'Amount', 'Note'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        $row = 2;
        foreach ($rows as $index => $item) {
            $name = match ($item->type) {
                'customer' => optional($item->customer)->name,
                default => null,
            };
            $sheet->fromArray([
                $index + 1, $item->invoice, $item->date, ucfirst($item->type), $name, ucfirst($item->payment_method), $item->amount, $item->note,
            ], null, 'A' . $row);
            $row++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = 'receive-list-' . now()->format('Y-m-d') . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }


    public function store(ReceiveRequest $request)
    {
        if (!$request->validated()) return send_error("Validation Error", $request->validated(), 422);
        try {
            $invoice = $request->invoice;
            if (empty($invoice) || Receive::where('invoice', $invoice)->exists()) {
                $invoice = transactionInvoice('Receive', 'R', $this->branchId, $request->type);
            }
            $data = new Receive();
            $data->invoice = $invoice;
            $dataKey = $request->except('id');
            foreach ($dataKey as $key => $value) {
                $data[$key] = $value;
            }
            $data->created_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->save();

            $msg = ucfirst($request->type) . " payment receive has created successfully";
            return response()->json(['status' => true, 'message' => $msg, 'invoice' => $data->invoice, 'id' => $data->id]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(ReceiveRequest $request)
    {
        if (!$request->validated()) return send_error("Validation Error", $request->validated(), 422);
        try {
            $data = Receive::find($request->id);
            $dataKey = $request->except('id');
            foreach ($dataKey as $key => $value) {
                $data[$key] = $value;
            }
            if ($request->payment_method == 'cash') {
                $data->bank_id = NULL;
            }
            $data->updated_by = $this->userId;
            $data->updated_at = Carbon::now();
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->update();

            $msg = ucfirst($request->type) . " payment receive has updated successfully";
            return response()->json(['status' => true, 'message' => $msg, 'invoice' => $data->invoice, 'id' => $data->id]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        try {
            $data = Receive::find($request->id);
            $data->deleted_by = $this->userId;
            $data->status = 'd';
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            $msg = ucfirst($data->type) . " payment receive has deleted successfully";
            return response()->json(['status' => true, 'message' => $msg]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }
}
