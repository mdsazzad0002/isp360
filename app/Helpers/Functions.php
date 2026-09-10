<?php
// use App\Models\UserAccess;

use App\Models\UserAccess;
use App\Models\CompanyProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

// the app's sidebar navigation, grouped; shared by the sidebar and the global quick-search
function appMenuGroups()
{
    return [
        [
            'key' => 'accounts',
            'label' => 'Accounts',
            'icon' => 'bi-cash',
            'items' => [
                ['access' => 'expense', 'uri' => '/expense', 'match' => 'expense', 'icon' => 'bi-clipboard-minus', 'label' => 'Expense Entry'],
                ['access' => 'income', 'uri' => '/income', 'match' => 'income', 'icon' => 'bi-duffle', 'label' => 'Income Entry'],
                ['access' => 'receive', 'uri' => '/receive', 'match' => 'receive', 'icon' => 'bi-cash-stack', 'label' => 'Receive'],
                ['access' => 'payment', 'uri' => '/payment', 'match' => 'payment', 'icon' => 'bi-person-workspace', 'label' => 'Payment'],
                ['access' => 'paymentRestore', 'uri' => '/deleted-payment-record', 'match' => 'deleted-payment-record', 'icon' => 'bi-arrow-counterclockwise', 'label' => 'Deleted Payment Record'],
                ['access' => 'bankTransaction', 'uri' => '/bankTransaction', 'match' => 'bankTransaction', 'icon' => 'bi-bank', 'label' => 'Bank Transaction'],
                ['access' => 'bankTransactionRestore', 'uri' => '/deleted-bankTransaction-record', 'match' => 'deleted-bankTransaction-record', 'icon' => 'bi-arrow-counterclockwise', 'label' => 'Deleted Bank Transaction Record'],
                ['access' => 'accounthead', 'uri' => '/accounthead', 'match' => 'accounthead', 'icon' => 'bi-plus-circle', 'label' => 'AccountHead Entry'],
                ['access' => 'accountheadRestore', 'uri' => '/deleted-accounthead-record', 'match' => 'deleted-accounthead-record', 'icon' => 'bi-arrow-counterclockwise', 'label' => 'Deleted AccountHead Record'],
                ['access' => 'bank', 'uri' => '/bank', 'match' => 'bank', 'icon' => 'bi-plus-circle', 'label' => 'Bank Entry'],
                ['access' => 'bankRestore', 'uri' => '/deleted-bank-record', 'match' => 'deleted-bank-record', 'icon' => 'bi-arrow-counterclockwise', 'label' => 'Deleted Bank Record'],
            ],
        ],
        [
            'key' => 'reports',
            'label' => 'Reports',
            'icon' => 'bi-calendar-check',
            'items' => [
                ['access' => 'cashLedger', 'uri' => '/cashLedger', 'match' => 'cashLedger', 'icon' => 'bi-list', 'label' => 'Cash Ledger'],
                ['access' => 'bankLedger', 'uri' => '/bankLedger', 'match' => 'bankLedger', 'icon' => 'bi-list', 'label' => 'Bank Ledger'],
                ['access' => 'cashLedger', 'uri' => '/cashBankLedger', 'match' => 'cashBankLedger', 'icon' => 'bi-list-columns', 'label' => 'Cash & Bank Ledger'],
                ['access' => 'customerDue', 'uri' => '/customerDue', 'match' => 'customerDue', 'icon' => 'bi-cash', 'label' => 'Customer Due'],
                ['access' => 'customerLedger', 'uri' => '/customerLedger', 'match' => 'customerLedger', 'icon' => 'bi-list', 'label' => 'Customer Ledger'],
                ['access' => 'dayBook', 'uri' => '/dayBook', 'match' => 'dayBook', 'icon' => 'bi-journal-check', 'label' => 'Day Book'],
                ['access' => 'balanceSheet', 'uri' => '/balanceSheet', 'match' => 'balanceSheet', 'icon' => 'bi-clipboard-data', 'label' => 'Balance Sheet'],
            ],
        ],
        [
            'key' => 'control',
            'label' => 'Control Panel',
            'icon' => 'bi-bank2',
            'items' => [
                ['access' => 'customer', 'uri' => '/customer', 'match' => 'customer', 'icon' => 'bi-person', 'label' => 'Customer'],
                ['access' => 'customerRestore', 'uri' => '/deleted-customer-record', 'match' => 'deleted-customer-record', 'icon' => 'bi-arrow-counterclockwise', 'label' => 'Deleted Customer Record'],
                ['access' => 'area', 'uri' => '/area', 'match' => 'area', 'icon' => 'bi-globe', 'label' => 'Area Entry'],
                ['access' => 'areaRestore', 'uri' => '/deleted-area-record', 'match' => 'deleted-area-record', 'icon' => 'bi-arrow-counterclockwise', 'label' => 'Deleted Area Record'],
                ['access' => 'company', 'uri' => '/company', 'match' => 'company', 'icon' => 'bi-plus-circle', 'label' => 'Company Entry'],
                ['access' => 'companyRestore', 'uri' => '/deleted-company-record', 'match' => 'deleted-company-record', 'icon' => 'bi-arrow-counterclockwise', 'label' => 'Deleted Company Record'],
            ],
        ],
        [
            'key' => 'sms',
            'label' => 'SMS',
            'icon' => 'bi-chat-dots',
            'items' => [
                ['access' => 'smsSetting', 'uri' => '/sms-gateway', 'match' => 'sms-gateway', 'icon' => 'bi-hdd-network', 'label' => 'SMS Gateway Setting'],
                ['access' => 'smsPromotion', 'uri' => '/sms-promotion', 'match' => 'sms-promotion', 'icon' => 'bi-megaphone', 'label' => 'Send Promotion SMS'],
                ['access' => 'smsPromotion', 'uri' => '/sms-log', 'match' => 'sms-log', 'icon' => 'bi-list-check', 'label' => 'SMS Log'],
            ],
        ],
        [
            'key' => 'userManage',
            'label' => 'User Manage',
            'icon' => 'bi-person-fill-gear',
            'items' => [
                ['access' => 'user', 'uri' => '/user', 'match' => 'user', 'icon' => 'bi-person-fill-add', 'label' => 'User Entry'],
                ['access' => 'role', 'uri' => '/role', 'match' => 'role', 'icon' => 'bi-person-badge', 'label' => 'Role Entry'],
            ],
        ],
        [
            'key' => 'setting',
            'label' => 'Setting',
            'icon' => 'bi-gear',
            'items' => [
                ['access' => 'companyProfile', 'uri' => '/companyProfile', 'match' => 'companyProfile', 'icon' => 'bi-house-fill', 'label' => 'Company Profile'],
                ['access' => 'branch', 'uri' => '/branch', 'match' => 'branch', 'icon' => 'bi-shop', 'label' => 'Branch'],
                ['access' => 'branchManage', 'uri' => '/branchManage', 'match' => 'branchManage', 'icon' => 'bi-diagram-3', 'label' => 'Branch Manage'],
            ],
        ],
    ];
}

