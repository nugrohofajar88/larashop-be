<?php

namespace App\Support;

class DemoStorefrontData
{
    public static function products(): array
    {
        return [
            [
                'slug' => 'pupuk-npk-premium',
                'name' => 'Pupuk NPK Premium',
                'image' => '/images/products/pupuk-npk-premium.svg',
                'category' => 'Pupuk',
                'price' => 'Rp75.000',
                'price_value' => 75000,
                'original_price' => 'Rp82.000',
                'discount_badge' => '-9%',
                'weight' => '5 kg',
                'status' => 'Tersedia',
                'stock' => 'Stok siap kirim',
                'badge' => 'Terlaris',
                'sold_label' => '1,4RB+ terjual',
                'description' => 'Pupuk majemuk untuk mendukung pertumbuhan vegetatif dan generatif pada berbagai jenis tanaman.',
                'highlights' => ['Nutrisi seimbang', 'Cocok untuk sayur dan buah', 'Praktis untuk pemupukan rutin'],
            ],
            [
                'slug' => 'pupuk-urea-granule',
                'name' => 'Pupuk Urea Granule',
                'image' => '/images/products/pupuk-urea-granule.svg',
                'category' => 'Pupuk',
                'price' => 'Rp68.000',
                'price_value' => 68000,
                'original_price' => 'Rp73.000',
                'discount_badge' => '-7%',
                'weight' => '5 kg',
                'status' => 'Tersedia',
                'stock' => 'Stok siap kirim',
                'badge' => 'Favorit',
                'sold_label' => '980+ terjual',
                'description' => 'Sumber nitrogen untuk membantu pertumbuhan daun dan batang dengan aplikasi yang mudah.',
                'highlights' => ['Granule mudah sebar', 'Mendukung pertumbuhan awal', 'Cocok untuk tanaman pangan'],
            ],
            [
                'slug' => 'benih-cabai-f1',
                'name' => 'Benih Cabai F1',
                'image' => '/images/products/benih-cabai-f1.svg',
                'category' => 'Benih',
                'price' => 'Rp28.000',
                'price_value' => 28000,
                'original_price' => 'Rp31.500',
                'discount_badge' => '-11%',
                'weight' => '1 pack',
                'status' => 'Stok terbatas',
                'stock' => 'Stok terbatas',
                'badge' => 'Baru',
                'sold_label' => '620+ terjual',
                'description' => 'Benih cabai unggul untuk hasil seragam dengan potensi tumbuh yang baik.',
                'highlights' => ['Daya tumbuh baik', 'Cocok untuk budidaya intensif', 'Kemasan praktis'],
            ],
            [
                'slug' => 'benih-tomat-unggul',
                'name' => 'Benih Tomat Unggul',
                'image' => '/images/products/benih-tomat-unggul.svg',
                'category' => 'Benih',
                'price' => 'Rp24.000',
                'price_value' => 24000,
                'original_price' => 'Rp27.000',
                'discount_badge' => '-11%',
                'weight' => '1 pack',
                'status' => 'Tersedia',
                'stock' => 'Stok tersedia',
                'badge' => 'Pilihan',
                'sold_label' => '430+ terjual',
                'description' => 'Benih tomat untuk kebutuhan tanam rumahan maupun skala kebun kecil.',
                'highlights' => ['Mudah ditanam', 'Cocok untuk dataran rendah', 'Buah seragam'],
            ],
            [
                'slug' => 'pestisida-organik',
                'name' => 'Pestisida Organik',
                'image' => '/images/products/pestisida-organik.svg',
                'category' => 'Perlindungan Tanaman',
                'price' => 'Rp64.000',
                'price_value' => 64000,
                'original_price' => 'Rp69.000',
                'discount_badge' => '-7%',
                'weight' => '1 liter',
                'status' => 'Tersedia',
                'stock' => 'Siap kirim hari ini',
                'badge' => 'Rekomendasi',
                'sold_label' => '523 terjual',
                'description' => 'Formulasi organik untuk membantu perlindungan tanaman dengan penggunaan yang lebih ramah lingkungan.',
                'highlights' => ['Aplikasi fleksibel', 'Ramah untuk kebun rumah', 'Mudah dicampur'],
            ],
            [
                'slug' => 'fungisida-cair',
                'name' => 'Fungisida Cair',
                'image' => '/images/products/fungisida-cair.svg',
                'category' => 'Perlindungan Tanaman',
                'price' => 'Rp82.000',
                'price_value' => 82000,
                'original_price' => 'Rp86.500',
                'discount_badge' => '-5%',
                'weight' => '500 ml',
                'status' => 'Pre-order',
                'stock' => 'Pre-order',
                'badge' => 'Pre-order',
                'sold_label' => '214 terjual',
                'description' => 'Fungisida cair untuk membantu pengendalian penyakit jamur pada beberapa jenis tanaman budidaya.',
                'highlights' => ['Cair dan mudah larut', 'Mendukung perlindungan daun', 'Tersedia pre-order'],
            ],
        ];
    }

