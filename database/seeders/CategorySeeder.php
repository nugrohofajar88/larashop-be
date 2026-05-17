<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Category::query()->delete();

        Category::insert([
            ['name' => 'Pupuk', 'slug' => 'pupuk', 'description' => 'Nutrisi dan pemupukan tanaman', 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Benih', 'slug' => 'benih', 'description' => 'Benih unggul dan bibit', 'is_active' => true, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Perlindungan Tanaman', 'slug' => 'perlindungan-tanaman', 'description' => 'Pestisida dan fungisida', 'is_active' => true, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
