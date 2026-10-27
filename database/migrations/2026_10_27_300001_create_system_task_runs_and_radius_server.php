<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// System & Automation: every run of a background task (scheduled or started from the dashboard), and
// where the FreeRADIUS server is, for the RADIUS setup page's live login test.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_task_runs', function (Blueprint $t) {
            $t->id();
            $t->string('command', 60)->index(); // isp:olt-poll, migrate, …
            $t->string('source', 12); // schedule | dashboard | auto
            $t->timestamp('started_at')->index();
            $t->timestamp('finished_at')->nullable();
            $t->integer('exit_code')->nullable(); // 0 = ok
            $t->text('output')->nullable(); // last part of the output (dashboard runs)
            $t->unsignedBigInteger('user_id')->nullable();
        });

        Schema::table('company_profiles', function (Blueprint $t) {
            $t->string('radius_server', 255)->nullable(); // FreeRADIUS host as this server reaches it
            $t->unsignedSmallInteger('radius_auth_port')->default(1812);
            $t->string('radius_self_ip', 45)->default('127.0.0.1'); // this server's address as FreeRADIUS sees it
            $t->text('radius_self_secret')->nullable(); // encrypted; secret of the self-test client in the nas table
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_task_runs');
        Schema::table('company_profiles', fn (Blueprint $t) => $t->dropColumn(['radius_server', 'radius_auth_port', 'radius_self_ip', 'radius_self_secret']));
    }
};
