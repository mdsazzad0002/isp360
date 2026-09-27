<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Two-factor login (TOTP) for staff and resellers, the company's 2FA policy, and SMS gateway
// secrets (API key, URL template with its key) encrypted at rest like router and payment
// gateway credentials already are.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'resellers'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'two_factor_secret')) {
                    $t->text('two_factor_secret')->nullable();
                    $t->text('two_factor_recovery_codes')->nullable();
                    $t->dateTime('two_factor_confirmed_at')->nullable();
                    // last accepted 30-second step, so a code can't be used twice
                    $t->unsignedBigInteger('two_factor_last_step')->nullable();
                }
            });
        }

        Schema::table('company_profiles', function (Blueprint $t) {
            if (! Schema::hasColumn('company_profiles', 'two_factor_policy')) {
                // off | admins | staff | staff_resellers
                $t->string('two_factor_policy', 20)->default('off');
            }
        });

        Schema::table('sms_gateways', function (Blueprint $t) {
            $t->text('api_key')->nullable()->change();
            $t->text('url_template')->nullable()->change();
        });
        foreach (DB::table('sms_gateways')->get(['id', 'api_key', 'url_template']) as $row) {
            DB::table('sms_gateways')->where('id', $row->id)->update([
                'api_key' => self::encrypt($row->api_key),
                'url_template' => self::encrypt($row->url_template),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('sms_gateways')->get(['id', 'api_key', 'url_template']) as $row) {
            DB::table('sms_gateways')->where('id', $row->id)->update([
                'api_key' => self::decrypt($row->api_key),
                'url_template' => self::decrypt($row->url_template),
            ]);
        }
        Schema::table('company_profiles', fn (Blueprint $t) => $t->dropColumn('two_factor_policy'));
        foreach (['users', 'resellers'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']));
        }
    }

    // Leaves null/empty and already-encrypted values alone, so a re-run is safe.
    private static function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || self::decrypt($value) !== $value) {
            return $value;
        }
        return Crypt::encryptString($value);
    }

    private static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }
};