    public static function customer(): array
    {
        return [
            'name' => 'Budi Santoso',
            'username' => 'budisantoso',
            'phone' => '0812-3456-7890',
        ];
    }

    public static function addresses(): array
    {
        return [
            [
                'id' => 'addr-utama',
                'label' => 'Alamat Utama',
                'name' => 'Budi Santoso',
                'phone' => '0812-3456-7890',
                'province' => 'Jawa Barat',
                'city' => 'Bogor',
                'district' => 'Cibungbulang',
                'subdistrict' => 'Sukamaju',
                'postal_code' => '16630',
                'address_line' => 'Jl. Melati No. 12',
                'note' => 'Rumah pagar putih, dekat mushola.',
                'is_primary' => true,
            ],
            [
                'id' => 'addr-gudang',
                'label' => 'Gudang Kebun',
                'name' => 'Budi Santoso',
                'phone' => '0812-3456-7890',
                'province' => 'Jawa Barat',
                'city' => 'Bogor',
                'district' => 'Cibungbulang',
                'subdistrict' => 'Leuweung Kolot',
                'postal_code' => '16630',
                'address_line' => 'Kp. Sukamaju RT 02/RW 01',
                'note' => 'Dekat pasar tani dan gudang pupuk.',
                'is_primary' => false,
            ],
        ];
    }

    public static function shippingOptions(): array
    {
        return [
            ['service' => 'JNT Regular', 'estimate' => '2-4 hari', 'price' => 'Rp18.000', 'price_value' => 18000, 'selected' => true],
            ['service' => 'JNT Express', 'estimate' => '1-2 hari', 'price' => 'Rp26.000', 'price_value' => 26000, 'selected' => false],
        ];
    }

    public static function addressSummary(array $address): string
    {
        return collect([
            $address['address_line'] ?? null,
            $address['subdistrict'] ?? null,
            'Kec. '.($address['district'] ?? ''),
            $address['city'] ?? null,
            $address['province'] ?? null,
            $address['postal_code'] ?? null,
        ])->filter(fn (?string $value) => $value !== null && $value !== '' && $value !== 'Kec. ')->implode(', ');
    }

    public static function checkout(): array
    {
        $addresses = array_map(function (array $address): array {
            $address['detail'] = self::addressSummary($address);

            return $address;
        }, self::addresses());

        $selectedAddress = collect($addresses)->firstWhere('is_primary', true) ?? $addresses[0];
        $shippingOptions = self::shippingOptions();
        $selectedShipping = collect($shippingOptions)->firstWhere('selected', true) ?? $shippingOptions[0];
        $itemsTotalValue = 178000;
        $uniqueCodeValue = 153;

        return [
            'address' => $selectedAddress,
            'addresses' => $addresses,
            'shipping_options' => $shippingOptions,
            'payment_summary' => [
                'items_total' => 'Rp178.000',
                'items_total_value' => $itemsTotalValue,
                'shipping_total' => $selectedShipping['price'],
                'shipping_total_value' => $selectedShipping['price_value'],
                'unique_code' => 'Rp153',
                'unique_code_value' => $uniqueCodeValue,
                'grand_total' => 'Rp196.153',
                'grand_total_value' => $itemsTotalValue + $selectedShipping['price_value'] + $uniqueCodeValue,
            ],
        ];
    }

    public static function adminProducts(): array
    {
        return [
            ['sku' => 'PRD-001', 'slug' => 'pupuk-npk-premium', 'name' => 'Pupuk NPK Premium', 'category' => 'Pupuk', 'price' => 'Rp75.000', 'price_input' => '75000', 'stock' => 48, 'status' => 'Aktif'],
            ['sku' => 'PRD-002', 'slug' => 'pupuk-urea-granule', 'name' => 'Pupuk Urea Granule', 'category' => 'Pupuk', 'price' => 'Rp68.000', 'price_input' => '68000', 'stock' => 12, 'status' => 'Aktif'],
            ['sku' => 'PRD-003', 'slug' => 'benih-cabai-f1', 'name' => 'Benih Cabai F1', 'category' => 'Benih', 'price' => 'Rp28.000', 'price_input' => '28000', 'stock' => 25, 'status' => 'Aktif'],
            ['sku' => 'PRD-004', 'slug' => 'benih-tomat-unggul', 'name' => 'Benih Tomat Unggul', 'category' => 'Benih', 'price' => 'Rp24.000', 'price_input' => '24000', 'stock' => 7, 'status' => 'Draft'],
        ];
    }

    public static function adminCustomers(): array
    {
        return [
            ['code' => 'CST-001', 'name' => 'Budi Santoso', 'username' => 'budisantoso', 'phone' => '0812-3456-7890', 'status' => 'Aktif', 'address_count' => 2],
            ['code' => 'CST-002', 'name' => 'Sari Lestari', 'username' => 'sarilestari', 'phone' => '0813-5555-2211', 'status' => 'Aktif', 'address_count' => 1],
            ['code' => 'CST-003', 'name' => 'Mitra Sawah', 'username' => 'mitrasawah', 'phone' => '0819-8888-7123', 'status' => 'Menunggu verifikasi', 'address_count' => 1],
        ];
    }
}
