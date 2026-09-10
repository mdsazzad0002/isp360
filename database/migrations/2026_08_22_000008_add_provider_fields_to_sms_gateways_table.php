<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_gateways', function (Blueprint $table) {
            $table->string('provider_type')->default('custom')->after('name');
            $table->string('api_key')->nullable()->after('provider_type');
            $table->string('sender_id')->nullable()->after('api_key');
            $table->string('sms_type')->default('text')->after('sender_id');
            $table->string('label')->default('promotional')->after('sms_type');
        });

        DB::unprepared('ALTER TABLE `sms_gateways` MODIFY `url_template` TEXT NULL');
    }

    public function down(): void
    {
        Schema::table('sms_gateways', function (Blueprint $table) {
            $table->dropColumn(['provider_type', 'api_key', 'sender_id', 'sms_type', 'label']);
        });
    }
};
