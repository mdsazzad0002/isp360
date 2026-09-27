<?php

use App\Http\Controllers\AccountHeadController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankTransactionController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPanelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ResellerController;
use App\Http\Controllers\ResellerPanelController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReceiveController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SmsGatewayController;
use App\Http\Controllers\Isp;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;

Route::fallback(function () {
    return \Inertia\Inertia::render('Error/NotFound');
})->middleware('auth');


// PWA manifest (per-tenant — company name/icon vary by install)
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');

// user login route
Route::get('/', [LoginController::class, 'showLoginForm'])->name('login.show');
Route::post('/login', [LoginController::class, 'login'])->name('login');
Route::get('/logout', [DashboardController::class, 'Logout'])->middleware('auth')->name('logout');

//company profile update
Route::get('/companyProfile', [DashboardController::class, 'companyProfile'])->name('companyProfile');
Route::get('/get-companyProfile', [DashboardController::class, 'getcompanyProfile'])->name('getcompanyProfile');
Route::post('/update-companyProfile', [DashboardController::class, 'updatecompanyProfile'])->name('update.companyProfile');

// sms gateway route
Route::get('/sms-gateway', [SmsGatewayController::class, 'create'])->name('sms.gateway.create');
Route::post('/get-sms-gateway', [SmsGatewayController::class, 'index'])->name('sms.gateway.index');
Route::post('/sms-gateway', [SmsGatewayController::class, 'store'])->name('sms.gateway.store');
Route::post('/update-sms-gateway', [SmsGatewayController::class, 'update'])->name('sms.gateway.update');
Route::post('/delete-sms-gateway', [SmsGatewayController::class, 'destroy'])->name('sms.gateway.delete');
Route::post('/toggle-sms-gateway', [SmsGatewayController::class, 'toggleActive'])->name('sms.gateway.toggleActive');
Route::post('/default-sms-gateway', [SmsGatewayController::class, 'setDefault'])->name('sms.gateway.setDefault');
Route::get('/sms-promotion', [SmsGatewayController::class, 'promotionPage'])->name('sms.promotion.create');
Route::post('/send-sms-promotion', [SmsGatewayController::class, 'sendPromotion'])->name('sms.promotion.send');
Route::get('/sms-log', [SmsGatewayController::class, 'logPage'])->name('sms.log.create');
Route::post('/get-sms-log', [SmsGatewayController::class, 'getLog'])->name('sms.log.index');

//branch manage settings
Route::get('/branchManage', [DashboardController::class, 'branchManage'])->name('branchManage');
Route::post('/update-branchManage', [DashboardController::class, 'updateBranchManage'])->name('update.branchManage');
Route::post('/switch-branch', [DashboardController::class, 'switchBranch'])->name('switch.branch');

Route::get('/get-headerInfo', [DashboardController::class, 'getHeaderInfo'])->name('get.headerInfo');
Route::get('/global-search', [DashboardController::class, 'globalSearch'])->name('global.search');

//panel and dashboard route
Route::group(['prefix' => 'panel'], function () {
    Route::get('/', function () {
        return redirect()->route('dashboard');
    });
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/{panel}', [DashboardController::class, 'panel'])->name('panel.access');
});

