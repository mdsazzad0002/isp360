<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sales tax (VAT / GST). Company-wide: the rates, whether prices include tax, and the company's
// tax number. A package uses the default rates, its own rates, or none (packages.tax_rate_ids:
// null = default, [] = exempt, [ids] = custom). Every invoice line keeps a snapshot of the taxes
// it was charged, so later rate edits never change an issued invoice.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_rates')) {
            Schema::create('tax_rates', function (Blueprint $table) {
                $table->id();
                $table->string('name', 60);
                $table->decimal('rate', 7, 4); // percent, e.g. 15.0000
                $table->boolean('is_default')->default(false); // applied to packages set to "default" and to manual lines
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('company_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('company_profiles', 'tax_label')) {
                $table->string('tax_label', 20)->default('VAT')->after('timezone');
                $table->string('tax_number', 60)->nullable()->after('tax_label');
                $table->boolean('prices_include_tax')->default(false)->after('tax_number');
            }
        });

        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'tax_rate_ids')) {
                $table->json('tax_rate_ids')->nullable()->after('base_price');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'tax')) {
                // tax = the lines' tax; tax_total = that plus credit/debit notes' tax
                $table->decimal('tax', 18, 3)->default(0)->after('discount');
                $table->decimal('tax_total', 18, 3)->default(0)->after('tax');
                // snapshot: true = line prices already include the tax (it is not added to the total)
                $table->boolean('tax_inclusive')->default(false)->after('tax_total');
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'tax_amount')) {
                $table->decimal('tax_amount', 18, 3)->default(0)->after('total');
                $table->json('taxes')->nullable()->after('tax_amount'); // [{id, name, rate, taxable, amount}]
            }
        });

        Schema::table('billing_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_notes', 'tax_amount')) {
                $table->decimal('tax_amount', 18, 3)->default(0)->after('amount');
            }
        });

        // tax given back with a package-downgrade credit (method 'adjustment')
        Schema::table('customer_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_payments', 'tax_amount')) {
                $table->decimal('tax_amount', 18, 3)->default(0)->after('refunded_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', fn (Blueprint $t) => $t->dropColumn('tax_amount'));
        Schema::table('billing_notes', fn (Blueprint $t) => $t->dropColumn('tax_amount'));
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn(['tax_amount', 'taxes']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['tax', 'tax_total', 'tax_inclusive']));
        Schema::table('packages', fn (Blueprint $t) => $t->dropColumn('tax_rate_ids'));
        Schema::table('company_profiles', fn (Blueprint $t) => $t->dropColumn(['tax_label', 'tax_number', 'prices_include_tax']));
        Schema::dropIfExists('tax_rates');
    }
};
