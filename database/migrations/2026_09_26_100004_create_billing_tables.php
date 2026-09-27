<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Finance core. Rules the schema is built around:
//  - invoice items snapshot the price at billing time (package edits never touch old invoices)
//  - payments are never edited/deleted: they are reversed, and allocations are reversed/re-made
//  - ledger_entries is append-only and is the single source of truth for a customer's balance
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('isp_settings')) {
            Schema::create('isp_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id');
                $table->string('key', 60);
                $table->text('value')->nullable();
                $table->timestamps();
                $table->unique(['branch_id', 'key']);
            });
        }

        if (! Schema::hasTable('number_sequences')) {
            Schema::create('number_sequences', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id');
                $table->string('name', 40);
                $table->unsignedSmallInteger('year');
                $table->unsignedInteger('last_value')->default(0);
                $table->unique(['branch_id', 'name', 'year']);
            });
        }

        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->string('invoice_no', 40);
                $table->unsignedBigInteger('customer_id')->index();
                $table->unsignedBigInteger('connection_id')->nullable()->index();
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                // "<connection_id>:<period_start>" while the invoice is live; NULL once voided, so the
                // period can be billed again. The unique index is what makes billing runs idempotent.
                $table->string('period_key', 40)->nullable()->unique();
                $table->date('invoice_date')->index();
                $table->date('due_date')->index();
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                // Net of debit notes (+) and credit notes (-) raised against this invoice.
                $table->decimal('adjustment', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->decimal('paid', 14, 2)->default(0);
                $table->decimal('due', 14, 2)->default(0);
                $table->enum('status', ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'void', 'cancelled'])->default('issued')->index();
                $table->enum('source', ['auto', 'manual'])->default('manual');
                $table->text('notes')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason')->nullable();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->string('ipAddress', 45)->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'invoice_no']);
            });
        }

        if (! Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('invoice_id')->index();
                $table->unsignedBigInteger('package_id')->nullable();
                $table->string('description');
                $table->decimal('unit_price', 14, 2);
                $table->decimal('quantity', 10, 4)->default(1);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('total', 14, 2);
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_payments')) {
            Schema::create('customer_payments', function (Blueprint $table) {
                $table->id();
                $table->string('receipt_no', 40);
                $table->unsignedBigInteger('customer_id')->index();
                $table->date('payment_date')->index();
                $table->decimal('amount', 14, 2);
                $table->decimal('allocated_amount', 14, 2)->default(0);
                $table->decimal('refunded_amount', 14, 2)->default(0);
                $table->enum('method', ['cash', 'bank', 'bkash', 'nagad', 'rocket', 'card', 'gateway', 'other'])->default('cash');
                $table->unsignedBigInteger('bank_id')->nullable();
                $table->string('provider')->nullable();
                $table->string('transaction_id')->nullable()->index();
                $table->string('reference')->nullable();
                $table->unsignedBigInteger('received_by')->nullable();
                $table->enum('source', ['admin', 'portal', 'reseller', 'gateway'])->default('admin');
                $table->enum('status', ['pending', 'completed', 'failed', 'reversed', 'refunded', 'partially_refunded'])->default('completed')->index();
                $table->text('notes')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->string('reversal_reason')->nullable();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('ipAddress', 45)->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'receipt_no']);
            });
        }

        if (! Schema::hasTable('payment_allocations')) {
            Schema::create('payment_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_payment_id')->index();
                $table->unsignedBigInteger('invoice_id')->index();
                $table->decimal('amount', 14, 2);
                $table->enum('status', ['active', 'reversed'])->default('active')->index();
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->string('reversal_reason')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('billing_notes')) {
            Schema::create('billing_notes', function (Blueprint $table) {
                $table->id();
                $table->string('note_no', 40);
                $table->enum('type', ['credit', 'debit']);
                $table->unsignedBigInteger('customer_id')->index();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->decimal('amount', 14, 2);
                $table->date('note_date');
                $table->string('reason');
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('ipAddress', 45)->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'note_no']);
            });
        }

        if (! Schema::hasTable('refunds')) {
            Schema::create('refunds', function (Blueprint $table) {
                $table->id();
                $table->string('refund_no', 40);
                $table->unsignedBigInteger('customer_payment_id')->index();
                $table->unsignedBigInteger('customer_id')->index();
                $table->decimal('amount', 14, 2);
                $table->date('refund_date');
                $table->enum('method', ['cash', 'bank', 'bkash', 'nagad', 'rocket', 'card', 'gateway', 'other'])->default('cash');
                $table->unsignedBigInteger('bank_id')->nullable();
                $table->string('reason');
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('ipAddress', 45)->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'refund_no']);
            });
        }

        if (! Schema::hasTable('ledger_entries')) {
            Schema::create('ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->date('entry_date');
                $table->enum('type', ['opening', 'invoice', 'invoice_void', 'payment', 'payment_reversal', 'credit_note', 'debit_note', 'refund']);
                $table->string('reference_type', 40)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('description');
                $table->decimal('debit', 14, 2)->default(0);
                $table->decimal('credit', 14, 2)->default(0);
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['customer_id', 'entry_date', 'id']);
                $table->index(['reference_type', 'reference_id']);
            });
        }

        // Cash/bank collections are mirrored into the existing `receives` book so the cash
        // ledger, bank ledger, day book and balance sheet keep including ISP money.
        Schema::table('receives', function (Blueprint $table) {
            if (! Schema::hasColumn('receives', 'customer_payment_id')) {
                $table->unsignedBigInteger('customer_payment_id')->nullable()->index();
            }
        });
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'refund_id')) {
                $table->unsignedBigInteger('refund_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'refund_id')) {
                $table->dropColumn('refund_id');
            }
        });
        Schema::table('receives', function (Blueprint $table) {
            if (Schema::hasColumn('receives', 'customer_payment_id')) {
                $table->dropColumn('customer_payment_id');
            }
        });
        foreach (['ledger_entries', 'refunds', 'billing_notes', 'payment_allocations', 'customer_payments', 'invoice_items', 'invoices', 'number_sequences', 'isp_settings'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
