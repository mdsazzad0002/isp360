<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\SmsGateway;
use App\Models\SmsLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class SmsGatewayController extends Controller
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

    public function create()
    {
        if (!checkAccess('smsSetting')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/Sms/Entry');
    }

    public function index(Request $request)
    {
        if (!checkAccess('smsSetting')) {
            return send_error('You are not authorized for this action', null, 403);
        }
        $gateways = SmsGateway::where('branch_id', $this->branchId)->latest()->get();
        return response()->json($gateways);
    }

    private function validatePayload(Request $request, array $extraRules = [], bool $keySaved = false)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'provider_type' => 'required|in:custom,mram,gennet',
        ];
        // on edit an empty key keeps the saved one (the key is never sent back to the browser)
        $key = $keySaved ? 'nullable|string' : 'required|string';
        if ($request->provider_type === 'mram') {
            $rules['api_key'] = $key;
            $rules['sender_id'] = 'required|string';
            $rules['sms_type'] = 'required|in:text,unicode';
            $rules['label'] = 'required|in:transactional,promotional';
        } elseif ($request->provider_type === 'gennet') {
            // api_key holds GenNet's api_token and sender_id holds its sid, reusing
            // the same columns "mram" uses for its analogous fields rather than
            // adding gennet-specific columns. Base URL (isms.gennet.com.bd) is
            // fixed in code, same as mram, so no url_template is needed here.
            $rules['api_key'] = $key;
            $rules['sender_id'] = 'required|string';
        } else {
            $rules['method'] = 'required|in:GET,POST';
            $rules['url_template'] = 'required|string';
        }
        return Validator::make($request->all(), array_merge($rules, $extraRules));
    }

    public function store(Request $request)
    {
        $validator = $this->validatePayload($request);
        if ($validator->fails()) return send_error('Validation Error', $validator->errors(), 422);

        try {
            $data = new SmsGateway();
            $data->name = $request->name;
            $data->provider_type = $request->provider_type;
            $data->method = match ($request->provider_type) {
                'mram' => 'GET',
                'gennet' => 'POST',
                default => $request->method,
            };
            $data->url_template = in_array($request->provider_type, ['mram', 'gennet']) ? null : $request->url_template;
            $data->api_key = $request->api_key;
            $data->sender_id = $request->sender_id;
            $data->sms_type = $request->sms_type ?? 'text';
            $data->label = $request->label ?? 'promotional';
            $data->is_active = (bool) $request->is_active;
            $data->is_default = false;
            $data->created_by = $this->userId;
            $data->branch_id = $this->branchId;
            $data->ipAddress = request()->ip();
            $data->save();

            if (!SmsGateway::where('branch_id', $this->branchId)->where('is_default', true)->exists()) {
                $data->is_default = true;
                $data->save();
            }

            return response()->json(['status' => true, 'message' => 'SMS gateway has created successfully']);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function update(Request $request)
    {
        $data = SmsGateway::where('id', $request->id)->where('branch_id', $this->branchId)->first();
        if (empty($data)) return send_error('SMS gateway not found', null, 404);
        $validator = $this->validatePayload($request, ['id' => 'required'], $data->has_api_key && $data->provider_type === $request->provider_type);
        if ($validator->fails()) return send_error('Validation Error', $validator->errors(), 422);

        try {

            $data->name = $request->name;
            $data->provider_type = $request->provider_type;
            $data->method = match ($request->provider_type) {
                'mram' => 'GET',
                'gennet' => 'POST',
                default => $request->method,
            };
            $data->url_template = in_array($request->provider_type, ['mram', 'gennet']) ? null : $request->url_template;
            if ($request->filled('api_key') || $request->provider_type === 'custom') {
                $data->api_key = $request->api_key;
            }
            $data->sender_id = $request->sender_id;
            $data->sms_type = $request->sms_type ?? 'text';
            $data->label = $request->label ?? 'promotional';
            $data->updated_by = $this->userId;
            $data->updated_at = Carbon::now();
            $data->ipAddress = request()->ip();
            $data->update();

            return response()->json(['status' => true, 'message' => 'SMS gateway has updated successfully']);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        try {
            $data = SmsGateway::where('id', $request->id)->where('branch_id', $this->branchId)->first();
            if (empty($data)) return send_error('SMS gateway not found', null, 404);

            $wasDefault = $data->is_default;
            $data->deleted_by = $this->userId;
            $data->ipAddress = request()->ip();
            $data->update();
            $data->delete();

            if ($wasDefault) {
                $next = SmsGateway::where('branch_id', $this->branchId)->first();
                if ($next) {
                    $next->is_default = true;
                    $next->save();
                }
            }

            return response()->json(['status' => true, 'message' => 'SMS gateway has deleted successfully']);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function toggleActive(Request $request)
    {
        try {
            $data = SmsGateway::where('id', $request->id)->where('branch_id', $this->branchId)->first();
            if (empty($data)) return send_error('SMS gateway not found', null, 404);

            $data->is_active = (bool) $request->is_active;
            $data->updated_by = $this->userId;
            $data->updated_at = Carbon::now();
            $data->ipAddress = request()->ip();
            $data->update();

            return response()->json(['status' => true, 'message' => 'SMS gateway status updated']);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function setDefault(Request $request)
    {
        try {
            $data = SmsGateway::where('id', $request->id)->where('branch_id', $this->branchId)->first();
            if (empty($data)) return send_error('SMS gateway not found', null, 404);

            SmsGateway::where('branch_id', $this->branchId)->update(['is_default' => false]);
            $data->is_default = true;
            $data->is_active = true;
            $data->updated_by = $this->userId;
            $data->updated_at = Carbon::now();
            $data->ipAddress = request()->ip();
            $data->update();

            return response()->json(['status' => true, 'message' => 'Default SMS gateway updated']);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function promotionPage()
    {
        if (!checkAccess('smsPromotion')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/Sms/Promotion');
    }

    public function sendPromotion(Request $request)
    {
        if (!checkAccess('smsPromotion')) {
            return send_error('You are not authorized to send SMS', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'customerIds' => 'required|array|min:1',
            'message' => 'required|string|max:1000',
            'gatewayIds' => 'nullable|array',
        ]);
        if ($validator->fails()) return send_error('Validation Error', $validator->errors(), 422);

        $gateways = SmsGateway::where('branch_id', $this->branchId)
            ->where('is_active', true)
            ->when(!empty($request->gatewayIds), fn ($q) => $q->whereIn('id', $request->gatewayIds))
            ->get();

        if ($gateways->isEmpty()) {
            return send_error('No active SMS gateway is configured', null, 422);
        }

        $customers = Customer::where('branch_id', $this->branchId)->whereIn('id', $request->customerIds)->get();
        $validCustomers = $customers->filter(fn ($c) => !empty($c->phone))->values();

        $sent = 0;
        $failed = $customers->count() - $validCustomers->count();
        $lastError = null;
        $now = Carbon::now();
        $ip = request()->ip();

        foreach ($customers as $customer) {
            if (empty($customer->phone)) {
                SmsLog::create([
                    'customer_id' => $customer->id,
                    'sms_gateway_id' => null,
                    'gateway_name' => null,
                    'phone' => '',
                    'message' => $request->message,
                    'purpose' => 'promotional',
                    'is_success' => false,
                    'response' => 'Customer has no phone number',
                    'created_by' => $this->userId,
                    'created_at' => $now,
                    'ipAddress' => $ip,
                    'branch_id' => $this->branchId,
                ]);
            }
        }

        // Sent in batches (one API call reaches many numbers at once) rather than one call per
        // customer — cheaper and faster, and matches how gateways like MRAM's "many-to-many" API work.
        foreach ($validCustomers->chunk(100) as $batch) {
            $numbers = $batch->pluck('phone')->all();
            $delivered = false;
            $usedGateway = null;
            $response = null;

            foreach ($gateways as $gateway) {
                $result = sendSmsViaGateway($gateway, $numbers, $request->message);
                $usedGateway = $gateway;
                $response = $result['response'];
                if ($result['status']) {
                    $delivered = true;
                    break;
                }
                $lastError = $result['response'];
            }

            foreach ($batch as $customer) {
                SmsLog::create([
                    'customer_id' => $customer->id,
                    'sms_gateway_id' => $usedGateway?->id,
                    'gateway_name' => $usedGateway?->name,
                    'phone' => $customer->phone,
                    'message' => $request->message,
                    'purpose' => 'promotional',
                    'is_success' => $delivered,
                    'response' => $response,
                    'created_by' => $this->userId,
                    'created_at' => $now,
                    'ipAddress' => $ip,
                    'branch_id' => $this->branchId,
                ]);
            }

            if ($delivered) {
                $sent += count($numbers);
            } else {
                $failed += count($numbers);
            }
        }

        return response()->json([
            'status' => true,
            'message' => "SMS sent to {$sent} customer(s)" . ($failed ? ", {$failed} failed" . ($lastError ? " ({$lastError})" : '') : ''),
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }

    public function logPage()
    {
        if (!checkAccess('smsPromotion')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Control/Sms/Log');
    }

    public function getLog(Request $request)
    {
        $logs = SmsLog::with('customer', 'adUser')->where('branch_id', $this->branchId);

        if (!empty($request->dateFrom) && !empty($request->dateTo)) {
            $logs->whereBetween('created_at', [$request->dateFrom . ' 00:00:00', $request->dateTo . ' 23:59:59']);
        }
        if (!empty($request->search)) {
            $logs->where(function ($q) use ($request) {
                $q->where('phone', 'like', '%' . $request->search . '%')
                    ->orWhere('message', 'like', '%' . $request->search . '%');
            });
        }
        if ($request->status === 'success') {
            $logs->where('is_success', true);
        } elseif ($request->status === 'failed') {
            $logs->where('is_success', false);
        }

        $perPage = (int) ($request->perPage ?? 20);
        $perPage = $perPage > 0 ? $perPage : 20;
        $page = (int) ($request->page ?? 1);
        $page = $page > 0 ? $page : 1;

        $total = (clone $logs)->count();
        $rows = $logs->latest('created_at')->forPage($page, $perPage)->get()->map(function ($log) {
            $log->customer_name = $log->customer->name ?? 'N/A';
            $log->sent_by = $log->adUser->name ?? 'N/A';
            return $log;
        });

        return response()->json(['data' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage]);
    }
}
