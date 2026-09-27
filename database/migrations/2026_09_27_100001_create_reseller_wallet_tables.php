<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Reseller wallet. How a reseller earns and settles:
//  - the company publishes packages as 'universal' (usable for anyone) or 'hidden' (a wholesale
//    base that a reseller's customer can only get through the reseller's own customized copy)
//  - a reseller customizes a company package (base_package_id) with their own name and a higher
//    price; the company reviews every create/edit before it goes live
//  - each auto invoice of a reseller package snapshots the company's share (reseller_cost), so
//    the reseller earns (paid - reseller_cost * paid / total) as the customer pays
//  - cash the reseller collects stays in their hand and counts against their balance; deposits
//    to the company and paid withdrawals settle it (reseller_transactions)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'visibility')) {
                $table->enum('visibility', ['universal', 'hidden'])->default('universal')->after('reseller_id');
            }
            if (! Schema::hasColumn('packages', 'base_package_id')) {
                $table->unsignedBigInteger('base_package_id')->nullable()->after('visibility')->index();
            }
            if (! Schema::hasColumn('packages', 'approval_status')) {
                // Company packages are always 'approved'. A reseller package is usable once
                // approved_at is set; later edits wait in pending_changes until reviewed.
                $table->enum('approval_status', ['approved', 'pending', 'rejected'])->default('approved')->after('base_package_id');
                $table->json('pending_changes')->nullable()->after('approval_status');
                $table->string('review_note')->nullable()->after('pending_changes');
                $table->timestamp('approved_at')->nullable()->after('review_note');
                $table->unsignedBigInteger('reviewed_by')->nullable()->after('approved_at');
            }
        });
        // Packages resellers made before the review flow keep working as they are.
        DB::table('packages')->whereNotNull('reseller_id')->whereNull('approved_at')->update(['approved_at' => now()]);

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'reseller_id')) {
                $table->unsignedBigInteger('reseller_id')->nullable()->after('connection_id')->index();
                $table->decimal('reseller_cost', 14, 2)->default(0)->after('reseller_id');
            }
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_payments', 'collected_by_reseller_id')) {
                $table->unsignedBigInteger('collected_by_reseller_id')->nullable()->after('received_by')->index();
            }
        });

        if (! Schema::hasTable('reseller_transactions')) {
            Schema::create('reseller_transactions', function (Blueprint $table) {
                $table->id();
                $table->string('ref_no', 40);
                $table->unsignedBigInteger('reseller_id')->index();
                // withdrawal: company pays the reseller; deposit: reseller hands collected cash to the company
                $table->enum('type', ['withdrawal', 'deposit']);
                $table->decimal('amount', 14, 2);
                $table->enum('status', ['pending', 'paid', 'rejected', 'cancelled'])->default('pending')->index();
                $table->enum('method', ['cash', 'bank', 'bkash', 'nagad', 'rocket', 'other'])->default('cash');
                $table->string('account_details')->nullable(); // where the reseller wants the money
                $table->unsignedBigInteger('bank_id')->nullable();   // company account the money moved through
                $table->string('transaction_id')->nullable();
                $table->string('reseller_note')->nullable();
                $table->string('admin_note')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->unsignedBigInteger('processed_by')->nullable();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('ipAddress', 45)->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'ref_no']);
            });
        }

        // Cash/bank books: withdrawals and deposits appear as reseller payments/receives.
        foreach (['receives', 'payments'] as $book) {
            if (! Schema::hasColumn($book, 'reseller_id')) {
                Schema::table($book, function (Blueprint $table) {
                    $table->unsignedBigInteger('reseller_id')->nullable()->index();
                    $table->unsignedBigInteger('reseller_transaction_id')->nullable()->index();
                });
            }
        }
        DB::statement("ALTER TABLE receives MODIFY type ENUM('customer','supplier','provider','reseller') NOT NULL DEFAULT 'customer'");
        DB::statement("ALTER TABLE payments MODIFY type ENUM('customer','supplier','careflow_order','provider','employee','reseller') NOT NULL DEFAULT 'supplier'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE payments MODIFY type ENUM('customer','supplier','careflow_order','provider','employee') NOT NULL DEFAULT 'supplier'");
        DB::statement("ALTER TABLE receives MODIFY type ENUM('customer','supplier','provider') NOT NULL DEFAULT 'customer'");
        foreach (['receives', 'payments'] as $book) {
            if (Schema::hasColumn($book, 'reseller_id')) {
                Schema::table($book, fn (Blueprint $table) => $table->dropColumn(['reseller_id', 'reseller_transaction_id']));
            }
        }
        Schema::dropIfExists('reseller_transactions');
        if (Schema::hasColumn('customer_payments', 'collected_by_reseller_id')) {
            Schema::table('customer_payments', fn (Blueprint $table) => $table->dropColumn('collected_by_reseller_id'));
        }
        if (Schema::hasColumn('invoices', 'reseller_id')) {
            Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['reseller_id', 'reseller_cost']));
        }
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['visibility', 'base_package_id', 'approval_status', 'pending_changes', 'review_note', 'approved_at', 'reviewed_by']);
        });
    }
};
