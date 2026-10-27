<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Collectors (roadmap 4.3): the areas each one covers, every door visited (with the outcome and an
// optional GPS stamp), and the cash handed over to the office. Payments themselves stay in
// customer_payments (received_by = the collector); cash in hand is computed, never stored.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collector_areas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('area_id')->index();
            $t->unique(['user_id', 'area_id']);
        });

        Schema::create('collection_visits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('customer_id')->index();
            $t->unsignedBigInteger('payment_id')->nullable();
            $t->string('outcome', 12); // paid | not_home | promised | refused | other
            $t->string('note', 255)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('cash_handovers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('collector_id')->index();
            $t->decimal('amount', 14, 2);
            $t->string('status', 10)->default('pending')->index(); // pending | confirmed | rejected
            $t->string('note', 255)->nullable();
            $t->unsignedBigInteger('confirmed_by')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->string('reject_reason', 255)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_handovers');
        Schema::dropIfExists('collection_visits');
        Schema::dropIfExists('collector_areas');
    }
};
