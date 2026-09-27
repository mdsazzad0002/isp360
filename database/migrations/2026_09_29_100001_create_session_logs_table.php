<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lawful session log (roadmap 2.6): who had which IP (and NAT port block) when, from RADIUS
// accounting and MikroTik polling, kept for the company's retention period and searchable by
// IP + time for regulator requests.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->nullable()->index();
            $t->unsignedBigInteger('connection_id')->nullable()->index();
            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->string('source', 10); // radius | mikrotik
            $t->string('source_key', 191); // radacct acctuniqueid / router + session
            $t->string('username', 64)->index();
            $t->string('nas_ip', 45)->nullable();
            $t->string('session_id', 64)->nullable();
            $t->string('framed_ip', 45)->nullable();
            $t->string('framed_ipv6', 64)->nullable();
            $t->string('mac', 50)->nullable();
            // CGNAT: the public address and port block the private IP was translated to (IPAM, 4.6)
            $t->string('nat_ip', 45)->nullable();
            $t->unsignedInteger('nat_port_start')->nullable();
            $t->unsignedInteger('nat_port_end')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('stopped_at')->nullable();
            $t->unsignedBigInteger('upload_bytes')->nullable();
            $t->unsignedBigInteger('download_bytes')->nullable();
            $t->string('terminate_cause', 32)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['source', 'source_key']);
            $t->index(['framed_ip', 'started_at']);
            $t->index(['nat_ip', 'started_at']);
            $t->index('started_at');
        });
        Schema::table('company_profiles', function (Blueprint $t) {
            // days session logs are kept (the country pack's legal minimum by default); 0 = forever
            $t->unsignedInteger('log_retention_days')->default(365);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_logs');
        Schema::table('company_profiles', fn (Blueprint $t) => $t->dropColumn('log_retention_days'));
    }
};
