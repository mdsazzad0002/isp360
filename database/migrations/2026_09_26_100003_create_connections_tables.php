<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'zone_id')) {
                $table->unsignedBigInteger('zone_id')->nullable()->after('area_id')->index();
            }
            if (! Schema::hasColumn('customers', 'box_id')) {
                $table->unsignedBigInteger('box_id')->nullable()->after('zone_id')->index();
            }
            if (! Schema::hasColumn('customers', 'nid')) {
                $table->string('nid', 30)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('customers', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable()->after('nid');
            }
            if (! Schema::hasColumn('customers', 'billing_address')) {
                $table->string('billing_address')->nullable()->after('address');
            }
            if (! Schema::hasColumn('customers', 'notes')) {
                $table->text('notes')->nullable()->after('billing_address');
            }
            if (! Schema::hasColumn('customers', 'account_status')) {
                $table->enum('account_status', ['active', 'inactive'])->default('active')->after('notes')->index();
            }
            // Cached sum of ledger_entries (debit - credit). Positive = customer owes, negative = advance.
            // Only LedgerService writes it; it can always be rebuilt from the ledger.
            if (! Schema::hasColumn('customers', 'ledger_balance')) {
                $table->decimal('ledger_balance', 14, 2)->default(0)->after('account_status');
            }
        });

        if (! Schema::hasTable('connections')) {
            Schema::create('connections', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50);
                $table->unsignedBigInteger('customer_id')->index();
                $table->unsignedBigInteger('package_id')->index();
                $table->unsignedBigInteger('box_id')->nullable()->index();
                $table->enum('connection_type', ['pppoe', 'static', 'dhcp'])->default('pppoe');
                $table->string('pppoe_username')->nullable();
                $table->text('pppoe_password')->nullable();
                $table->string('static_ip', 45)->nullable();
                $table->string('mac_address', 32)->nullable();
                // Fixed monthly discount on the package price for this connection.
                $table->decimal('discount', 14, 2)->default(0);
                $table->date('installation_date')->nullable();
                $table->date('activation_date')->nullable();
                // First day of the next period that has not been invoiced yet.
                $table->date('next_billing_date')->nullable()->index();
                $table->enum('status', ['pending', 'active', 'suspended', 'inactive', 'terminated'])->default('pending')->index();
                $table->string('suspension_reason')->nullable();
                $table->timestamp('suspended_at')->nullable();
                $table->unsignedBigInteger('suspended_by')->nullable();
                $table->timestamp('terminated_at')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->string('ipAddress', 45)->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'code']);
                $table->unique(['branch_id', 'pppoe_username']);
                $table->index('static_ip');
            });
        }

        if (! Schema::hasTable('connection_histories')) {
            Schema::create('connection_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('connection_id')->index();
                $table->string('action', 40)->index();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('reason')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('package_histories')) {
            Schema::create('package_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('connection_id')->index();
                $table->unsignedBigInteger('old_package_id')->nullable();
                $table->unsignedBigInteger('new_package_id');
                $table->decimal('old_price', 14, 2)->nullable();
                $table->decimal('new_price', 14, 2);
                $table->date('effective_date');
                $table->string('reason')->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('package_histories');
        Schema::dropIfExists('connection_histories');
        Schema::dropIfExists('connections');
        Schema::table('customers', function (Blueprint $table) {
            foreach (['zone_id', 'box_id', 'nid', 'date_of_birth', 'billing_address', 'notes', 'account_status', 'ledger_balance'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
