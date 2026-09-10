<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receives')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `receives` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice` varchar(255) NOT NULL,
  `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `date` date NOT NULL,
  `type` enum('customer','supplier') NOT NULL DEFAULT 'customer',
  `payment_method` enum('cash','bank') NOT NULL DEFAULT 'cash',
  `bank_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount` decimal(8,2) NOT NULL DEFAULT 0.00,
  `previous_due` decimal(8,2) NOT NULL DEFAULT 0.00,
  `note` text DEFAULT NULL,
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
ALTER TABLE `receives`
  ADD PRIMARY KEY (`id`),
  ADD KEY `receives_customer_id_foreign` (`customer_id`),
  ADD KEY `receives_supplier_id_foreign` (`supplier_id`),
  ADD KEY `receives_bank_id_foreign` (`bank_id`),
  ADD KEY `receives_created_by_foreign` (`created_by`),
  ADD KEY `receives_updated_by_foreign` (`updated_by`),
  ADD KEY `receives_deleted_by_foreign` (`deleted_by`),
  ADD KEY `receives_branch_id_foreign` (`branch_id`);
SQL
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('receives');
    }
};