// flat, access-filtered list of every menu item (including the standalone top-level links) for the global quick-search
function appMenuSearchIndex()
{
    $items = [
        ['label' => 'Dashboard', 'uri' => '/', 'icon' => 'bi-grid', 'group' => 'Menu'],
    ];

    foreach (appMenuGroups() as $group) {
        foreach ($group['items'] as $item) {
            if (checkAccess($item['access'])) {
                $items[] = [
                    'label' => $item['label'],
                    'uri' => $item['uri'],
                    'icon' => $item['icon'],
                    'group' => $group['label'],
                ];
            }
        }
    }

    return $items;
}

function send_error($message, $errors = null, $code = 500)
{
    $response = [
        'status' => false,
        'message' => $message,
    ];
    !empty($errors) ? $response['errors'] = $errors : null;

    return response()->json($response, $code);
}

// upload image
function imageUpload($request, $image, $directory, $code)
{
    $doUpload = function ($image) use ($directory, $code) {
        $extention = $image->getClientOriginalExtension();
        $imageName = $code . '_' . uniqId() . '.' . $extention;
        $image->move(public_path($directory), $imageName);
        return $directory . '/' . $imageName;
    };
    if (!empty($image) && $request->hasFile($image)) {
        $file = $request->file($image);
        if (is_array($file) && count($file)) {
            $imagesPath = [];
            foreach ($file as $key => $image) {
                $imagesPath[] = $doUpload($image);
            }
            return $imagesPath;
        } else {
            return $doUpload($file);
        }
    }

    return false;
}

// delete a previously uploaded file, given the relative path stored in the DB
function deleteUploadedFile($path)
{
    if (empty($path) || !is_string($path)) {
        return;
    }

    $fullPath = public_path($path);
    if (file_exists($fullPath)) {
        unlink($fullPath);
    }
}

