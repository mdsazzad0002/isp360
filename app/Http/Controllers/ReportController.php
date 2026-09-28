<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\Bank;
use App\Models\Customer;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
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

    public function getTransactionList(Request $request)
    {
        $query = DB::table('transactions as t')
            ->leftJoin('account_heads as ah', 'ah.id', '=', 't.account_id')
            ->where('t.branch_id', $this->branchId)
            ->where('t.status', 'a')
            ->whereNull('t.deleted_at')
            ->select('t.date', 'ah.name as account_name', 't.note', 't.amount');
        if (!empty($request->type)) {
            $query->where('t.type', $request->type);
        }
        if (!empty($request->dateFrom) && !empty($request->dateTo)) {
            $query->whereBetween('t.date', [$request->dateFrom, $request->dateTo]);
        }
        return response()->json($query->orderByDesc('t.date')->get());
    }

    public function dayBook()
    {
        if (!checkAccess('dayBook')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Report/DayBook');
    }

    public function getDayBook(Request $request)
    {
        $dateFrom = $request->dateFrom ?: now()->format('Y-m-d');
        $dateTo = $request->dateTo ?: now()->format('Y-m-d');
        $dateBeforeFrom = Carbon::parse($dateFrom)->subDay()->format('Y-m-d');

        $req = ['branchId' => $this->branchId];

        $openingCash = (float) (AccountHead::getCashBalance($req, $dateBeforeFrom)->cashbalance ?? 0);
        $closingCash = (float) (AccountHead::getCashBalance($req, $dateTo)->cashbalance ?? 0);

        $openingBanks = collect(Bank::getBankBalance($req, $dateBeforeFrom))->keyBy('id');
        $closingBanks = collect(Bank::getBankBalance($req, $dateTo))->keyBy('id');

        $bankRows = $closingBanks->map(function ($bank, $id) use ($openingBanks) {
            $opening = (float) ($openingBanks[$id]->currentbalance ?? 0);
            $closing = (float) $bank->currentbalance;
            return [
                'id' => $id,
                'name' => trim($bank->bank_name . ' ' . $bank->name . ' ' . $bank->number),
                'opening' => $opening,
                'closing' => $closing,
                'change' => Money::round($closing - $opening),
            ];
        })->values();

        $totalOpeningBank = Money::round($bankRows->sum('opening'));
        $totalClosingBank = Money::round($bankRows->sum('closing'));
        $cashChange = Money::round($closingCash - $openingCash);

        $receiptBanks = $bankRows->filter(fn ($b) => $b['change'] > 0)->map(fn ($b) => ['id' => $b['id'], 'name' => $b['name'], 'amount' => $b['change']])->values();
        $paymentBanks = $bankRows->filter(fn ($b) => $b['change'] < 0)->map(fn ($b) => ['id' => $b['id'], 'name' => $b['name'], 'amount' => Money::round(abs($b['change']))])->values();

        $totalReceipt = Money::round($receiptBanks->sum('amount') + ($cashChange > 0 ? $cashChange : 0));
        $totalPayment = Money::round($paymentBanks->sum('amount') + ($cashChange < 0 ? abs($cashChange) : 0));

        return response()->json([
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'openingCash' => Money::round($openingCash),
            'closingCash' => Money::round($closingCash),
            'totalOpeningBank' => $totalOpeningBank,
            'totalClosingBank' => $totalClosingBank,
            'openingBanks' => $bankRows->map(fn ($b) => ['id' => $b['id'], 'name' => $b['name'], 'amount' => $b['opening']])->values(),
            'closingBanks' => $bankRows->map(fn ($b) => ['id' => $b['id'], 'name' => $b['name'], 'amount' => $b['closing']])->values(),
            'receiptCash' => $cashChange > 0 ? $cashChange : 0,
            'paymentCash' => $cashChange < 0 ? Money::round(abs($cashChange)) : 0,
            'receiptBanks' => $receiptBanks,
            'paymentBanks' => $paymentBanks,
            'totalReceipt' => $totalReceipt,
            'totalPayment' => $totalPayment,
            'leftTotal' => Money::round($openingCash + $totalOpeningBank + $totalReceipt),
            'rightTotal' => Money::round($totalPayment + $closingCash + $totalClosingBank),
        ]);
    }

    public function balanceSheet()
    {
        if (!checkAccess('balanceSheet')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Report/BalanceSheet');
    }

    // Assets = Liabilities + Equity, all as of a single date. This is a reduced
    // balance sheet scoped to what the surviving schema (cash/bank/customer)
    // can actually support — no inventory, fixed-asset, supplier or
    // investment data exists in this build. Retained Earnings is reported as
    // the balancing figure (Assets - Liabilities) since there is no full
    // general ledger.
    public function getBalanceSheet(Request $request)
    {
        $branchId = $this->branchId;
        $date = $request->date ?: now()->format('Y-m-d');
        $req = ['branchId' => $branchId];

        // ---- Cash & Bank ----
        $cashInHand = (float) (AccountHead::getCashBalance($req, $date)->cashbalance ?? 0);

        $bankRows = collect(Bank::getBankBalance($req, $date))->map(function ($b) {
            return [
                'id' => $b->id,
                'name' => trim($b->bank_name . ' ' . $b->name . ' ' . $b->number),
                'amount' => Money::round((float) $b->currentbalance),
            ];
        })->values();
        $totalBank = Money::round($bankRows->sum('amount'));

        // ---- Receivables (customer ledger can flip sign into an advance) ----
        $customerRows = collect(Customer::customerDue($req, $date));
        $accountsReceivable = Money::round($customerRows->sum(fn ($c) => (float) $c->due > 0 ? (float) $c->due : 0));
        $customerAdvance = Money::round($customerRows->sum(fn ($c) => (float) $c->due < 0 ? abs((float) $c->due) : 0));

        $assets = [
            'cashInHand' => Money::round($cashInHand),
            'bankBalances' => $bankRows,
            'totalBank' => $totalBank,
            'accountsReceivable' => $accountsReceivable,
        ];
        $totalAssets = Money::round(array_sum([
            $assets['cashInHand'],
            $assets['totalBank'],
            $assets['accountsReceivable'],
        ]));

        $liabilities = [
            'customerAdvance' => $customerAdvance,
        ];
        $totalLiabilities = Money::round(array_sum($liabilities));

        $retainedEarnings = Money::round($totalAssets - $totalLiabilities);
        $totalEquity = Money::round($retainedEarnings);
        $equity = [
            'retainedEarnings' => $retainedEarnings,
        ];

        return response()->json([
            'date' => $date,
            'assets' => $assets,
            'totalAssets' => $totalAssets,
            'liabilities' => $liabilities,
            'totalLiabilities' => $totalLiabilities,
            'equity' => $equity,
            'totalEquity' => $totalEquity,
            'totalLiabilitiesAndEquity' => Money::round($totalLiabilities + $totalEquity),
        ]);
    }

    // Per-line drill-down for the Balance Sheet — every item is clickable and shows only
    // the parties/records behind that one number, not the whole system.
    public function getBalanceSheetDetail(Request $request)
    {
        $branchId = $this->branchId;
        $date = $request->date ?: now()->format('Y-m-d');
        $section = $request->section;
        $req = ['branchId' => $branchId];

        switch ($section) {
            case 'receivable':
                $rows = collect(Customer::customerDue($req, $date))
                    ->filter(fn ($c) => (float) $c->due > 0)
                    ->sortByDesc(fn ($c) => (float) $c->due)
                    ->map(fn ($c) => ['label' => $c->name, 'sublabel' => $c->code, 'amount' => Money::round((float) $c->due)])
                    ->values();
                break;

            case 'customerAdvance':
                $rows = collect(Customer::customerDue($req, $date))
                    ->filter(fn ($c) => (float) $c->due < 0)
                    ->sortBy(fn ($c) => (float) $c->due)
                    ->map(fn ($c) => ['label' => $c->name, 'sublabel' => $c->code, 'amount' => Money::round(abs((float) $c->due))])
                    ->values();
                break;

            case 'retainedEarnings':
                $summary = json_decode($this->getBalanceSheet(new Request(['date' => $date]))->getContent());
                return response()->json([
                    'type' => 'breakdown',
                    'rows' => [
                        ['label' => 'Total Assets', 'direction' => 'in', 'amount' => (float) $summary->totalAssets],
                        ['label' => 'Total Liabilities', 'direction' => 'out', 'amount' => (float) $summary->totalLiabilities],
                    ],
                    'total' => (float) $summary->equity->retainedEarnings,
                ]);

            default:
                return response()->json(['type' => 'list', 'rows' => []]);
        }

        return response()->json(['type' => 'list', 'rows' => $rows]);
    }

}
