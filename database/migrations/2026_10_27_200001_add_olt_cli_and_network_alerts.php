<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// OLT changes over the CLI (authorise / reboot / rename / delete ONUs, vendor commands in
// config/olt_vendors.php) and network alerts: OLT unreachable, PON port down, ONU weak signal.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $t) {
            $t->string('cli_protocol', 10)->default('none')->after('snmp_community'); // none | ssh | telnet
            $t->unsignedSmallInteger('cli_port')->nullable()->after('cli_protocol');
            $t->string('cli_username', 100)->nullable()->after('cli_port');
            $t->text('cli_password')->nullable()->after('cli_username'); // encrypted
            $t->text('cli_enable_password')->nullable()->after('cli_password'); // encrypted, when "enable" asks for one
            $t->json('provision')->nullable()->after('cli_enable_password'); // defaults for authorising: line_profile, service_profile, vlan, gemport, onu_type
        });

        Schema::create('network_alerts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('olt_id')->index();
            $t->unsignedBigInteger('onu_id')->nullable()->index();
            $t->string('kind', 20); // olt_down | pon_down | onu_weak
            $t->string('subject', 100)->nullable(); // PON port, ONU
            $t->string('message', 255);
            $t->timestamp('started_at');
            $t->timestamp('resolved_at')->nullable()->index();
            $t->timestamp('notified_at')->nullable(); // customers told about the outage
            $t->unsignedInteger('notified_count')->nullable();
            $t->timestamps();
            $t->index(['olt_id', 'kind', 'subject', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_alerts');
        Schema::table('olts', fn (Blueprint $t) => $t->dropColumn(['cli_protocol', 'cli_port', 'cli_username', 'cli_password', 'cli_enable_password', 'provision']));
    }
};
