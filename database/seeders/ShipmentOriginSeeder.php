<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShipmentOriginSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('shipment_origins')->insert([
            [
                'id' => 1,
                'label' => 'Gudang Utama Malang',
                'contact_name' => 'Tim Gudang Larashop',
                'contact_phone' => '0341123456',
                'origin_id' => 47246,
                'selected_courier' => 'jnt',
                'province' => 'JAWA TIMUR',
                'city' => 'MALANG',
                'district' => 'PAKIS',
                'subdistrict' => 'SEKARPURO',
                'postal_code' => '65154',
                'address_line' => 'Jl. Raya Sekarpuro No.86, Sekaran, Kec. Pakis, Kabupaten Malang, Jawa Timur 65154',
                'pin_point' => '-7.968106, 112.676096',
                'note' => 'Dipakai sebagai titik asal default untuk estimasi ongkir customer.',
                'is_default' => 1,
                'is_active' => 1,
                'created_at' => '2026-05-16 07:01:22',
                'updated_at' => '2026-06-09 11:13:20',
            ],
        ]);
    }
}
