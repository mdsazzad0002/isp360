<?php

namespace App\Http\Controllers\Isp;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

abstract class IspController extends Controller
{
    protected $userId;
    protected $branchId;

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            $this->branchId = (int) $request->session()->get('branch')->id;
            $this->userId = auth()->user()->id;
            return $next($request);
        });
    }

    protected function page(string $access, string $component, array $props = [])
    {
        if (! checkAccess($access)) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render($component, $props);
    }

    protected function deny(string $access)
    {
        return checkAccess($access) ? null : send_error('You are not authorized for this action', null, 403);
    }

    protected function validateOrFail(array $data, array $rules, array $messages = [])
    {
        $validator = Validator::make($data, $rules, $messages);
        return $validator->fails() ? send_error('Validation Error', $validator->errors(), 422) : null;
    }

    // Business-rule violations (RuntimeException) are user errors; anything else is logged.
    protected function fail(\Throwable $th)
    {
        if ($th instanceof \RuntimeException || $th instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return send_error($th instanceof \RuntimeException ? $th->getMessage() : 'Record not found', null, 422);
        }
        Log::error($th);
        return send_error('Something went wrong', config('app.debug') ? $th->getMessage() : null);
    }

    protected function ok(string $message, array $extra = [])
    {
        return response()->json(['status' => true, 'message' => $message] + $extra);
    }
}