// code generate
function invoiceGenerate($model, $prefix = '', $branch_id = null, $table = null)
{
    $year = date('y');
    $invoice = $year . "00001";
    $modelName = $table ?? (strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $model)) . 's');
    $clause = "";
    if ($branch_id != null) {
        $clause .= "and branch_id = '$branch_id'";
    }
    $model = DB::select("select * from `$modelName` where invoice like '$prefix$year%' $clause");

    $num_rows = count($model);
    if ($num_rows != 0) {
        $newCode = $num_rows + 1;
        $zeros = ['0', '00', '000', '0000'];
        $invoice = $year . (strlen($newCode) > count($zeros) ? $newCode : $zeros[count($zeros) - strlen($newCode)] . $newCode);
    }
    return $prefix . $invoice;
}
// code generate
function transactionInvoice($model, $prefix = '', $branch_id = null, $type = 'expense')
{
    $year = date('y');
    $invoice = $year . "0001";

    $modelName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $model)) . 's';
    $clause = "";
    if ($branch_id != null) {
        $clause .= "and branch_id = '$branch_id'";
    }
    $model = DB::select("select * from `$modelName` where type = '$type' and invoice like '$prefix$year%' $clause");

    $num_rows = count($model);
    if ($num_rows != 0) {
        $newCode = $num_rows + 1;
        $zeros = ['0', '00', '000'];
        $invoice = $year . (strlen($newCode) > count($zeros) ? $newCode : $zeros[count($zeros) - strlen($newCode)] . $newCode);
    }
    return $prefix . $invoice;
}

// code generate
function generateCode($model, $prefix = '', $branch_id = null, $column = 'code')
{
    $modelName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $model)) . 's';
    $clause = "";
    if ($branch_id != null) {
        $clause .= "and branch_id = '$branch_id'";
    }
    $prefixLen = strlen($prefix) + 1;
    $row = DB::selectOne(
        "select max(cast(substring(`$column`, $prefixLen) as unsigned)) as max_code from `$modelName` where `$column` like ? $clause",
        [$prefix . '%']
    );

    $newCode = (int) ($row->max_code ?? 0) + 1;
    $zeros = ['0', '00', '000', '0000'];
    $numLen = strlen($newCode);
    $code = $numLen > count($zeros) ? (string) $newCode : $zeros[count($zeros) - $numLen] . $newCode;

    return $prefix . $code;
}
// make slug
function make_slug($string)
{
    return strtolower(preg_replace('/\s+/u', '-', trim($string)));
}

//credentials check
function credentials($username, $password)
{
    if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
        return ['email' => $username, 'password' => $password];
    } else {
        return ['username' => $username, 'password' => $password];
    }
}

// user access
function checkAccess($accessName)
{
    static $accessCache = [];

    $user = Auth::user();
    if (!$user) {
        return false;
    }

    if ($user->id == 1 || $user->role == 'Superadmin' || $user->role == 'admin') {
        return true;
    }

    if (!array_key_exists($user->id, $accessCache)) {
        $userAccess = UserAccess::where('user_id', $user->id)->first();
        $access = [];
        if (!empty($userAccess) && !empty($userAccess->access)) {
            $decoded = json_decode(json_decode($userAccess->access, true), true);
            if (is_array($decoded)) {
                $access = $decoded;
            }
        }

        if (empty($access)) {
            $role = \App\Models\Role::where('name', $user->role)->first();
            if (!empty($role) && !empty($role->access)) {
                $decoded = json_decode($role->access, true);
                if (is_array($decoded)) {
                    $access = $decoded;
                }
            }
        }

        $accessCache[$user->id] = $access;
    }

    return in_array($accessName, $accessCache[$user->id]);
}

// user action
function buttonAction($action)
{
    $status = false;
    if (Auth::user()->role == 'Superadmin' || Auth::user()->role == 'admin') {
        $status = true;
    } else {
        $actionbtn = explode(",", Auth::user()->action);
        if (in_array($action, $actionbtn)) {
            $status = true;
        }
    }

    return $status;
}

// banglamonth
function bangla_number($int)
{
    $engNumber = array(1, 2, 3, 4, 5, 6, 7, 8, 9, 0);
    $bangNumber = array('১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯', '০');

    $converted = str_replace($engNumber, $bangNumber, $int);
    return $converted;
}

