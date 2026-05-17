<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::query()->delete();

        $categories = Category::query()->pluck('id', 'name');

        $products = [
            [
                'category_id' => $categories['Pupuk'],
                'sku' => 'PRD-001',
                'slug' => 'pupuk-npk-premium',
                'name' => 'Pupuk NPK Premium',
                'short_description' => 'Pupuk majemuk premium untuk berbagai tanaman.',
                'description' => 'Pupuk majemuk untuk mendukung pertumbuhan vegetatif dan generatif pada berbagai jenis tanaman.',
                'price' => 75000,
                'compare_at_price' => 82000,
                'weight_label' => '5 kg',
                'weight_grams' => 5000,
                'length_cm' => 32,
                'width_cm' => 24,
                'height_cm' => 8,
                'stock' => 48,
                'public_status' => 'active',
                'catalog_status' => 'available',
                'badge_label' => 'Terlaris',
                'sold_count' => 1400,
                'highlights' => ['Nutrisi seimbang', 'Cocok untuk sayur dan buah', 'Praktis untuk pemupukan rutin'],
                'is_featured' => true,
                'published_at' => now()->subDays(30),
                'variants' => [
                    ['sku' => 'PRD-001-500', 'label' => '500 gr', 'price' => 12000, 'compare_at_price' => 14000, 'stock' => 18, 'weight_grams' => 500, 'length_cm' => 20, 'width_cm' => 14, 'height_cm' => 4, 'is_default' => false],
                    ['sku' => 'PRD-001-1KG', 'label' => '1 kg', 'price' => 22000, 'compare_at_price' => 25000, 'stock' => 22, 'weight_grams' => 1000, 'length_cm' => 24, 'width_cm' => 16, 'height_cm' => 5, 'is_default' => false],
                    ['sku' => 'PRD-001-5KG', 'label' => '5 kg', 'price' => 75000, 'compare_at_price' => 82000, 'stock' => 8, 'weight_grams' => 5000, 'length_cm' => 32, 'width_cm' => 24, 'height_cm' => 8, 'is_default' => true],
                ],
                'images' => [
                    ['/images/products/pupuk-npk-premium.svg', 'Pupuk NPK Premium', 1, true],
                    ['/images/products/gallery-detail.svg', 'Detail kemasan pupuk NPK', 2, false],
                    ['/images/products/gallery-field.svg', 'Aplikasi pupuk NPK di lahan', 3, false],
                ],
            ],
            [
                'category_id' => $categories['Pupuk'],
                'sku' => 'PRD-002',
                'slug' => 'pupuk-urea-granule',
                'name' => 'Pupuk Urea Granule',
                'short_description' => 'Sumber nitrogen granule mudah sebar.',
                'description' => 'Sumber nitrogen untuk membantu pertumbuhan daun dan batang dengan aplikasi yang mudah.',
                'price' => 68000,
                'compare_at_price' => 73000,
                'weight_label' => '5 kg',
                'weight_grams' => 5000,
                'length_cm' => 30,
                'width_cm' => 22,
                'height_cm' => 8,
                'stock' => 12,
                'public_status' => 'active',
                'catalog_status' => 'available',
                'badge_label' => 'Favorit',
                'sold_count' => 980,
                'highlights' => ['Granule mudah sebar', 'Mendukung pertumbuhan awal', 'Cocok untuk tanaman pangan'],
                'is_featured' => true,
                'published_at' => now()->subDays(25),
                'variants' => [
                    ['sku' => 'PRD-002-1KG', 'label' => '1 kg', 'price' => 18000, 'compare_at_price' => 20000, 'stock' => 7, 'weight_grams' => 1000, 'length_cm' => 22, 'width_cm' => 15, 'height_cm' => 5, 'is_default' => false],
                    ['sku' => 'PRD-002-5KG', 'label' => '5 kg', 'price' => 68000, 'compare_at_price' => 73000, 'stock' => 5, 'weight_grams' => 5000, 'length_cm' => 30, 'width_cm' => 22, 'height_cm' => 8, 'is_default' => true],
                ],
                'images' => [
                    ['/images/products/pupuk-urea-granule.svg', 'Pupuk Urea Granule', 1, true],
                    ['/images/products/gallery-detail.svg', 'Tekstur granule urea', 2, false],
                ],
            ],
            [
                'category_id' => $categories['Benih'],
                'sku' => 'PRD-003',
                'slug' => 'benih-cabai-f1',
                'name' => 'Benih Cabai F1',
                'short_description' => 'Benih cabai unggul F1.',
                'description' => 'Benih cabai unggul untuk hasil seragam dengan potensi tumbuh yang baik.',
                'price' => 28000,
                'compare_at_price' => 31500,
                'weight_label' => '1 pack',
                'weight_grams' => 100,
                'length_cm' => 12,
                'width_cm' => 9,
                'height_cm' => 1,
                'stock' => 25,
                'public_status' => 'active',
                'catalog_status' => 'limited',
                'badge_label' => 'Baru',
                'sold_count' => 620,
                'highlights' => ['Daya tumbuh baik', 'Cocok untuk budidaya intensif', 'Kemasan praktis'],
                'is_featured' => false,
                'published_at' => now()->subDays(12),
                'variants' => [
                    ['sku' => 'PRD-003-1PK', 'label' => '1 pack', 'price' => 28000, 'compare_at_price' => 31500, 'stock' => 15, 'weight_grams' => 100, 'length_cm' => 12, 'width_cm' => 9, 'height_cm' => 1, 'is_default' => true],
                    ['sku' => 'PRD-003-3PK', 'label' => '3 pack', 'price' => 78000, 'compare_at_price' => 90000, 'stock' => 10, 'weight_grams' => 300, 'length_cm' => 14, 'width_cm' => 10, 'height_cm' => 3, 'is_default' => false],
                ],
                'images' => [
                    ['/images/products/benih-cabai-f1.svg', 'Benih Cabai F1', 1, true],
                    ['/images/products/gallery-seed.svg', 'Benih cabai siap tanam', 2, false],
                ],
            ],
            [
                'category_id' => $categories['Benih'],
                'sku' => 'PRD-004',
                'slug' => 'benih-tomat-unggul',
                'name' => 'Benih Tomat Unggul',
                'short_description' => 'Benih tomat untuk tanam rumahan.',
                'description' => 'Benih tomat untuk kebutuhan tanam rumahan maupun skala kebun kecil.',
                'price' => 24000,
                'compare_at_price' => 27000,
                'weight_label' => '1 pack',
                'weight_grams' => 100,
                'length_cm' => 12,
                'width_cm' => 9,
                'height_cm' => 1,
                'stock' => 7,
                'public_status' => 'draft',
                'catalog_status' => 'available',
                'badge_label' => 'Pilihan',
                'sold_count' => 430,
                'highlights' => ['Mudah ditanam', 'Cocok untuk dataran rendah', 'Buah seragam'],
                'is_featured' => false,
                'published_at' => null,
                'variants' => [
                    ['sku' => 'PRD-004-1PK', 'label' => '1 pack', 'price' => 24000, 'compare_at_price' => 27000, 'stock' => 7, 'weight_grams' => 100, 'length_cm' => 12, 'width_cm' => 9, 'height_cm' => 1, 'is_default' => true],
                ],
                'images' => [
                    ['/images/products/benih-tomat-unggul.svg', 'Benih Tomat Unggul', 1, true],
                    ['/images/products/gallery-seed.svg', 'Detail kemasan benih tomat', 2, false],
                ],
            ],
            [
                'category_id' => $categories['Perlindungan Tanaman'],
                'sku' => 'PRD-005',
                'slug' => 'pestisida-organik',
                'name' => 'Pestisida Organik',
                'short_description' => 'Perlindungan tanaman ramah lingkungan.',
                'description' => 'Formulasi organik untuk membantu perlindungan tanaman dengan penggunaan yang lebih ramah lingkungan.',
                'price' => 64000,
                'compare_at_price' => 69000,
                'weight_label' => '1 liter',
                'weight_grams' => 1000,
                'length_cm' => 10,
                'width_cm' => 10,
                'height_cm' => 25,
                'stock' => 16,
                'public_status' => 'active',
                'catalog_status' => 'available',
                'badge_label' => 'Rekomendasi',
                'sold_count' => 523,
                'highlights' => ['Aplikasi fleksibel', 'Ramah untuk kebun rumah', 'Mudah dicampur'],
                'is_featured' => true,
                'published_at' => now()->subDays(20),
                'variants' => [
                    ['sku' => 'PRD-005-500ML', 'label' => '500 ml', 'price' => 36000, 'compare_at_price' => 40000, 'stock' => 9, 'weight_grams' => 500, 'length_cm' => 8, 'width_cm' => 8, 'height_cm' => 20, 'is_default' => false],
                    ['sku' => 'PRD-005-1L', 'label' => '1 liter', 'price' => 64000, 'compare_at_price' => 69000, 'stock' => 7, 'weight_grams' => 1000, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 25, 'is_default' => true],
                ],
                'images' => [
                    ['/images/products/pestisida-organik.svg', 'Pestisida Organik', 1, true],
                    ['/images/products/gallery-usage.svg', 'Cara penggunaan pestisida organik', 2, false],
                ],
            ],
            [
                'category_id' => $categories['Perlindungan Tanaman'],
                'sku' => 'PRD-006',
                'slug' => 'fungisida-cair',
                'name' => 'Fungisida Cair',
                'short_description' => 'Fungisida cair mudah larut.',
                'description' => 'Fungisida cair untuk membantu pengendalian penyakit jamur pada beberapa jenis tanaman budidaya.',
                'price' => 82000,
                'compare_at_price' => 86500,
                'weight_label' => '500 ml',
                'weight_grams' => 500,
                'length_cm' => 9,
                'width_cm' => 9,
                'height_cm' => 22,
                'stock' => 4,
                'public_status' => 'active',
                'catalog_status' => 'preorder',
                'badge_label' => 'Pre-order',
                'sold_count' => 214,
                'highlights' => ['Cair dan mudah larut', 'Mendukung perlindungan daun', 'Tersedia pre-order'],
                'is_featured' => false,
                'published_at' => now()->subDays(7),
                'variants' => [
                    ['sku' => 'PRD-006-500ML', 'label' => '500 ml', 'price' => 82000, 'compare_at_price' => 86500, 'stock' => 4, 'weight_grams' => 500, 'length_cm' => 9, 'width_cm' => 9, 'height_cm' => 22, 'is_default' => true],
                ],
                'images' => [
                    ['/images/products/fungisida-cair.svg', 'Fungisida Cair', 1, true],
                    ['/images/products/gallery-usage.svg', 'Aplikasi fungisida cair', 2, false],
                ],
            ],
        ];

        foreach ($products as $productData) {
            $images = $productData['images'];
            $variants = $productData['variants'];
            unset($productData['images'], $productData['variants']);

            $product = Product::create($productData);

            foreach ($variants as $index => $variant) {
                $product->variants()->create([
                    'sku' => $variant['sku'],
                    'label' => $variant['label'],
                    'price' => $variant['price'],
                    'compare_at_price' => $variant['compare_at_price'],
                    'stock' => $variant['stock'],
                    'weight_grams' => $variant['weight_grams'],
                    'length_cm' => $variant['length_cm'],
                    'width_cm' => $variant['width_cm'],
                    'height_cm' => $variant['height_cm'],
                    'is_default' => $variant['is_default'],
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ]);
            }

            foreach ($images as [$path, $alt, $sortOrder, $isPrimary]) {
                $product->images()->create([
                    'path' => $path,
                    'alt' => $alt,
                    'sort_order' => $sortOrder,
                    'is_primary' => $isPrimary,
                ]);
            }
        }
    }
}
