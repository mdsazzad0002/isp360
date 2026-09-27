<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Indexes for the every-minute billing jobs, so each run reads only the rows that crossed a
// boundary (paid time ended, renewal due, invoice past due) instead of scanning a branch:
// isp:process-overdue (autoSuspend, markOverdue) and isp:generate-invoices.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $t) {
            $t->index(['branch_id', 'status', 'expire_at'], 'connections_branch_status_expire_index');
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->index(['branch_id', 'status', 'due_date'], 'invoices_branch_status_due_date_index');
            $t->index(['connection_id', 'status'], 'invoices_connection_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('connections', fn (Blueprint $t) => $t->dropIndex('connections_branch_status_expire_index'));
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropIndex('invoices_branch_status_due_date_index');
            $t->dropIndex('invoices_connection_status_index');
        });
    }
};
