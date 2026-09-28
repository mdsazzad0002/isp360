<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InitialSetupSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::firstOrCreate(
            ['code' => 'B00001'],
            [
                'name' => 'main',
                'title' => 'main',
                'address' => null,
                'phone' => '0000000000',
                'status' => 'a',
                'created_by' => 1,
                'created_at' => now(),
                'ipAddress' => '127.0.0.1',
            ]
        );

        CompanyProfile::firstOrCreate(
            ['id' => 1],
            [
                'name' => env('ADMIN_COMPANY_NAME', 'My Company'),
                'title' => 'Company Title',
                'phone' => '0000000000',
                'email' => null,
                'address' => null,
                'favicon' => null,
                'logo' => null,
                'url' => null,
                'ipAddress' => '127.0.0.1',
            ]
        );

        $username = env('ADMIN_USERNAME', 'admin');
        // "1" is only for a local install; production gets a random password unless one is set
        $password = env('ADMIN_PASSWORD') ?: (app()->environment('production') ? \Illuminate\Support\Str::password(16, symbols: false) : '1');

        User::firstOrCreate(
            ['username' => $username],
            [
                'code' => 'U00001',
                'name' => 'Admin',
                'email' => env('ADMIN_EMAIL', '1'),
                'password' => Hash::make($password),
                'phone' => '0000000000',
                'role' => 'Superadmin',
                'status' => 'a',
                'created_at' => now(),
                'ipAddress' => '127.0.0.1',
                'branch_id' => $branch->id,
            ]
        );

        $this->command?->info("Initial admin login -> username: {$username} / password: {$password}");
    }
}
