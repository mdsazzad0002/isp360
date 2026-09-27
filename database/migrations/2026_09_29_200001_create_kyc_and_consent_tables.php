<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// KYC documents, legal texts (terms / privacy) with versions, customers' consents, marketing
// opt-out and pseudonymised erasure (roadmap 2.6).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->index();
            $t->string('type', 40); // an ID type of the country pack (nid, aadhaar, passport...), or contract / photo / other
            $t->string('number', 100)->nullable();
            $t->string('file_path')->nullable(); // private disk (storage/app/kyc), served only to staff
            $t->string('original_name')->nullable();
            $t->string('status', 20)->default('pending'); // pending | verified | rejected
            $t->string('note', 255)->nullable();
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->dateTime('verified_at')->nullable();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('legal_documents', function (Blueprint $t) {
            $t->id();
            $t->string('type', 20); // terms | privacy
            $t->unsignedInteger('version');
            $t->longText('body');
            $t->dateTime('published_at');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['type', 'version']);
        });
        Schema::create('customer_consents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->index();
            $t->string('type', 20); // terms | privacy | marketing
            $t->unsignedInteger('version')->nullable();
            $t->boolean('granted')->default(true);
            $t->string('source', 20); // portal | admin
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable(); // staff user when recorded by admin
            $t->dateTime('created_at');
        });
        Schema::table('customers', function (Blueprint $t) {
            $t->boolean('marketing_opt_out')->default(false);
            $t->dateTime('erased_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_documents');
        Schema::dropIfExists('legal_documents');
        Schema::dropIfExists('customer_consents');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['marketing_opt_out', 'erased_at']));
    }
};
