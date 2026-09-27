<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Site / IP blocking pushed to the branch's MikroTik routers: a domain or an IPv4 address/subnet,
// blocked for every customer or only for one package's customers. Each router keeps the result
// of its last block sync.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('network_blocks')) {
            Schema::create('network_blocks', function (Blueprint $table) {
                $table->id();
                $table->enum('type', ['domain', 'ip']);
                $table->string('value', 255);
                $table->enum('scope', ['all', 'package'])->default('all');
                $table->unsignedBigInteger('package_id')->nullable()->index();
                $table->string('note', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['branch_id', 'value', 'scope', 'package_id']);
            });
        }
        Schema::table('routers', function (Blueprint $table) {
            if (! Schema::hasColumn('routers', 'block_sync_status')) {
                $table->string('block_sync_status', 20)->nullable()->after('last_status'); // synced | failed
                $table->string('block_sync_note', 500)->nullable()->after('block_sync_status');
                $table->dateTime('block_synced_at')->nullable()->after('block_sync_note');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_blocks');
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['block_sync_status', 'block_sync_note', 'block_synced_at']);
        });
    }
};
