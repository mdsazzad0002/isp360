<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('company_profiles', 'favicon_sizes')) {
            return;
        }

        Schema::table('company_profiles', function (Blueprint $table) {
            // {"32": "uploads/favicon/xxx-32.png", "180": "...", "192": "...", "512": "..."}
            // Generated automatically from the logo (or a manually uploaded
            // favicon) whenever either is saved — see FaviconGenerator.
            $table->text('favicon_sizes')->nullable()->after('favicon');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('company_profiles', 'favicon_sizes')) {
            return;
        }

        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn('favicon_sizes');
        });
    }
};