// reseller portal route (self-service, guarded by the 'reseller' auth guard)
Route::group(['prefix' => 'reseller', 'middleware' => 'auth:reseller'], function () {
    Route::get('/dashboard', [ResellerPanelController::class, 'dashboard'])->name('reseller.dashboard');
    Route::get('/profile', [ResellerPanelController::class, 'profile'])->name('reseller.profile');
    Route::post('/update-profile', [ResellerPanelController::class, 'updateProfile'])->name('reseller.profile.update');
    Route::get('/packages', [ResellerPanelController::class, 'packages'])->name('reseller.packages');
    Route::post('/get-packages', [ResellerPanelController::class, 'getPackages'])->name('reseller.packages.index');
    Route::post('/package', [ResellerPanelController::class, 'storePackage'])->name('reseller.package.store');
    Route::post('/delete-package', [ResellerPanelController::class, 'destroyPackage'])->name('reseller.package.delete');
    Route::get('/connections', [ResellerPanelController::class, 'connections'])->name('reseller.connections');
    Route::get('/payments', [ResellerPanelController::class, 'payments'])->name('reseller.payments');
    Route::post('/get-payments', [ResellerPanelController::class, 'getPayments'])->name('reseller.payments.index');
    Route::post('/get-customer-dues', [ResellerPanelController::class, 'customerDues'])->name('reseller.customer.dues');
    Route::post('/payment', [ResellerPanelController::class, 'storePayment'])->name('reseller.payment.store');
    Route::get('/withdrawals', [ResellerPanelController::class, 'withdrawals'])->name('reseller.withdrawals');
    Route::post('/get-withdrawals', [ResellerPanelController::class, 'getWithdrawals'])->name('reseller.withdrawals.index');
    Route::post('/withdrawal', [ResellerPanelController::class, 'storeWithdrawal'])->name('reseller.withdrawal.store');
    Route::post('/withdrawal-cancel', [ResellerPanelController::class, 'cancelWithdrawal'])->name('reseller.withdrawal.cancel');
    Route::get('/ledger', [ResellerPanelController::class, 'ledger'])->name('reseller.ledger');
    Route::post('/get-ledger', [ResellerPanelController::class, 'getLedger'])->name('reseller.ledger.data');
    Route::get('/logout', [ResellerPanelController::class, 'logout'])->name('reseller.logout');

    Route::get('/tickets', [\App\Http\Controllers\PortalTicketController::class, 'page'])->name('reseller.tickets');
    Route::post('/get-tickets', [\App\Http\Controllers\PortalTicketController::class, 'index'])->name('reseller.tickets.index');
    Route::post('/get-ticket', [\App\Http\Controllers\PortalTicketController::class, 'show'])->name('reseller.ticket.show');
    Route::post('/ticket', [\App\Http\Controllers\PortalTicketController::class, 'store'])->middleware('throttle:20,1')->name('reseller.ticket.store');
    Route::post('/ticket-reply', [\App\Http\Controllers\PortalTicketController::class, 'reply'])->middleware('throttle:30,1')->name('reseller.ticket.reply');
    Route::post('/ticket-status', [\App\Http\Controllers\PortalTicketController::class, 'status'])->name('reseller.ticket.status');
});

// customer portal route (self-service, guarded by the 'customer' auth guard)
Route::group(['prefix' => 'customer-portal', 'middleware' => 'auth:customer'], function () {
    Route::get('/dashboard', [CustomerPanelController::class, 'dashboard'])->name('customerPortal.dashboard');
    Route::get('/profile', [CustomerPanelController::class, 'profile'])->name('customerPortal.profile');
    Route::get('/connections', [CustomerPanelController::class, 'connections'])->name('customerPortal.connections');
    Route::post('/invoice', [CustomerPanelController::class, 'invoice'])->name('customerPortal.invoice');
    Route::post('/statement', [CustomerPanelController::class, 'statement'])->name('customerPortal.statement');
    Route::post('/update-profile', [CustomerPanelController::class, 'updateProfile'])->name('customerPortal.profile.update');
    Route::get('/logout', [CustomerPanelController::class, 'logout'])->name('customerPortal.logout');
    Route::get('/pay', [\App\Http\Controllers\CustomerPortalPaymentController::class, 'page'])->name('customerPortal.pay');
    Route::post('/pay/start', [\App\Http\Controllers\CustomerPortalPaymentController::class, 'start'])->middleware('throttle:10,1')->name('customerPortal.pay.start');
    Route::post('/pay/manual', [\App\Http\Controllers\CustomerPortalPaymentController::class, 'manual'])->middleware('throttle:10,1')->name('customerPortal.pay.manual');

    Route::get('/tickets', [\App\Http\Controllers\PortalTicketController::class, 'page'])->name('customerPortal.tickets');
    Route::post('/get-tickets', [\App\Http\Controllers\PortalTicketController::class, 'index'])->name('customerPortal.tickets.index');
    Route::post('/get-ticket', [\App\Http\Controllers\PortalTicketController::class, 'show'])->name('customerPortal.ticket.show');
    Route::post('/ticket', [\App\Http\Controllers\PortalTicketController::class, 'store'])->middleware('throttle:10,1')->name('customerPortal.ticket.store');
    Route::post('/ticket-reply', [\App\Http\Controllers\PortalTicketController::class, 'reply'])->middleware('throttle:30,1')->name('customerPortal.ticket.reply');
    Route::post('/ticket-status', [\App\Http\Controllers\PortalTicketController::class, 'status'])->name('customerPortal.ticket.status');
});

