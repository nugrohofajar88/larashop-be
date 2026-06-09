<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentAccountSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('payment_accounts')->insert([
            [
                'id' => 1,
                'bank_name' => 'BCA',
                'account_number' => '99991029',
                'account_holder' => 'Fredi Nasution',
                'note' => NULL,
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => '2026-06-09 06:04:45',
                'updated_at' => '2026-06-09 06:16:22',
            ],
            [
                'id' => 2,
                'bank_name' => 'Mandiri',
                'account_number' => '88808970',
                'account_holder' => 'Fredi Nasution',
                'note' => NULL,
                'is_active' => 0,
                'sort_order' => 0,
                'created_at' => '2026-06-09 06:05:24',
                'updated_at' => '2026-06-09 06:05:24',
            ],
        ]);
    }
}
