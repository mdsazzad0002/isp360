<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Support tickets between customers, resellers and the company.
//  - a customer's ticket is seen by the company and, if the customer belongs to a reseller,
//    by that reseller too (reseller_id is copied onto the ticket)
//  - a reseller's own ticket is between the reseller and the company
//  - company staff can add internal notes that customers/resellers never see
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->id();
                $table->string('ticket_no', 40);
                $table->string('subject');
                $table->string('category', 40)->default('other');
                $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
                $table->enum('status', ['open', 'in_progress', 'waiting', 'resolved', 'closed'])->default('open')->index();
                $table->enum('opened_by_type', ['customer', 'reseller', 'admin']);
                $table->unsignedBigInteger('opened_by_id')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->unsignedBigInteger('reseller_id')->nullable()->index();
                $table->unsignedBigInteger('connection_id')->nullable();
                $table->unsignedBigInteger('assigned_to')->nullable()->index();
                $table->enum('last_reply_by_type', ['customer', 'reseller', 'admin'])->nullable();
                $table->timestamp('last_reply_at')->nullable()->index();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('branch_id')->index();
                $table->timestamps();

                $table->unique(['branch_id', 'ticket_no']);
            });
        }

        if (! Schema::hasTable('ticket_replies')) {
            Schema::create('ticket_replies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ticket_id')->index();
                $table->enum('author_type', ['customer', 'reseller', 'admin', 'system']);
                $table->unsignedBigInteger('author_id')->nullable();
                $table->text('message');
                $table->string('attachment')->nullable();
                $table->boolean('is_internal')->default(false);
                $table->string('ipAddress', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_replies');
        Schema::dropIfExists('tickets');
    }
};
