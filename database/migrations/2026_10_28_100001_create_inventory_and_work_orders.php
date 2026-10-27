<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Inventory (roadmap 4.5) and work orders (4.4). Stock is never stored: it is the sum of
// inventory_movements into a place minus out of it (store, a technician, a customer's connection),
// the same rule as the customer ledger. Serial-tracked units (ONU, router) also have a row each.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->string('name', 150);
            $t->string('category', 20); // onu | router | switch | sfp | cable | splitter | other
            $t->string('unit', 10)->default('pcs'); // pcs | m
            $t->boolean('serialized')->default(false); // each unit has a serial (ONU, router)
            $t->string('sku', 60)->nullable();
            $t->decimal('unit_cost', 14, 2)->default(0); // last purchase price
            $t->decimal('low_stock', 12, 2)->default(0); // warn at or below this in the store
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('inventory_serials', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('item_id')->index();
            $t->string('serial', 64);
            $t->string('mac', 17)->nullable()->index();
            $t->string('location', 30)->default('store'); // store | tech:<user id> | conn:<connection id> | out
            $t->string('condition', 12)->default('good'); // good | faulty
            $t->unsignedBigInteger('connection_id')->nullable()->index(); // while installed at a customer
            $t->timestamps();
            $t->unique(['branch_id', 'serial']);
        });

        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('item_id')->index();
            $t->unsignedBigInteger('serial_id')->nullable()->index();
            $t->string('type', 16); // receive | issue | return | install | recover | faulty | write_off | adjust
            $t->decimal('qty', 12, 2);
            $t->string('from', 30); // supplier | store | tech:<id> | conn:<id> | out
            $t->string('to', 30);
            $t->decimal('unit_cost', 14, 2)->nullable();
            $t->unsignedBigInteger('work_order_id')->nullable()->index();
            $t->unsignedBigInteger('connection_id')->nullable()->index();
            $t->string('note', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['item_id', 'from']);
            $t->index(['item_id', 'to']);
        });

        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->string('wo_no', 40);
            $t->string('type', 16); // installation | shifting | repair | disconnection | pickup | other
            $t->string('status', 12)->default('new')->index(); // new | assigned | in_progress | done | cancelled
            $t->string('priority', 8)->default('normal'); // low | normal | high | urgent
            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->unsignedBigInteger('connection_id')->nullable()->index();
            $t->unsignedBigInteger('ticket_id')->nullable()->index();
            $t->unsignedBigInteger('assigned_to')->nullable()->index();
            $t->dateTime('scheduled_at')->nullable()->index();
            $t->string('address', 255)->nullable(); // where to go, copied when created
            $t->text('notes')->nullable(); // what to do
            $t->text('result')->nullable(); // what was done
            $t->string('onu_serial', 64)->nullable();
            $t->string('signature', 255)->nullable(); // customer's signature, private disk
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->unsignedBigInteger('completed_by')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['branch_id', 'wo_no']);
        });

        Schema::create('work_order_photos', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('work_order_id')->index();
            $t->string('path', 255); // private disk
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_photos');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_serials');
        Schema::dropIfExists('inventory_items');
    }
};
