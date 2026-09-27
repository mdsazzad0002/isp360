<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Zone -> Area -> Box physical hierarchy. `areas` already exists (POS era), so it is
// only extended with its parent zone and a short code.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zones')) {
            Schema::create('zones', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code', 50)->nullable();
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

        Schema::table('areas', function (Blueprint $table) {
            if (! Schema::hasColumn('areas', 'zone_id')) {
                $table->unsignedBigInteger('zone_id')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('areas', 'code')) {
                $table->string('code', 50)->nullable()->after('name');
            }
            if (! Schema::hasColumn('areas', 'description')) {
                $table->text('description')->nullable()->after('code');
            }
        });

        if (! Schema::hasTable('boxes')) {
            Schema::create('boxes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('area_id')->index();
                $table->string('name');
                $table->string('code', 50)->nullable();
                $table->string('location')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->unsignedInteger('capacity')->default(0);
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
    }

    public function down(): void
    {
        Schema::dropIfExists('boxes');
        Schema::table('areas', function (Blueprint $table) {
            foreach (['zone_id', 'code', 'description'] as $column) {
                if (Schema::hasColumn('areas', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::dropIfExists('zones');
    }
};
