<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->insert([
            [
                'id' => 1,
                'code' => 'ADM-001',
                'name' => 'Superadmin',
                'username' => 'superadmin',
                'email' => 'superadmin@akartanikimia.com',
                'phone' => '085733920144',
                'role' => 'admin',
                'admin_role' => 'super_admin',
                'status' => 'active',
                'email_verified_at' => NULL,
                'last_login_at' => '2026-06-09 06:02:39',
                'password' => '$2y$12$9m0.RKlFiGs6knHjnmicvOSybkQrLT8CT5XThVFbZufjWc6r5eUly',
                'remember_token' => NULL,
                'created_at' => '2026-05-14 23:43:16',
                'updated_at' => '2026-06-09 06:02:39',
            ],
        ]);
    }
}
