<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('packages')) {
            Schema::create('packages', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code', 50)->nullable();
                $table->unsignedInteger('download_mbps')->default(0);
                $table->unsignedInteger('upload_mbps')->default(0);
                $table->decimal('price', 14, 2)->default(0);
                $table->enum('billing_cycle', ['monthly', 'quarterly', 'half_yearly', 'yearly'])->default('monthly');
                $table->unsignedInteger('validity_days')->default(30);
                $table->decimal('installation_fee', 14, 2)->default(0);
                $table->decimal('activation_fee', 14, 2)->default(0);
                // Name of the matching profile on the router/RADIUS side, used by the network driver.
                $table->string('network_profile')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->char('status', 1)->default('a');
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->string('ipAddress', 45)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('package_price_histories')) {
            Schema::create('package_price_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('package_id')->index();
                $table->decimal('old_price', 14, 2);
                $table->decimal('new_price', 14, 2);
                $table->string('reason')->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('package_price_histories');
        Schema::dropIfExists('packages');
    }
};
