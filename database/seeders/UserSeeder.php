<?php

namespace Database\Seeders;

use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::query()->delete();

        $admin = User::create([
            'code' => 'ADM-001',
            'name' => 'Larashop Admin',
            'username' => 'adminlarashop',
            'email' => 'admin@larashop.test',
            'phone' => '081100000001',
            'role' => 'admin',
            'admin_role' => 'super_admin',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);

        User::create([
            'code' => 'ADM-002',
            'name' => 'Rina Kartika',
            'username' => 'rinaops',
            'email' => 'rina.ops@larashop.test',
            'phone' => '081322004455',
            'role' => 'admin',
            'admin_role' => 'operational_admin',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);

        User::create([
            'code' => 'ADM-003',
            'name' => 'Andi Saputra',
            'username' => 'andigudang',
            'email' => 'andi.gudang@larashop.test',
            'phone' => '081977001122',
            'role' => 'admin',
            'admin_role' => 'warehouse_admin',
            'status' => 'inactive',
            'password' => Hash::make('password'),
        ]);

        $primaryCustomer = User::create([
            'code' => 'CST-001',
            'name' => 'Budi Santoso',
            'username' => 'budisantoso',
            'phone' => '081234567890',
            'role' => 'customer',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);

        $secondaryCustomer = User::create([
            'code' => 'CST-002',
            'name' => 'Sari Lestari',
            'username' => 'sarilestari',
            'phone' => '081355552211',
            'role' => 'customer',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);

        $pendingCustomer = User::create([
            'code' => 'CST-003',
            'name' => 'Mitra Sawah',
            'username' => 'mitrasawah',
            'phone' => '081988887123',
            'role' => 'customer',
            'status' => 'pending_verification',
            'password' => Hash::make('password'),
        ]);

        CustomerAddress::insert([
            [
                'user_id' => $primaryCustomer->id,
                'label' => 'Alamat Utama',
                'recipient_name' => 'Budi Santoso',
                'recipient_phone' => '081234567890',
                'destination_id' => 68423,
                'province' => 'Jawa Barat',
                'city' => 'Bogor',
                'district' => 'Cibungbulang',
                'subdistrict' => 'Sukamaju',
                'postal_code' => '16630',
                'address_line' => 'Jl. Melati No. 12',
                'note' => 'Rumah pagar putih, dekat mushola.',
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $primaryCustomer->id,
                'label' => 'Gudang Kebun',
                'recipient_name' => 'Budi Santoso',
                'recipient_phone' => '081234567890',
                'destination_id' => 68423,
                'province' => 'Jawa Barat',
                'city' => 'Bogor',
                'district' => 'Cibungbulang',
                'subdistrict' => 'Leuweung Kolot',
                'postal_code' => '16630',
                'address_line' => 'Kp. Sukamaju RT 02/RW 01',
                'note' => 'Dekat pasar tani dan gudang pupuk.',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $secondaryCustomer->id,
                'label' => 'Rumah',
                'recipient_name' => 'Sari Lestari',
                'recipient_phone' => '081355552211',
                'destination_id' => 47280,
                'province' => 'Jawa Timur',
                'city' => 'Malang',
                'district' => 'Singosari',
                'subdistrict' => 'Pagentan',
                'postal_code' => '65153',
                'address_line' => 'Jl. Sidomulyo Timur No. 83',
                'note' => 'Dinding keramik biru.',
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $pendingCustomer->id,
                'label' => 'Kebun Mitra',
                'recipient_name' => 'Mitra Sawah',
                'recipient_phone' => '081988887123',
                'destination_id' => 47075,
                'province' => 'Jawa Tengah',
                'city' => 'Klaten',
                'district' => 'Delanggu',
                'subdistrict' => 'Karang',
                'postal_code' => '57471',
                'address_line' => 'Jl. Raya Delanggu No. 7',
                'note' => 'Pintu samping kios pupuk.',
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
