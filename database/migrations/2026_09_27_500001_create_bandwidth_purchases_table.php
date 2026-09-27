<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Upstream bandwidth the ISP buys (IIG / NTTN / transit / cache...), as a monthly cost that
// runs from start_date until end_date (open-ended when null). Compared against the bandwidth
// sold on active connections, and charged against billing revenue in the profit report.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bandwidth_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 150);
            $table->enum('type', ['iig', 'nttn', 'transit', 'cache', 'other'])->default('iig');
            $table->decimal('bandwidth_mbps', 12, 2);
            $table->decimal('monthly_cost', 14, 2);
            $table->date('start_date')->index();
            $table->date('end_date')->nullable()->index();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bandwidth_purchases');
    }
};
