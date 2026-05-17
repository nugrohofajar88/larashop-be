<?php

namespace Database\Seeders;

use App\Models\ShippingService;
use Illuminate\Database\Seeder;

class ShippingServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ShippingService::query()->delete();

        ShippingService::insert([
            [
                'code' => 'jnt-regular',
                'name' => 'JNT',
                'service_level' => 'Regular',
                'estimate_days' => '2-4 hari',
                'price' => 18000,
                'description' => 'Layanan reguler untuk pengiriman standar.',
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'jnt-express',
                'name' => 'JNT',
                'service_level' => 'Express',
                'estimate_days' => '1-2 hari',
                'price' => 26000,
                'description' => 'Layanan cepat untuk kebutuhan prioritas.',
                'is_active' => true,
                'is_default' => false,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
