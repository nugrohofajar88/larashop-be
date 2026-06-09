<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('categories')->insert([
            [
                'id' => 1,
                'name' => 'Insektisida',
                'slug' => 'insektisida',
                'description' => 'Pengendali serangga & hama',
                'is_active' => 1,
                'sort_order' => 1,
                'created_at' => '2026-06-09 08:46:37',
                'updated_at' => '2026-06-09 08:46:37',
            ],
            [
                'id' => 2,
                'name' => 'Fungisida',
                'slug' => 'fungisida',
                'description' => 'Pengendali penyakit jamur',
                'is_active' => 1,
                'sort_order' => 2,
                'created_at' => '2026-06-09 08:46:37',
                'updated_at' => '2026-06-09 08:46:37',
            ],
            [
                'id' => 3,
                'name' => 'ZPT & Hormon',
                'slug' => 'zpt-hormon',
                'description' => 'Zat pengatur tumbuh, hormon & biostimulan',
                'is_active' => 1,
                'sort_order' => 3,
                'created_at' => '2026-06-09 08:46:37',
                'updated_at' => '2026-06-09 08:46:37',
            ],
        ]);
    }
}
