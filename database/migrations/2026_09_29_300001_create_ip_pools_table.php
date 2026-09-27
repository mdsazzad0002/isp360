<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// IP address management (roadmap 4.6): pools for static IPv4, IPv6 prefix delegation and
// deterministic CGNAT (each private address always gets the same public address + port block,
// so a lawful request for "public IP:port at time t" finds one customer without NAT logs).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_pools', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('router_id')->nullable();
            $t->string('name', 100);
            $t->string('type', 12); // static | ipv6_pd | cgnat
            $t->string('network', 64); // 10.20.0.0/24 ; 2001:db8:100::/40 ; CGNAT private side 100.64.0.0/22
            $t->string('gateway', 45)->nullable();
            $t->unsignedTinyInteger('delegated_length')->nullable(); // ipv6_pd: /56 per customer
            $t->string('public_network', 45)->nullable(); // cgnat: public side 203.0.113.0/28
            $t->unsignedInteger('ports_per_user')->nullable(); // cgnat: 2016 -> 32 users per public IP
            $t->unsignedInteger('port_start')->default(1024); // cgnat: first port handed out
            $t->string('notes', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::table('connections', function (Blueprint $t) {
            $t->string('ipv6_prefix', 64)->nullable()->after('static_ip'); // delegated prefix, e.g. 2001:db8:100:1200::/56
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_pools');
        Schema::table('connections', fn (Blueprint $t) => $t->dropColumn('ipv6_prefix'));
    }
};
