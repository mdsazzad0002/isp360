<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "On due": an admin may start a service bill's time before it is paid. credit_at is when that
// time started; the bill stays open (customer due) until paid, and paying it later does not
// move the time. Only the admin panel can grant it (access key connectionCredit).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'credit_at')) {
                $table->dateTime('credit_at')->nullable()->after('paid_at');
                $table->unsignedBigInteger('credit_by')->nullable()->after('credit_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['credit_at', 'credit_by']);
        });
    }
};
