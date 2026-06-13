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
            // Metode pembayaran yang ditawarkan ke pelanggan (default dua-duanya aktif).
            [
                'id' => 5,
                'key' => 'payment_transfer_enabled',
                'value' => '1',
                'created_at' => '2026-06-12 00:00:00',
                'updated_at' => '2026-06-12 00:00:00',
            ],
            [
                'id' => 6,
                'key' => 'payment_qris_enabled',
                // Default OFF: QRIS baru aktif setelah admin upload QRIS toko & mencentangnya.
                'value' => '0',
                'created_at' => '2026-06-12 00:00:00',
                'updated_at' => '2026-06-12 00:00:00',
            ],
            // Catatan: `qrisly_qris_id` SENGAJA tidak di-seed — spesifik per lingkungan
            // (hasil upload QRIS asli toko via menu admin QRIS).
        ]);
    }
}
