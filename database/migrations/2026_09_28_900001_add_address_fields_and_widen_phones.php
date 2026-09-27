<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Generic address next to Zone/Area/Box (roadmap 2.9), labelled by the country pack
// (State/Division/County, Postcode/PIN/ZIP/CEP), and phones long enough for "+" numbers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->string('phone', 20)->nullable()->change();
            $t->string('city', 100)->nullable()->after('address');
            $t->string('state', 100)->nullable()->after('city');
            $t->string('postcode', 20)->nullable()->after('state');
        });
        Schema::table('resellers', function (Blueprint $t) {
            $t->string('phone', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['city', 'state', 'postcode']));
    }
};