// ============================= Control Panel Route ==============================
Route::get('/branchset/{id}', [DashboardController::class, 'branchset'])->name('set.branch');
// branch route
Route::get('/branch', [BranchController::class, 'create'])->name('branch.create');
Route::match(['get', 'post'], '/get-branch', [BranchController::class, 'index'])->name('branch.index');
Route::post('/branch', [BranchController::class, 'store'])->name('branch.store');
Route::post('/update-branch', [BranchController::class, 'update'])->name('branch.update');
Route::post('/delete-branch', [BranchController::class, 'destroy'])->name('branch.delete');

// user route
Route::get('/user', [UserController::class, 'create'])->name('user.create');
Route::get('/user-profile', [UserController::class, 'profile'])->name('user.profile');
Route::post('/get-user', [UserController::class, 'index'])->name('user.index');
Route::post('/user', [UserController::class, 'store'])->name('user.store');
Route::post('/update-user', [UserController::class, 'update'])->name('user.update');
Route::post('/delete-user', [UserController::class, 'destroy'])->name('user.delete');

// user switch (login as / switch back) route
Route::get('/user/{id}/login-as', [UserController::class, 'loginAs'])->middleware('auth')->name('user.loginAs');
Route::get('/customer/{id}/login-as', [CustomerController::class, 'loginAs'])->middleware('auth')->name('customer.loginAs');
Route::get('/reseller/{id}/login-as', [ResellerController::class, 'loginAs'])->middleware('auth')->whereNumber('id')->name('reseller.loginAs');
Route::get('/switch-back', [UserController::class, 'switchBack'])->middleware('auth')->name('user.switchBack');

// role route
Route::get('/role', [RoleController::class, 'create'])->name('role.create');
Route::post('/get-role', [RoleController::class, 'index'])->name('role.index');
Route::post('/role', [RoleController::class, 'store'])->name('role.store');
Route::post('/update-role', [RoleController::class, 'update'])->name('role.update');
Route::post('/delete-role', [RoleController::class, 'destroy'])->name('role.delete');

// role access route
Route::get('/roleAccess/{id}', [RoleController::class, 'roleAccess'])->name('roleAccess.create');
Route::post('/get-roleAccess', [RoleController::class, 'getRoleAccess'])->name('roleAccess.index');
Route::post('/save-roleAccess', [RoleController::class, 'saveRoleAccess'])->name('roleAccess.save');

// company route
Route::get('/company', [CompanyController::class, 'create'])->name('company.create');
Route::match(['get', 'post'], '/get-company', [CompanyController::class, 'index'])->name('company.index');
Route::post('/company', [CompanyController::class, 'store'])->name('company.store');
Route::post('/update-company', [CompanyController::class, 'update'])->name('company.update');
Route::post('/delete-company', [CompanyController::class, 'destroy'])->name('company.delete');
Route::get('/deleted-company-record', [CompanyController::class, 'deletedRecord'])->name('company.record.deleted');
Route::post('/get-deleted-company', [CompanyController::class, 'getDeleted'])->name('get.deleted.company');
Route::post('/restore-company', [CompanyController::class, 'restore'])->name('company.restore');

// area route
Route::get('/area', [AreaController::class, 'create'])->name('area.create');
Route::match(['get', 'post'], '/get-area', [AreaController::class, 'index'])->name('area.index');
Route::post('/area', [AreaController::class, 'store'])->name('area.store');
Route::post('/update-area', [AreaController::class, 'update'])->name('area.update');
Route::post('/delete-area', [AreaController::class, 'destroy'])->name('area.delete');
Route::get('/deleted-area-record', [AreaController::class, 'deletedRecord'])->name('area.record.deleted');
Route::post('/get-deleted-area', [AreaController::class, 'getDeleted'])->name('get.deleted.area');
Route::post('/restore-area', [AreaController::class, 'restore'])->name('area.restore');

