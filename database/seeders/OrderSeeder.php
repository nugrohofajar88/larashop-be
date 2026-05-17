<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingService;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Order::query()->delete();

        $customer = User::query()->where('username', 'budisantoso')->first();
        $secondCustomer = User::query()->where('username', 'sarilestari')->first();
        $address = $customer?->addresses()->where('is_primary', true)->first();
        $secondAddress = $secondCustomer?->addresses()->where('is_primary', true)->first();
        $shippingService = ShippingService::query()->where('is_default', true)->first();
        $expressService = ShippingService::query()->where('code', 'jnt-express')->first();
        $products = Product::query()->whereIn('slug', ['pupuk-npk-premium', 'pestisida-organik'])->get();
        $secondProducts = Product::query()->whereIn('slug', ['benih-cabai-f1', 'pupuk-urea-granule'])->get();

        if ($customer === null || $address === null || $shippingService === null || $products->count() < 2) {
            return;
        }

        $itemsTotal = (75000 * 1) + (64000 * 1);
        $uniqueCode = 153;

        $order = Order::create([
            'code' => 'ORD-001',
            'user_id' => $customer->id,
            'customer_address_id' => $address->id,
            'shipping_service_id' => $shippingService->id,
            'status' => 'pending_payment',
            'payment_method' => 'Transfer manual',
            'payment_status' => 'Menunggu transfer',
            'items_total' => $itemsTotal,
            'shipping_total' => $shippingService->price,
            'unique_code' => $uniqueCode,
            'used_unique_code' => 0,
            'grand_total' => $itemsTotal + $shippingService->price + $uniqueCode,
            'shipping_service_name' => $shippingService->name.' '.$shippingService->service_level,
            'shipping_estimate_days' => $shippingService->estimate_days,
            'shipment_note' => 'Menunggu validasi pembayaran sebelum shipment dibuat.',
            'recipient_name' => $address->recipient_name,
            'recipient_phone' => $address->recipient_phone,
            'address_label' => $address->label,
            'address_snapshot' => $address->address_line.', '.$address->subdistrict.', Kec. '.$address->district.', '.$address->city.', '.$address->province.' '.$address->postal_code,
        ]);

        foreach ($products as $product) {
            $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'price' => $product->price,
                'quantity' => 1,
                'subtotal' => $product->price,
            ]);
        }

        if ($secondCustomer === null || $secondAddress === null || $expressService === null || $secondProducts->count() < 2) {
            return;
        }

        $paidOrder = Order::create([
            'code' => 'ORD-002',
            'user_id' => $secondCustomer->id,
            'customer_address_id' => $secondAddress->id,
            'shipping_service_id' => $expressService->id,
            'status' => 'paid',
            'payment_method' => 'Transfer manual',
            'payment_status' => 'Tervalidasi',
            'items_total' => 96000,
            'shipping_total' => $expressService->price,
            'unique_code' => 0,
            'used_unique_code' => 0,
            'grand_total' => 122000,
            'shipping_service_name' => $expressService->name.' '.$expressService->service_level,
            'shipping_estimate_days' => $expressService->estimate_days,
            'shipment_note' => 'Siap dibuat pickup oleh admin.',
            'paid_at' => now()->subDay(),
            'recipient_name' => $secondAddress->recipient_name,
            'recipient_phone' => $secondAddress->recipient_phone,
            'address_label' => $secondAddress->label,
            'address_snapshot' => $secondAddress->address_line.', '.$secondAddress->subdistrict.', Kec. '.$secondAddress->district.', '.$secondAddress->city.', '.$secondAddress->province.' '.$secondAddress->postal_code,
        ]);

        foreach ($secondProducts as $product) {
            $paidOrder->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'price' => $product->price,
                'quantity' => 1,
                'subtotal' => $product->price,
            ]);
        }

        $shippedOrder = Order::create([
            'code' => 'ORD-003',
            'user_id' => $customer->id,
            'customer_address_id' => $address->id,
            'shipping_service_id' => $shippingService->id,
            'status' => 'shipped',
            'payment_method' => 'Transfer manual',
            'payment_status' => 'Tervalidasi',
            'items_total' => 75000,
            'shipping_total' => $shippingService->price,
            'unique_code' => 0,
            'used_unique_code' => 0,
            'grand_total' => 93000,
            'shipping_service_name' => $shippingService->name.' '.$shippingService->service_level,
            'shipping_estimate_days' => $shippingService->estimate_days,
            'awb' => 'JNT00123456789',
            'shipment_note' => 'Paket sudah dijemput kurir dan dalam perjalanan.',
            'paid_at' => now()->subDays(3),
            'shipped_at' => now()->subDays(2),
            'recipient_name' => $address->recipient_name,
            'recipient_phone' => $address->recipient_phone,
            'address_label' => $address->label,
            'address_snapshot' => $address->address_line.', '.$address->subdistrict.', Kec. '.$address->district.', '.$address->city.', '.$address->province.' '.$address->postal_code,
        ]);

        $shippedOrder->items()->create([
            'product_id' => $products->first()->id,
            'product_name' => $products->first()->name,
            'product_sku' => $products->first()->sku,
            'price' => $products->first()->price,
            'quantity' => 1,
            'subtotal' => $products->first()->price,
        ]);
    }
}
