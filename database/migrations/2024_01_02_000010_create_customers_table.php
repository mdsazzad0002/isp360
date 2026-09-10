<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `customers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `owner` varchar(255) DEFAULT NULL,
  `phone` varchar(15) NOT NULL,
  `type` enum('retail','wholesale') NOT NULL DEFAULT 'retail',
  `email` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `area_id` bigint(20) UNSIGNED DEFAULT NULL,
  `previous_due` decimal(18,2) NOT NULL DEFAULT 0.00,
  `credit_limit` decimal(18,2) NOT NULL DEFAULT 0.00,
  `is_membership` enum('yes','no') NOT NULL DEFAULT 'no',
  `amount` decimal(8,2) NOT NULL DEFAULT 0.00,
  `point` int(11) NOT NULL DEFAULT 0,
  `image` varchar(255) DEFAULT NULL,
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
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customers_area_id_foreign` (`area_id`),
  ADD KEY `customers_created_by_foreign` (`created_by`),
  ADD KEY `customers_updated_by_foreign` (`updated_by`),
  ADD KEY `customers_deleted_by_foreign` (`deleted_by`),
  ADD KEY `customers_branch_id_foreign` (`branch_id`),
  ADD KEY `customers_code_index` (`code`),
  ADD KEY `customers_phone_index` (`phone`),
  ADD KEY `customers_type_index` (`type`);
SQL
        );

        DB::unprepared(<<<'SQL'
ALTER TABLE `customers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
SQL
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