// customer route
Route::get('/customer', [CustomerController::class, 'create'])->name('customer.create');
Route::redirect('/customerList', '/customer')->name('customer.list');
Route::match(['get', 'post'], '/get-customer', [CustomerController::class, 'index'])->name('customer.index');
Route::get('/customer/export-excel', [CustomerController::class, 'exportExcel'])->name('customer.export-excel');
Route::post('/customer/import-batch', [CustomerController::class, 'importBatch'])->name('customer.import-batch');
Route::post('/customer', [CustomerController::class, 'store'])->name('customer.store');
Route::post('/update-customer', [CustomerController::class, 'update'])->name('customer.update');
Route::post('/delete-customer', [CustomerController::class, 'destroy'])->name('customer.delete');
Route::get('/customerDue', [CustomerController::class, 'customerDue'])->name('customer.due');
Route::post('/get-customerDue', [CustomerController::class, 'getCustomerDue'])->name('get.customer.due');
Route::get('/customerDue/export-excel', [CustomerController::class, 'customerDueExportExcel'])->name('customer.due.export-excel');
Route::get('/customerLedger', [CustomerController::class, 'customerLedger'])->name('customer.ledger');
Route::post('/get-customer-ledger', [CustomerController::class, 'getCustomerLedger'])->name('get.customer.ledger');
Route::get('/deleted-customer-record', [CustomerController::class, 'deletedCustomerRecord'])->name('customer.record.deleted');
Route::post('/get-deleted-customer', [CustomerController::class, 'getDeletedCustomer'])->name('get.deleted.customer');
Route::post('/restore-customer', [CustomerController::class, 'restoreCustomer'])->name('customer.restore');

// reseller route
Route::get('/reseller', [ResellerController::class, 'create'])->name('reseller.create');
Route::match(['get', 'post'], '/get-reseller', [ResellerController::class, 'index'])->name('reseller.index');
Route::post('/reseller', [ResellerController::class, 'store'])->name('reseller.store');
Route::post('/update-reseller', [ResellerController::class, 'update'])->name('reseller.update');
Route::post('/delete-reseller', [ResellerController::class, 'destroy'])->name('reseller.delete');
Route::get('/reseller-export-excel', [ResellerController::class, 'exportExcel'])->name('reseller.export-excel');
Route::post('/reseller-import-batch', [ResellerController::class, 'importBatch'])->name('reseller.import-batch');

// ============================= Account Panel Route ==============================
// account head route
Route::get('/accounthead', [AccountHeadController::class, 'create'])->name('accounthead.create');
Route::match(['get', 'post'], '/get-accounthead', [AccountHeadController::class, 'index'])->name('accounthead.index');
Route::post('/accounthead', [AccountHeadController::class, 'store'])->name('accounthead.store');
Route::post('/update-accounthead', [AccountHeadController::class, 'update'])->name('accounthead.update');
Route::post('/delete-accounthead', [AccountHeadController::class, 'destroy'])->name('accounthead.delete');
Route::get('/accounthead/export-excel', [AccountHeadController::class, 'exportExcel'])->name('accounthead.export-excel');
Route::post('/accounthead/import-batch', [AccountHeadController::class, 'importBatch'])->name('accounthead.import-batch');
Route::get('/deleted-accounthead-record', [AccountHeadController::class, 'deletedAccountHeadRecord'])->name('accounthead.record.deleted');
Route::post('/get-deleted-accounthead', [AccountHeadController::class, 'getDeletedAccountHead'])->name('get.deleted.accounthead');
Route::post('/restore-accounthead', [AccountHeadController::class, 'restoreAccountHead'])->name('accounthead.restore');

Route::get('/cashLedger', [AccountHeadController::class, 'cashLedger'])->name('cash.ledger');
Route::post('/get-cash-ledger', [AccountHeadController::class, 'getCashLedger'])->name('get.cash.ledger');
Route::post('/get-dayBook-cash-detail', [AccountHeadController::class, 'getDayBookCashDetail'])->name('get.dayBook.cash.detail');
Route::get('/cashBankLedger', [AccountHeadController::class, 'cashBankLedger'])->name('cashbank.ledger');
Route::post('/get-cashBalance', [AccountHeadController::class, 'getCashBalance'])->name('get.cash.balance');

