<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where each staff user / reseller / customer is signed in (roadmap 4.12): browser, IP, last seen,
// and a way to sign a session out from elsewhere. Works with every session driver.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('guard', 20);
            $table->unsignedBigInteger('account_id');
            $table->string('token', 64)->unique(); // sha256 of the random value kept in the session
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['guard', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_sessions');
    }
};
