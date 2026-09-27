<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('routers')) {
            Schema::create('routers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('host');
                $table->unsignedInteger('port')->nullable();
                $table->boolean('use_https')->default(false);
                $table->string('username');
                $table->text('password');
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_checked_at')->nullable();
                $table->string('last_status')->nullable();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('connections', function (Blueprint $table) {
            if (! Schema::hasColumn('connections', 'router_id')) {
                $table->unsignedBigInteger('router_id')->nullable()->after('box_id')->index();
            }
            if (! Schema::hasColumn('connections', 'network_synced_at')) {
                $table->timestamp('network_synced_at')->nullable();
                $table->string('network_sync_error', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            foreach (['router_id', 'network_synced_at', 'network_sync_error'] as $column) {
                if (Schema::hasColumn('connections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::dropIfExists('routers');
    }
};