// bank route
Route::get('/bank', [BankController::class, 'create'])->name('bank.create');
Route::match(['get', 'post'], '/get-bank', [BankController::class, 'index'])->name('bank.index');
Route::post('/bank', [BankController::class, 'store'])->name('bank.store');
Route::post('/update-bank', [BankController::class, 'update'])->name('bank.update');
Route::post('/delete-bank', [BankController::class, 'destroy'])->name('bank.delete');
Route::get('/bank/export-excel', [BankController::class, 'exportExcel'])->name('bank.export-excel');
Route::get('/bankLedger', [BankController::class, 'bankLedger'])->name('bank.ledger');
Route::post('/get-bank-ledger', [BankController::class, 'getBankLedger'])->name('get.bank.ledger');
Route::post('/get-dayBook-bank-detail', [BankController::class, 'getDayBookBankDetail'])->name('get.dayBook.bank.detail');
Route::post('/get-bankBalance', [BankController::class, 'getBankBalance'])->name('get.bank.balance');
Route::get('/deleted-bank-record', [BankController::class, 'deletedBankRecord'])->name('bank.record.deleted');
Route::post('/get-deleted-bank', [BankController::class, 'getDeletedBank'])->name('get.deleted.bank');
Route::post('/restore-bank', [BankController::class, 'restoreBank'])->name('bank.restore');

// expense route
Route::get('/expense', [TransactionController::class, 'expense'])->name('expense.create');
Route::get('/income', [TransactionController::class, 'income'])->name('income.create');
Route::match(['get', 'post'], '/get-transaction', [TransactionController::class, 'index'])->name('transaction.index');
Route::post('/transaction', [TransactionController::class, 'store'])->name('transaction.store');
Route::post('/update-transaction', [TransactionController::class, 'update'])->name('transaction.update');
Route::post('/delete-transaction', [TransactionController::class, 'destroy'])->name('transaction.delete');
Route::post('/get-transaction-invoice', [TransactionController::class, 'getInvoice'])->name('transaction.invoice');
Route::get('/transaction/export-excel', [TransactionController::class, 'exportExcel'])->name('transaction.export-excel');

// bankTransaction route
Route::get('/bankTransaction', [BankTransactionController::class, 'create'])->name('bankTransaction.create');
Route::match(['get', 'post'], '/get-bankTransaction', [BankTransactionController::class, 'index'])->name('bankTransaction.index');
Route::post('/bankTransaction', [BankTransactionController::class, 'store'])->name('bankTransaction.store');
Route::post('/update-bankTransaction', [BankTransactionController::class, 'update'])->name('bankTransaction.update');
Route::post('/delete-bankTransaction', [BankTransactionController::class, 'destroy'])->name('bankTransaction.delete');
Route::get('/deleted-bankTransaction-record', [BankTransactionController::class, 'deletedBankTransactionRecord'])->name('bankTransaction.record.deleted');
Route::post('/get-deleted-bankTransaction', [BankTransactionController::class, 'getDeletedBankTransaction'])->name('get.deleted.bankTransaction');
Route::post('/restore-bankTransaction', [BankTransactionController::class, 'restoreBankTransaction'])->name('bankTransaction.restore');

// receive route
Route::get('/receive', [ReceiveController::class, 'create'])->name('receive.create');
Route::match(['get', 'post'], '/get-receive', [ReceiveController::class, 'index'])->name('receive.index');
Route::post('/receive', [ReceiveController::class, 'store'])->name('receive.store');
Route::post('/update-receive', [ReceiveController::class, 'update'])->name('receive.update');
Route::post('/delete-receive', [ReceiveController::class, 'destroy'])->name('receive.delete');
Route::get('/receive/export-excel', [ReceiveController::class, 'exportExcel'])->name('receive.export-excel');
Route::post('/get-receive-invoice', [ReceiveController::class, 'getInvoice'])->name('receive.invoice');
Route::post('/get-receive-record', [ReceiveController::class, 'show'])->name('receive.record');

// payment route
Route::get('/payment', [PaymentController::class, 'create'])->name('payment.create');
Route::match(['get', 'post'], '/get-payment', [PaymentController::class, 'index'])->name('payment.index');
Route::post('/payment', [PaymentController::class, 'store'])->name('payment.store');
Route::post('/update-payment', [PaymentController::class, 'update'])->name('payment.update');
Route::post('/delete-payment', [PaymentController::class, 'destroy'])->name('payment.delete');
Route::get('/payment/export-excel', [PaymentController::class, 'exportExcel'])->name('payment.export-excel');
Route::post('/get-payment-invoice', [PaymentController::class, 'getInvoice'])->name('payment.invoice');
Route::post('/get-payment-record', [PaymentController::class, 'show'])->name('payment.record');
Route::get('/deleted-payment-record', [PaymentController::class, 'deletedPaymentRecord'])->name('payment.record.deleted');
Route::post('/get-deleted-payment', [PaymentController::class, 'getDeletedPayment'])->name('get.deleted.payment');
Route::post('/restore-payment', [PaymentController::class, 'restorePayment'])->name('payment.restore');

