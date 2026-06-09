<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Urutan menjaga relasi FK (users -> categories -> products -> variants).
        $this->call([
            UserSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            ProductVariantSeeder::class,
            ShipmentOriginSeeder::class,
            PaymentAccountSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
