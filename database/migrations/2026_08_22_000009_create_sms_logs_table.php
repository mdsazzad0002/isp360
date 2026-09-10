<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sms_logs')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `sms_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `sms_gateway_id` bigint(20) UNSIGNED DEFAULT NULL,
  `gateway_name` varchar(255) DEFAULT NULL,
  `phone` varchar(30) NOT NULL,
  `message` text NOT NULL,
  `purpose` varchar(50) NOT NULL DEFAULT 'promotional',
  `is_success` tinyint(1) NOT NULL DEFAULT 0,
  `response` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ipAddress` varchar(45) NOT NULL,
  `branch_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL
        );

        DB::unprepared(<<<'SQL'
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sms_logs_customer_id_foreign` (`customer_id`),
  ADD KEY `sms_logs_sms_gateway_id_foreign` (`sms_gateway_id`),
  ADD KEY `sms_logs_created_by_foreign` (`created_by`),
  ADD KEY `sms_logs_branch_id_foreign` (`branch_id`);
SQL
        );

        DB::unprepared(<<<'SQL'
ALTER TABLE `sms_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
SQL
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
