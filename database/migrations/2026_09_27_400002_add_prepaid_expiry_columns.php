<?php

use App\Services\Isp\ConnectionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Prepaid, time-based service. A service invoice buys `service_months` of internet. The time
// starts when the invoice is fully paid (paid_at) — or when the time already paid for ends, if
// that is later — and period_start/period_end (now with time of day) record the window it
// bought. connections.expire_at is the end of the last window: the line runs until that moment.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dateTime('period_start')->nullable()->change();
            $table->dateTime('period_end')->nullable()->change();
        });
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'service_months')) {
                $table->unsignedTinyInteger('service_months')->nullable()->after('period_key');
                $table->dateTime('paid_at')->nullable()->after('service_months');
            }
        });
        Schema::table('connections', function (Blueprint $table) {
            if (! Schema::hasColumn('connections', 'expire_at')) {
                $table->dateTime('activated_at')->nullable()->after('activation_date');
                $table->dateTime('expire_at')->nullable()->after('next_billing_date')->index();
            }
        });

        // Existing data: activation from the start of its day; period invoices become service
        // invoices of their package's cycle; paid ones are dated by their last allocation.
        DB::table('connections')->whereNotNull('activation_date')->whereNull('activated_at')->update(['activated_at' => DB::raw('activation_date')]);
        DB::table('invoices')->whereNotNull('period_start')->whereNotNull('connection_id')->whereNull('service_months')
            ->update(['service_months' => DB::raw("coalesce((select case packages.billing_cycle when 'quarterly' then 3 when 'half_yearly' then 6 when 'yearly' then 12 else 1 end
                from connections join packages on packages.id = connections.package_id where connections.id = invoices.connection_id), 1)")]);
        DB::table('invoices')->whereNotNull('service_months')->where('status', 'paid')->whereNull('paid_at')
            ->update(['paid_at' => DB::raw("coalesce((select max(created_at) from payment_allocations where payment_allocations.invoice_id = invoices.id and payment_allocations.status = 'active'), invoices.updated_at)")]);

        DB::table('connections')->orderBy('id')->pluck('id')->each(fn ($id) => ConnectionService::refreshExpiry($id, false));
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['activated_at', 'expire_at']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['service_months', 'paid_at']);
        });
    }
};
