<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Multi-level reseller network (roadmap 3.3):
//  - resellers.parent_id / path / depth: the tree (path "/3/7/" lists the ancestors and the reseller)
//  - resellers.credit_limit: how much a reseller may owe before it can't collect more cash
//  - reseller_transactions.parent_reseller_id: who the deposit / withdrawal was settled with (null = company)
//  - invoice_reseller_shares: per reseller-package bill, each level's cost (net of tax) — what that
//    level owes the one above for the sale. Level 0 = the selling reseller; the top level's cost
//    is the company's share (= invoices.reseller_cost).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resellers', 'parent_id')) {
            Schema::table('resellers', function (Blueprint $table) {
                $table->unsignedBigInteger('parent_id')->nullable()->after('id')->index();
                $table->string('path', 255)->nullable()->after('parent_id')->index();
                $table->unsignedTinyInteger('depth')->default(1)->after('path');
                $table->decimal('credit_limit', 18, 3)->nullable()->after('area_id');
            });
        }
        if (! Schema::hasColumn('reseller_transactions', 'parent_reseller_id')) {
            Schema::table('reseller_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('parent_reseller_id')->nullable()->after('reseller_id')->index();
            });
        }
        if (! Schema::hasTable('invoice_reseller_shares')) {
            Schema::create('invoice_reseller_shares', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('invoice_id');
                $table->unsignedBigInteger('reseller_id')->index();
                $table->unsignedTinyInteger('level');
                // null: no known share, the whole net amount is the company's (as reseller_cost null)
                $table->decimal('cost', 18, 3)->nullable();
                $table->unique(['invoice_id', 'level']);
            });
        }

        // every existing reseller is a top-level one, and every existing reseller bill has one level
        DB::table('resellers')->whereNull('path')->update(['path' => DB::raw("concat('/', id, '/')"), 'depth' => 1]);
        DB::statement("insert into invoice_reseller_shares (invoice_id, reseller_id, level, cost)
            select i.id, i.reseller_id, 0, i.reseller_cost from invoices i
            where i.reseller_id is not null
            and not exists (select 1 from invoice_reseller_shares s where s.invoice_id = i.id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reseller_shares');
        if (Schema::hasColumn('reseller_transactions', 'parent_reseller_id')) {
            Schema::table('reseller_transactions', fn (Blueprint $table) => $table->dropColumn('parent_reseller_id'));
        }
        if (Schema::hasColumn('resellers', 'parent_id')) {
            Schema::table('resellers', fn (Blueprint $table) => $table->dropColumn(['parent_id', 'path', 'depth', 'credit_limit']));
        }
    }
};
