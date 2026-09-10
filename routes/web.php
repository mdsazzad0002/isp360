<?php

use App\Http\Controllers\AccountHeadController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankTransactionController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReceiveController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SmsGatewayController;
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
