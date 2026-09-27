<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Hotspot connections reuse pppoe_username / pppoe_password as their login credentials.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `connections` MODIFY `connection_type` ENUM('pppoe','hotspot','static','dhcp') NOT NULL DEFAULT 'pppoe'");
    }

    public function down(): void
    {
        DB::statement("UPDATE `connections` SET `connection_type` = 'pppoe' WHERE `connection_type` = 'hotspot'");
        DB::statement("ALTER TABLE `connections` MODIFY `connection_type` ENUM('pppoe','static','dhcp') NOT NULL DEFAULT 'pppoe'");
    }
};
