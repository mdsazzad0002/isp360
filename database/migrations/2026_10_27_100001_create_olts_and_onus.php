<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// OLT / ONU (roadmap 4.6): the OLTs of a branch, read over SNMP (vendor OIDs in config/olt_vendors.php),
// and the ONUs on their PON ports, each optionally linked to the customer's connection.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->string('name', 100);
            $t->string('vendor', 20); // key of config/olt_vendors.php
            $t->string('model', 100)->nullable();
            $t->string('host', 255);
            $t->unsignedSmallInteger('snmp_port')->default(161);
            $t->text('snmp_community')->nullable(); // encrypted, SNMP v2c read community
            $t->string('location', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_checked_at')->nullable();
            $t->string('last_status', 255)->nullable();
            $t->timestamp('last_polled_at')->nullable();
            $t->string('notes', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('onus', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('olt_id')->index();
            $t->string('pon_port', 50); // as the OLT names it: "GPON 0/1/3", "EPON0/2"
            $t->unsignedInteger('onu_id')->nullable(); // ONU number on that port
            $t->string('serial', 32)->nullable()->index(); // GPON serial, e.g. HWTC1A2B3C4D
            $t->string('mac', 17)->nullable()->index(); // EPON ONU MAC
            $t->string('name', 100)->nullable(); // description on the OLT
            $t->unsignedBigInteger('connection_id')->nullable()->unique();
            $t->string('status', 20)->default('unknown'); // online | offline | los | dying_gasp | auth_failed | not_found | unknown
            $t->decimal('rx_power', 6, 2)->nullable(); // dBm, ONU receive power as the OLT reports it
            $t->decimal('tx_power', 6, 2)->nullable();
            $t->unsignedInteger('distance_m')->nullable();
            $t->string('source', 10)->default('manual'); // manual | snmp
            $t->timestamp('last_seen_at')->nullable(); // last poll that found it online
            $t->timestamp('last_polled_at')->nullable();
            $t->string('notes', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['olt_id', 'pon_port', 'onu_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onus');
        Schema::dropIfExists('olts');
    }
};
