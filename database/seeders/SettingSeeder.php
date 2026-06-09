<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('settings')->insert([
            [
                'id' => 1,
                'key' => 'store_whatsapp',
                'value' => '6285733920144',
                'created_at' => '2026-06-09 07:53:48',
                'updated_at' => '2026-06-09 07:53:48',
            ],
            [
                'id' => 2,
                'key' => 'store_email',
                'value' => 'admin@akartanikimia.com',
                'created_at' => '2026-06-09 08:17:25',
                'updated_at' => '2026-06-09 08:17:25',
            ],
            [
                'id' => 3,
                'key' => 'store_brand',
                'value' => 'Akar Tani Kimia',
                'created_at' => '2026-06-09 08:17:25',
                'updated_at' => '2026-06-09 08:17:25',
            ],
            [
                'id' => 4,
                'key' => 'unique_code_enabled',
                'value' => '0',
                'created_at' => '2026-06-09 14:49:21',
                'updated_at' => '2026-06-09 14:49:21',
            ],
        ]);
    }
}
