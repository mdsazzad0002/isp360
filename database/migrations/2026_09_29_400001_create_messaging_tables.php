<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// More notification channels (roadmap 4.8): e-mail and WhatsApp next to SMS, a delivery log for
// them, each customer's channels, and the expiry reminder's tracking column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_channels', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->string('channel', 20); // email | whatsapp
            $t->boolean('is_active')->default(false);
            $t->text('credentials')->nullable(); // encrypted JSON
            $t->timestamps();
            $t->unique(['branch_id', 'channel']);
        });
        Schema::create('notification_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->string('channel', 20);
            $t->string('event', 40)->nullable();
            $t->string('recipient', 191);
            $t->string('subject', 255)->nullable();
            $t->text('body');
            $t->boolean('is_success')->default(false);
            $t->text('response')->nullable();
            $t->dateTime('created_at');
        });
        Schema::table('customers', function (Blueprint $t) {
            $t->string('notify_channels', 40)->default('sms'); // comma list: sms,email,whatsapp
        });
        Schema::table('connections', function (Blueprint $t) {
            $t->dateTime('reminder_for')->nullable(); // expire_at the renewal reminder was sent for
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_channels');
        Schema::dropIfExists('notification_logs');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('notify_channels'));
        Schema::table('connections', fn (Blueprint $t) => $t->dropColumn('reminder_for'));
    }
};
