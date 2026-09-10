<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Traits\ChecksAccountBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Http\Requests\PaymentRequest;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PaymentController extends Controller
{
    use ChecksAccountBalance;

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
        $transactions = Payment::with('adUser', 'upUser', 'bank', 'customer', 'employee')->where('branch_id', $this->branchId);
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
        if (!empty($request->employeeId)) {
            $transactions->where('employee_id', $request->employeeId);
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
        
        $transactions = $transactions->where('status','a')->latest()->get();
        return response()->json($transactions);
    }

    public function create(Request $request)
    {
        if (!checkAccess('payment')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Account/PaymentReceive', [
            'mode' => 'payment',
            'role' => auth()->user()->role,
            'customerId' => $request->customerId,
            'supplierId' => $request->supplierId,
            'providerId' => $request->providerId,
            'employeeId' => $request->employeeId,
        ]);
    }

    public function getInvoice(Request $request)
    {
        $type = in_array($request->type, ['customer', 'provider', 'employee']) ? $request->type : 'supplier';
        return response()->json(['invoice' => transactionInvoice('Payment', 'P', $this->branchId, $type)]);
    }

    // Single record with relations loaded, for the print offcanvas.
    public function show(Request $request)
    {
        $data = Payment::with('adUser', 'bank', 'customer', 'employee')
            ->where('branch_id', $this->branchId)
            ->find($request->id);
        if (!$data) {
            return send_error('Not found', 'Payment record not found', 404);
        }
        return response()->json($data);
    }

    public function exportExcel(Request $request)
    {
        if (!checkAccess('payment')) {
            abort(403);
        }

        $rows = Payment::with('bank', 'customer', 'employee')
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
        $sheet->setTitle('Payment List');

        $headers = ['Sl', 'Invoice', 'Date', 'Type', 'Customer/Supplier', 'Method', 'Amount', 'Note'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        $row = 2;
        foreach ($rows as $index => $item) {
            $name = match ($item->type) {
                'customer' => optional($item->customer)->name,
                'employee' => optional($item->employee)->name,
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

        $fileName = 'payment-list-' . now()->format('Y-m-d') . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }


    public function store(PaymentRequest $request)
    {
        if (!$request->validated()) return send_error("Validation Error", $request->validated(), 422);
        try {
            $amount = (float) ($request->amount ?? 0);
            if ($amount > 0) {
                $balanceError = $this->assertSufficientInvestBalance($amount)
                    ?? $this->assertSufficientAccountBalance($request->payment_method, $request->bank_id, $amount);
                if ($balanceError) {
                    return send_error($balanceError, $balanceError, 422);
                }
            }
            $invoice = $request->invoice;
            if (empty($invoice) || Payment::where('invoice', $invoice)->exists()) {
                $invoice = transactionInvoice('Payment', 'P', $this->branchId, $request->type);
            }
            $data = new Payment();
            $data->invoice = $invoice;
            $dataKey = $request->except('id');
            foreach ($dataKey as $key => $value) {
                $data[$key] = $value;
            }
            $data->created_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->branch_id = $this->branchId;
            $data->save();

            $msg = ucfirst($request->type) . " payment has created successfully";
            return response()->json(['status' => true, 'message' => $msg, 'invoice' => $data->invoice, 'id' => $data->id]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(PaymentRequest $request)
    {
        if (!$request->validated()) return send_error("Validation Error", $request->validated(), 422);
        try {
            $data = Payment::find($request->id);
            $amount = (float) ($request->amount ?? 0);
            $previousAmount = (float) ($data->amount ?? 0);
            $sameAccount = $data->payment_method === $request->payment_method
                && (string) $data->bank_id === (string) $request->bank_id;
            if ($amount > 0) {
                $balanceError = $this->assertSufficientInvestBalance($amount, $previousAmount)
                    ?? $this->assertSufficientAccountBalance($request->payment_method, $request->bank_id, $amount, $sameAccount ? $previousAmount : 0);
                if ($balanceError) {
                    return send_error($balanceError, $balanceError, 422);
                }
            }
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

            $msg = ucfirst($request->type) . " payment has updated successfully";
            return response()->json(['status' => true, 'message' => $msg, 'invoice' => $data->invoice, 'id' => $data->id]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        try {
            $data = Payment::find($request->id);
            $data->deleted_by = $this->userId;
            $data->status = 'd';
            $data->ipAddress = request()->ip();
            $data->update();

            $data->delete();
            $msg = ucfirst($data->type) . " payment has deleted successfully";
            return response()->json(['status' => true, 'message' => $msg]);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    public function deletedPaymentRecord()
    {
        if (!checkAccess('paymentRestore')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Account/Payment/DeletedRecord');
    }

    public function getDeletedPayment(Request $request)
    {
        $payments = Payment::onlyTrashed()->with('deUser', 'bank', 'customer', 'employee')->where('branch_id', $this->branchId);
        if (!empty($request->search)) {
            $payments = $payments->where(function ($query) use ($request) {
                $query->where('invoice', 'like', '%' . $request->search . '%')
                    ->orWhere('note', 'like', '%' . $request->search . '%');
            });
        }
        $payments = $payments->latest('deleted_at')->get()->map(function ($item) {
            $item->party_name = match ($item->type) {
                'customer' => optional($item->customer)->name,
                'employee' => optional($item->employee)->name,
                default => null,
            };
            $item->deleted_by_name = $item->deUser->name ?? 'NA';
            return $item;
        });
        return response()->json($payments);
    }

    public function restorePayment(Request $request)
    {
        if (!checkAccess('paymentRestore')) {
            return send_error('You are not authorized to restore payment', null, 403);
        }
        $payment = Payment::onlyTrashed()->where('branch_id', $this->branchId)->find($request->id);
        if (empty($payment)) {
            return send_error('Deleted payment not found', null, 404);
        }
        try {
            $payment->restore();
            $payment->status = 'a';
            $payment->deleted_by = null;
            $payment->ipAddress = request()->ip();
            $payment->update();

            $msg = ucfirst($payment->type) . " payment has restored successfully";
            return response()->json(['status' => true, 'message' => $msg]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }
}
