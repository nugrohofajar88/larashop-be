<?php

namespace Database\Seeders;

use App\Models\ShipmentOrigin;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ShipmentOriginSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        ShipmentOrigin::query()->create([
            'label' => 'Gudang Utama Malang',
            'contact_name' => 'Tim Gudang Larashop',
            'contact_phone' => '0341123456',
            'origin_id' => 47071,
            'selected_courier' => 'jnt',
            'province' => 'JAWA TIMUR',
            'city' => 'MALANG',
            'district' => 'KEPANJEN',
            'subdistrict' => 'ARDIREJO',
            'postal_code' => '65163',
            'address_line' => 'Jl. Raya Kebun No. 8, dekat area packing utama',
            'note' => 'Dipakai sebagai titik asal default untuk estimasi ongkir customer.',
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
