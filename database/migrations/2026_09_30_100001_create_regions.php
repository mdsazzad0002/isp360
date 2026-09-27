<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Company -> Region -> Branch (roadmap 3.2). A user with a region is a regional manager:
// they can switch between (and see figures of) that region's branches only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('code', 20)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::table('branches', fn (Blueprint $table) => $table->unsignedBigInteger('region_id')->nullable()->index());
        Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('region_id')->nullable()->index());
        // read by the branch switcher and Branch Manage, but never created by the base schema
        if (! Schema::hasColumn('company_profiles', 'multi_branch_status')) {
            Schema::table('company_profiles', fn (Blueprint $table) => $table->enum('multi_branch_status', ['active', 'inactive'])->default('inactive'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_profiles', 'multi_branch_status')) {
            Schema::table('company_profiles', fn (Blueprint $table) => $table->dropColumn('multi_branch_status'));
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('region_id'));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('region_id'));
        Schema::dropIfExists('regions');
    }
};