function dateBangla($timestamp = null)
{
    if ($timestamp === null) {
        $timestamp = time();
    }

    // Bengali months
    $banglaMonths = [
        'বৈশাখ',
        'জ্যৈষ্ঠ',
        'আষাঢ়',
        'শ্রাবণ',
        'ভাদ্র',
        'আশ্বিন',
        'কার্তিক',
        'অগ্রহায়ণ',
        'পৌষ',
        'মাঘ',
        'ফাল্গুন',
        'চৈত্র'
    ];

    // Bengali New Year start dates
    $bengaliNewYearStart = [
        1427 => mktime(0, 0, 0, 4, 14, 2020),
        1428 => mktime(0, 0, 0, 4, 14, 2021),
        1429 => mktime(0, 0, 0, 4, 14, 2022),
        1430 => mktime(0, 0, 0, 4, 14, 2023),
        1431 => mktime(0, 0, 0, 4, 14, 2024),
    ];

    $banglaYear = 1427;
    foreach ($bengaliNewYearStart as $year => $startTimestamp) {
        if ($timestamp >= $startTimestamp) {
            $banglaYear = $year;
        } else {
            break;
        }
    }

    $bengaliYearStart = $bengaliNewYearStart[$banglaYear];
    $dayOfYear = floor(($timestamp - $bengaliYearStart) / (60 * 60 * 24));
    $daysInBanglaMonths = [31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 30, 30];
    $banglaMonth = 0;
    $banglaDay = $dayOfYear;

    foreach ($daysInBanglaMonths as $daysInMonth) {
        if ($banglaDay < $daysInMonth) {
            break;
        }
        $banglaDay -= $daysInMonth;
        $banglaMonth++;
    }

    if ($banglaMonth >= count($banglaMonths)) {
        $banglaMonth = count($banglaMonths) - 1;
    }

    $banglaMonthName = $banglaMonths[$banglaMonth];
    $banglaDay += 1;

    return getBanglaDay(date("l", $timestamp)) . ', ' . bangla_number($banglaDay) . ' ' . $banglaMonthName . ' ' . bangla_number($banglaYear);
}

function getBanglaDay($englishDay)
{
    $dayMapping = [
        'Sunday'    => 'রবিবার',
        'Monday'    => 'সোমবার',
        'Tuesday'   => 'মঙ্গলবার',
        'Wednesday' => 'বুধবার',
        'Thursday'  => 'বৃহস্পতিবার',
        'Friday'    => 'শুক্রবার',
        'Saturday'  => 'শনিবার'
    ];
    if (array_key_exists($englishDay, $dayMapping)) {
        return $dayMapping[$englishDay];
    } else {
        return 'Invalid day';
    }
}

// Cached: company profile is read on virtually every request (shared Inertia
// prop, letterhead lookups, etc). Call clearCompanyCache() after any write to
// company_profiles so readers don't see stale data.
function company()
{
    return \Illuminate\Support\Facades\Cache::rememberForever('company_profile', function () {
        return CompanyProfile::first();
    });
}

function clearCompanyCache()
{
    \Illuminate\Support\Facades\Cache::forget('company_profile');
}

// Sends a single transactional SMS (e.g. sale confirmation) through the branch's active
// gateways, trying the default one first, and records the attempt in sms_logs. No-op
// (returns false) if no active gateway is configured — callers should treat that as
// "notification skipped", not an error.
function sendTransactionalSms($branchId, $userId, $phone, string $message, $customerId = null, string $purpose = 'transactional')
{
    if (empty($phone)) {
        return false;
    }

    $gateways = \App\Models\SmsGateway::where('branch_id', $branchId)
        ->where('is_active', true)
        ->orderByDesc('is_default')
        ->get();

    if ($gateways->isEmpty()) {
        return false;
    }

    $delivered = false;
    $usedGateway = null;
    $response = null;

    foreach ($gateways as $gateway) {
        $result = sendSmsViaGateway($gateway, [$phone], $message);
        $usedGateway = $gateway;
        $response = $result['response'];
        if ($result['status']) {
            $delivered = true;
            break;
        }
    }

    \App\Models\SmsLog::create([
        'customer_id' => $customerId,
        'sms_gateway_id' => $usedGateway?->id,
        'gateway_name' => $usedGateway?->name,
        'phone' => $phone,
        'message' => $message,
        'purpose' => $purpose,
        'is_success' => $delivered,
        'response' => $response,
        'created_by' => $userId,
        'created_at' => \Illuminate\Support\Carbon::now(),
        'ipAddress' => request()->ip(),
        'branch_id' => $branchId,
    ]);

    return $delivered;
}

