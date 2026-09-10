<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sms_gateways')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `sms_gateways` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `method` varchar(10) NOT NULL DEFAULT 'GET',
  `url_template` text NOT NULL,
  `headers` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `status` char(1) NOT NULL DEFAULT 'a',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `ipAddress` varchar(45) NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL
        );

        DB::unprepared(<<<'SQL'
ALTER TABLE `sms_gateways`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sms_gateways_created_by_foreign` (`created_by`),
  ADD KEY `sms_gateways_updated_by_foreign` (`updated_by`),
  ADD KEY `sms_gateways_deleted_by_foreign` (`deleted_by`),
  ADD KEY `sms_gateways_branch_id_foreign` (`branch_id`);
SQL
        );

        DB::unprepared(<<<'SQL'
ALTER TABLE `sms_gateways`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
SQL
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_gateways');
    }
};
