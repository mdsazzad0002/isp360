<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Aggregates every notification-worthy thing into one feed. Every source
 * this used to pull from (stock transfers, branch fund transfers, low
 * stock) belonged to removed modules, so this currently returns an empty
 * feed — adding a new kind of notification later just means adding another
 * private build*() method and folding its rows into index().
 */
class NotificationController extends Controller
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

    public function page()
    {
        return \Inertia\Inertia::render('Notification/Index');
    }

    public function index(Request $request)
    {
        $notifications = collect();

        if (!empty($request->type)) {
            $notifications = $notifications->where('type', $request->type);
        }

        return response()->json($notifications->values());
    }
}