// notifications (aggregated feed)
Route::get('/notifications', [NotificationController::class, 'page'])->name('notifications.page');
Route::match(['get', 'post'], '/get-notifications', [NotificationController::class, 'index'])->name('notifications.index');

// ============================= Report Panel Route ==============================
Route::post('/get-transaction-list-light', [ReportController::class, 'getTransactionList'])->name('get.transaction.list.light');

Route::get('/dayBook', [ReportController::class, 'dayBook'])->name('dayBook');
Route::post('/get-dayBook', [ReportController::class, 'getDayBook'])->name('get.dayBook');
Route::get('/balanceSheet', [ReportController::class, 'balanceSheet'])->name('balanceSheet');
Route::post('/get-balanceSheet', [ReportController::class, 'getBalanceSheet'])->name('get.balanceSheet');
Route::post('/get-balanceSheet-detail', [ReportController::class, 'getBalanceSheetDetail'])->name('get.balanceSheet.detail');

// ============================= ISP Management Route ==============================
Route::group(['prefix' => 'isp', 'middleware' => 'auth'], function () {
    // Zone / Area / Box (areas use the existing /area endpoints)
    Route::redirect('/locations', '/isp/zones');
    Route::get('/zones', [Isp\LocationController::class, 'zonePage'])->name('isp.zones.page');
    Route::get('/areas', [Isp\LocationController::class, 'areaPage'])->name('isp.areas.page');
    Route::get('/boxes', [Isp\LocationController::class, 'boxPage'])->name('isp.boxes.page');
    Route::post('/get-zones', [Isp\LocationController::class, 'zones'])->name('isp.zones');
    Route::post('/zone', [Isp\LocationController::class, 'storeZone'])->name('isp.zone.store');
    Route::post('/delete-zone', [Isp\LocationController::class, 'destroyZone'])->name('isp.zone.delete');
    Route::post('/get-boxes', [Isp\LocationController::class, 'boxes'])->name('isp.boxes');
    Route::post('/box', [Isp\LocationController::class, 'storeBox'])->name('isp.box.store');
    Route::post('/delete-box', [Isp\LocationController::class, 'destroyBox'])->name('isp.box.delete');

    Route::get('/packages', [Isp\PackageController::class, 'create'])->name('isp.packages');
    Route::post('/get-packages', [Isp\PackageController::class, 'index'])->name('isp.packages.index');
    Route::post('/get-package-history', [Isp\PackageController::class, 'history'])->name('isp.packages.history');
    Route::post('/package', [Isp\PackageController::class, 'store'])->name('isp.package.store');
    Route::post('/delete-package', [Isp\PackageController::class, 'destroy'])->name('isp.package.delete');

    Route::get('/reseller-requests', [Isp\ResellerRequestController::class, 'create'])->name('isp.resellerRequests');
    Route::get('/reseller-packages', [Isp\ResellerRequestController::class, 'packagesPage'])->name('isp.resellerPackages');
    Route::get('/reseller-ledger', [Isp\ResellerRequestController::class, 'ledgerPage'])->name('isp.resellerLedger');
    Route::post('/get-reseller-ledger', [Isp\ResellerRequestController::class, 'ledger'])->name('isp.resellerLedger.data');

    Route::get('/tickets', [Isp\TicketController::class, 'create'])->name('isp.tickets');
    Route::post('/get-tickets', [Isp\TicketController::class, 'index'])->name('isp.tickets.index');
    Route::post('/get-ticket', [Isp\TicketController::class, 'show'])->name('isp.ticket.show');
    Route::post('/ticket', [Isp\TicketController::class, 'store'])->name('isp.ticket.store');
    Route::post('/ticket-reply', [Isp\TicketController::class, 'reply'])->name('isp.ticket.reply');
    Route::post('/ticket-status', [Isp\TicketController::class, 'status'])->name('isp.ticket.status');
    Route::post('/ticket-update', [Isp\TicketController::class, 'update'])->name('isp.ticket.update');
    Route::post('/get-reseller-package-requests', [Isp\ResellerRequestController::class, 'packages'])->name('isp.resellerRequests.packages');
    Route::post('/get-reseller-wallets', [Isp\ResellerRequestController::class, 'wallets'])->name('isp.resellerRequests.wallets');
    Route::post('/get-reseller-transactions', [Isp\ResellerRequestController::class, 'transactions'])->name('isp.resellerRequests.transactions');
    Route::post('/reseller-withdrawal-pay', [Isp\ResellerRequestController::class, 'pay'])->name('isp.resellerRequests.pay');
    Route::post('/reseller-withdrawal-reject', [Isp\ResellerRequestController::class, 'reject'])->name('isp.resellerRequests.reject');
    Route::post('/reseller-deposit', [Isp\ResellerRequestController::class, 'deposit'])->name('isp.resellerRequests.deposit');

    Route::get('/payment-gateways', [Isp\PaymentGatewayController::class, 'create'])->name('isp.paymentGateways');
    Route::post('/get-payment-gateways', [Isp\PaymentGatewayController::class, 'index'])->name('isp.paymentGateways.index');
    Route::post('/payment-gateway', [Isp\PaymentGatewayController::class, 'store'])->name('isp.paymentGateway.store');
    Route::get('/online-payments', [Isp\OnlinePaymentController::class, 'create'])->name('isp.onlinePayments');
    Route::post('/get-online-payments', [Isp\OnlinePaymentController::class, 'index'])->name('isp.onlinePayments.index');
    Route::post('/online-payment-approve', [Isp\OnlinePaymentController::class, 'approve'])->name('isp.onlinePayment.approve');
    Route::post('/online-payment-reject', [Isp\OnlinePaymentController::class, 'reject'])->name('isp.onlinePayment.reject');

    Route::get('/connections', [Isp\ConnectionController::class, 'create'])->name('isp.connections');
    Route::post('/get-connections', [Isp\ConnectionController::class, 'index'])->name('isp.connections.index');
    Route::post('/get-connection', [Isp\ConnectionController::class, 'show'])->name('isp.connection.show');
    Route::post('/get-connection-secret', [Isp\ConnectionController::class, 'secret'])->name('isp.connection.secret');
    Route::post('/connection', [Isp\ConnectionController::class, 'store'])->name('isp.connection.store');
    Route::post('/connection-action', [Isp\ConnectionController::class, 'action'])->name('isp.connection.action');
    Route::post('/connection-pay-quote', [Isp\ConnectionController::class, 'payQuote'])->name('isp.connection.payQuote');
    Route::post('/connection-pay', [Isp\ConnectionController::class, 'pay'])->name('isp.connection.pay');
    Route::post('/connection-change-package', [Isp\ConnectionController::class, 'changePackage'])->name('isp.connection.package');
    Route::post('/connection-sync', [Isp\ConnectionController::class, 'sync'])->name('isp.connection.sync');
    Route::post('/connection-online', [Isp\ConnectionController::class, 'online'])->name('isp.connection.online');
    Route::post('/connection-traffic', [Isp\ConnectionController::class, 'traffic'])->middleware('throttle:90,1')->name('isp.connection.traffic');
    Route::post('/connection-verify', [Isp\ConnectionController::class, 'verify'])->middleware('throttle:30,1')->name('isp.connection.verify');
    Route::post('/connection-terminal', [Isp\ConnectionController::class, 'terminal'])->middleware('throttle:60,1')->name('isp.connection.terminal');

    Route::get('/routers', [Isp\RouterController::class, 'create'])->name('isp.routers');
    Route::post('/get-routers', [Isp\RouterController::class, 'index'])->name('isp.routers.index');
    Route::post('/router', [Isp\RouterController::class, 'store'])->name('isp.router.store');
    Route::post('/router-test', [Isp\RouterController::class, 'test'])->name('isp.router.test');
    Route::post('/router-readiness', [Isp\RouterController::class, 'readiness'])->middleware('throttle:30,1')->name('isp.router.readiness');
    Route::post('/router-sessions', [Isp\RouterController::class, 'sessions'])->name('isp.router.sessions');
    Route::post('/router-sync-all', [Isp\RouterController::class, 'syncAll'])->name('isp.router.sync');
    Route::post('/delete-router', [Isp\RouterController::class, 'destroy'])->name('isp.router.delete');

    Route::get('/customer/{id}', [Isp\CustomerProfileController::class, 'show'])->whereNumber('id')->name('isp.customer.show');
    Route::post('/get-customer-profile', [Isp\CustomerProfileController::class, 'data'])->name('isp.customer.data');
    Route::post('/get-customer-statement', [Isp\CustomerProfileController::class, 'ledger'])->name('isp.customer.ledger');

    Route::get('/invoices', [Isp\InvoiceController::class, 'create'])->name('isp.invoices');
    Route::post('/get-invoices', [Isp\InvoiceController::class, 'index'])->name('isp.invoices.index');
    Route::post('/get-invoice', [Isp\InvoiceController::class, 'show'])->name('isp.invoice.show');
    Route::post('/invoice', [Isp\InvoiceController::class, 'store'])->name('isp.invoice.store');
    Route::post('/invoice-issue', [Isp\InvoiceController::class, 'issue'])->name('isp.invoice.issue');
    Route::post('/invoice-void', [Isp\InvoiceController::class, 'void'])->name('isp.invoice.void');
    Route::post('/invoice-note', [Isp\InvoiceController::class, 'note'])->name('isp.invoice.note');
    Route::post('/invoice-generate', [Isp\InvoiceController::class, 'generate'])->name('isp.invoice.generate');

    Route::get('/payments', [Isp\PaymentController::class, 'create'])->name('isp.payments');
    Route::post('/get-payments', [Isp\PaymentController::class, 'index'])->name('isp.payments.index');
    Route::post('/get-payment', [Isp\PaymentController::class, 'show'])->name('isp.payment.show');
    Route::post('/get-customer-dues', [Isp\PaymentController::class, 'customerDues'])->name('isp.customer.dues');
    Route::post('/payment', [Isp\PaymentController::class, 'store'])->name('isp.payment.store');
    Route::post('/payment-reverse', [Isp\PaymentController::class, 'reverse'])->name('isp.payment.reverse');
    Route::post('/payment-reallocate', [Isp\PaymentController::class, 'reallocate'])->name('isp.payment.reallocate');
    Route::post('/payment-refund', [Isp\PaymentController::class, 'refund'])->name('isp.payment.refund');

    Route::post('/get-dashboard', [Isp\ReportController::class, 'dashboard'])->name('isp.dashboard');
    Route::get('/bandwidth', [Isp\BandwidthController::class, 'create'])->name('isp.bandwidth');
    Route::post('/get-bandwidth', [Isp\BandwidthController::class, 'index'])->name('isp.bandwidth.index');
    Route::post('/bandwidth', [Isp\BandwidthController::class, 'store'])->name('isp.bandwidth.store');
    Route::post('/delete-bandwidth', [Isp\BandwidthController::class, 'destroy'])->name('isp.bandwidth.delete');
    Route::get('/bandwidth-usage', [Isp\BandwidthController::class, 'usagePage'])->name('isp.bandwidth.usage');
    Route::post('/get-bandwidth-usage', [Isp\BandwidthController::class, 'usage'])->name('isp.bandwidth.usage.data');
    Route::get('/bandwidth-profit', [Isp\BandwidthController::class, 'profitPage'])->name('isp.bandwidth.profit');
    Route::post('/get-bandwidth-profit', [Isp\BandwidthController::class, 'profit'])->name('isp.bandwidth.profit.data');
    Route::get('/due-report', [Isp\ReportController::class, 'dueReport'])->name('isp.due.report');
    Route::post('/get-due-report', [Isp\ReportController::class, 'getDueReport'])->name('isp.due.report.data');
    Route::post('/get-due-summary', [Isp\ReportController::class, 'dueSummary'])->name('isp.due.summary');
    Route::get('/collection-report', [Isp\ReportController::class, 'collectionReport'])->name('isp.collection.report');
    Route::post('/get-collection-report', [Isp\ReportController::class, 'getCollectionReport'])->name('isp.collection.report.data');

    Route::get('/settings', [Isp\SettingController::class, 'create'])->name('isp.settings');
    Route::post('/get-settings', [Isp\SettingController::class, 'show'])->name('isp.settings.show');
    Route::post('/settings', [Isp\SettingController::class, 'update'])->name('isp.settings.update');

    Route::get('/audit-log', [Isp\AuditLogController::class, 'create'])->name('isp.audit');
    Route::post('/get-audit-log', [Isp\AuditLogController::class, 'index'])->name('isp.audit.index');
});
