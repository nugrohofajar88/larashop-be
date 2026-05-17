<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ShipmentOrigin;
use App\Models\ShippingService;
use App\Models\User;
use App\Models\UserUniqueCode;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        OrderItem::query()->delete();
        Order::query()->delete();
        ProductImage::query()->delete();
        ProductVariant::query()->delete();
        Product::query()->delete();
        Category::query()->delete();
        CustomerAddress::query()->delete();
        ShippingService::query()->delete();
        ShipmentOrigin::query()->delete();
        UserUniqueCode::query()->delete();
        User::query()->delete();
        Schema::enableForeignKeyConstraints();

        $this->call([
            UserSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            ShippingServiceSeeder::class,
            ShipmentOriginSeeder::class,
            OrderSeeder::class,
        ]);
    }
}