// MRAM (sms.mram.com.bd) documented error codes, used to translate their response
// body into a readable reason instead of a bare status code.
function mramErrorCodes()
{
    return [
        '1002' => 'Sender Id/Masking Not Found',
        '1003' => 'API Not Found',
        '1004' => 'SPAM Detected',
        '1005' => 'Internal Error',
        '1006' => 'Internal Error',
        '1007' => 'Balance Insufficient',
        '1008' => 'Message is empty',
        '1009' => 'Message Type Not Set (text/unicode)',
        '1010' => 'Invalid User & Password',
        '1011' => 'Invalid User Id',
        '1012' => 'Invalid Number',
        '1013' => 'API limit error',
        '1014' => 'No matching template',
        '1015' => 'SMS Content Validation Fails',
        '1016' => 'IP address not allowed',
        '1019' => 'Sms Purpose Missing',
    ];
}

// Sends one SMS blast through a gateway to one or more numbers at once (numbers are
// joined the way each provider expects for a single-message, many-recipients call).
// Built-in provider types ("mram", "gennet") know their own request shape; a "custom"
// gateway falls back to its configured URL template, substituting {number} (numbers
// joined with a comma) and {message}. GET gateways are called as-is; POST gateways
// have their query string split off and sent as the form body instead.
//
// "gennet" is GenNet's Push SMS Gateway at isms.gennet.com.bd — like "mram", its
// base URL is fixed in code rather than configured per-gateway; only the
// account's api_token and sid are entered when setting up the gateway.
function sendSmsViaGateway(\App\Models\SmsGateway $gateway, array $numbers, string $message)
{
    try {
        if ($gateway->provider_type === 'mram') {
            $url = 'https://sms.mram.com.bd/smsapi'
                . '?api_key=' . rawurlencode($gateway->api_key)
                . '&type=' . rawurlencode($gateway->sms_type ?: 'text')
                . '&contacts=' . rawurlencode(implode('+', $numbers))
                . '&senderid=' . rawurlencode($gateway->sender_id ?? '')
                . '&msg=' . rawurlencode($message)
                . '&label=' . rawurlencode($gateway->label ?: 'promotional');

            $response = \Illuminate\Support\Facades\Http::get($url);
            $body = $response->body();

            $codes = mramErrorCodes();
            foreach ($codes as $code => $meaning) {
                if (str_contains($body, $code)) {
                    return ['status' => false, 'response' => "$code: $meaning"];
                }
            }

            return ['status' => $response->successful(), 'response' => $body];
        }

        if ($gateway->provider_type === 'gennet') {
            $base = 'https://isms.gennet.com.bd';
            $csmsId = strtoupper(\Illuminate\Support\Str::random(20));

            if (count($numbers) > 1) {
                $response = \Illuminate\Support\Facades\Http::post("{$base}/api/v3/send-sms/bulk", [
                    'api_token' => $gateway->api_key,
                    'sid' => $gateway->sender_id,
                    'msisdn' => array_values($numbers),
                    'sms' => $message,
                    'batch_csms_id' => $csmsId,
                ]);
            } else {
                $response = \Illuminate\Support\Facades\Http::post("{$base}/api/v3/send-sms", [
                    'api_token' => $gateway->api_key,
                    'sid' => $gateway->sender_id,
                    'msisdn' => $numbers[0] ?? '',
                    'sms' => $message,
                    'csms_id' => $csmsId,
                ]);
            }

            $json = $response->json() ?? [];
            $status = $response->successful() && ($json['status'] ?? null) === 'SUCCESS';

            // status:SUCCESS only means the request was accepted — a bulk/dynamic call can
            // still report every individual recipient as INVALID/DUPLICATE in smsinfo, so
            // only count it delivered once at least one recipient actually went out.
            if ($status && !empty($json['smsinfo'])) {
                $status = collect($json['smsinfo'])->contains(fn ($row) => ($row['sms_status'] ?? null) === 'SUCCESS');
            }

            if (! $status) {
                $reason = $json['error_message'] ?: ($json['smsinfo'][0]['status_message'] ?? null) ?: $response->body();

                return ['status' => false, 'response' => $reason];
            }

            return ['status' => true, 'response' => $response->body()];
        }

        $url = str_replace(
            ['{number}', '{message}'],
            [implode(',', $numbers), rawurlencode($message)],
            $gateway->url_template
        );

        if (strtoupper($gateway->method) === 'POST') {
            $parts = parse_url($url);
            $baseUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '');
            parse_str($parts['query'] ?? '', $params);
            $response = \Illuminate\Support\Facades\Http::asForm()->post($baseUrl, $params);
        } else {
            $response = \Illuminate\Support\Facades\Http::get($url);
        }

        return ['status' => $response->successful(), 'response' => $response->body()];
    } catch (\Throwable $th) {
        return ['status' => false, 'response' => $th->getMessage()];
    }
}
