<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\UserUniqueCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_products_api_returns_filtered_catalog(): void
    {
        $response = $this->getJson('/api/v1/products?category=Pupuk&sort=price_desc');

        $response->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('data.0.slug', 'pupuk-npk-premium')
            ->assertJsonPath('data.1.slug', 'pupuk-urea-granule');
    }

    public function test_product_detail_api_returns_related_products(): void
    {
        $response = $this->getJson('/api/v1/products/pestisida-organik');

        $response->assertOk()
            ->assertJsonPath('data.product.slug', 'pestisida-organik')
            ->assertJsonCount(3, 'data.related_products');
    }

    public function test_products_api_hides_items_with_stock_one_or_less(): void
    {
        Product::query()->where('slug', 'fungisida-cair')->update(['stock' => 1]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonMissing(['slug' => 'fungisida-cair']);

        $this->assertNotContains(
            'fungisida-cair',
            collect($response->json('data'))->pluck('slug')->all()
        );

        $this->getJson('/api/v1/products/fungisida-cair')->assertNotFound();
    }

    public function test_customer_addresses_api_returns_multiple_addresses_for_authenticated_customer(): void
    {
        Sanctum::actingAs(User::query()->where('username', 'budisantoso')->firstOrFail());

        $response = $this->getJson('/api/v1/customer/addresses');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.label', 'Alamat Utama');
    }

    public function test_customer_destination_search_proxies_rajaongkir_results(): void
    {
        Sanctum::actingAs(User::query()->where('username', 'budisantoso')->firstOrFail());

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/destination/domestic-destination*' => Http::response([
                'data' => [
                    [
                        'id' => 47071,
                        'label' => 'ARDIREJO, KEPANJEN, MALANG, JAWA TIMUR, 65163',
                        'province_name' => 'JAWA TIMUR',
                        'city_name' => 'MALANG',
                        'district_name' => 'KEPANJEN',
                        'subdistrict_name' => 'ARDIREJO',
                        'zip_code' => '65163',
                    ],
                ],
            ]),
        ]);

        $response = $this->getJson('/api/v1/customer/destinations/search?search=65163');

        $response->assertOk()
            ->assertJsonPath('data.0.city_name', 'MALANG')
            ->assertJsonPath('data.0.zip_code', '65163');
    }

    public function test_customer_can_update_profile_without_resetting_password(): void
    {
        $customer = User::query()->where('username', 'budisantoso')->firstOrFail();
        $oldPasswordHash = $customer->password;
        Sanctum::actingAs($customer);

        $response = $this->putJson('/api/v1/customer/profile', [
            'name' => 'Budi Santoso Baru',
            'username' => 'budisantoso',
            'email' => 'budi.baru@larashop.test',
            'phone' => '081234567890',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Budi Santoso Baru')
            ->assertJsonPath('data.email', 'budi.baru@larashop.test');

        $customer->refresh();

        $this->assertSame($oldPasswordHash, $customer->password);
    }

    public function test_customer_can_update_profile_and_reset_password_when_password_is_filled(): void
    {
        $customer = User::query()->where('username', 'budisantoso')->firstOrFail();
        Sanctum::actingAs($customer);

        $response = $this->putJson('/api/v1/customer/profile', [
            'name' => 'Budi Santoso',
            'username' => 'budisantoso',
            'email' => 'budi@larashop.test',
            'phone' => '081234567890',
            'password' => 'Password456',
            'password_confirmation' => 'Password456',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'budi@larashop.test');

        $customer->refresh();

        $this->assertTrue(Hash::check('Password456', $customer->password));
    }

    public function test_checkout_api_returns_summary_and_shipping_options(): void
    {
        Sanctum::actingAs(User::query()->where('username', 'budisantoso')->firstOrFail());

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost*' => Http::response([
                'data' => [
                    [
                        'name' => 'J&T Express',
                        'code' => 'jnt',
                        'service' => 'EZ',
                        'description' => 'Reguler',
                        'cost' => 16000,
                        'etd' => '2 day',
                    ],
                    [
                        'name' => 'Jalur Nugraha Ekakurir (JNE)',
                        'code' => 'jne',
                        'service' => 'REG',
                        'description' => 'Layanan Reguler',
                        'cost' => 18000,
                        'etd' => '3 day',
                    ],
                ],
            ]),
        ]);

        $response = $this->getJson('/api/v1/checkout');

        $response->assertOk()
            ->assertJsonPath('data.address.label', 'Alamat Utama')
            ->assertJsonPath('data.shipment_origin.label', 'Gudang Utama Malang')
            ->assertJsonPath('data.payment_summary.shipping_total_value', 16000)
            ->assertJsonPath('data.shipping_options.0.service', 'J&T Express - Reguler')
            ->assertJsonCount(2, 'data.shipping_options');

        Http::assertSentCount(1);
    }

    public function test_checkout_api_can_disable_unique_code_from_config(): void
    {
        config(['services.checkout.use_unique_code' => false]);
        Sanctum::actingAs(User::query()->where('username', 'budisantoso')->firstOrFail());

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost*' => Http::response([
                'data' => [
                    [
                        'name' => 'J&T Express',
                        'code' => 'jnt',
                        'service' => 'EZ',
                        'description' => 'Reguler',
                        'cost' => 16000,
                        'etd' => '2 day',
                    ],
                ],
            ]),
        ]);

        $response = $this->getJson('/api/v1/checkout');

        $response->assertOk()
            ->assertJsonPath('data.payment_summary.unique_code_enabled', false)
            ->assertJsonPath('data.payment_summary.unique_code_value', 0);
    }

    public function test_checkout_api_can_apply_unique_code_balance_when_requested(): void
    {
        $customer = User::query()->where('username', 'budisantoso')->firstOrFail();
        UserUniqueCode::query()->create([
            'user_id' => $customer->id,
            'value' => 250,
            'ref_id' => 1001,
            'type' => 'paid',
        ]);

        Sanctum::actingAs($customer);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost*' => Http::response([
                'data' => [
                    [
                        'name' => 'J&T Express',
                        'code' => 'jnt',
                        'service' => 'EZ',
                        'description' => 'Reguler',
                        'cost' => 16000,
                        'etd' => '2 day',
                    ],
                ],
            ]),
        ]);

        $response = $this->getJson('/api/v1/checkout?use_unique_code_balance=1');

        $response->assertOk()
            ->assertJsonPath('data.payment_summary.use_unique_code_balance', true)
            ->assertJsonPath('data.payment_summary.available_unique_code_balance_value', 250)
            ->assertJsonPath('data.payment_summary.used_unique_code_value', 250)
            ->assertJsonPath('data.payment_summary.grand_total_value', 15750);
    }

    public function test_customer_can_add_specific_variant_to_cart(): void
    {
        $customer = User::query()->where('username', 'budisantoso')->firstOrFail();
        Sanctum::actingAs($customer);
        $product = Product::query()->where('slug', 'pupuk-npk-premium')->firstOrFail();
        $variant = $product->variants()->where('label', '1 kg')->firstOrFail();

        $response = $this->postJson('/api/v1/customer/cart/items', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'selected' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.item.product_variant_id', $variant->id)
            ->assertJsonPath('data.item.variant', '1 kg')
            ->assertJsonPath('data.item.qty', 2)
            ->assertJsonPath('data.summary.selected_product_count', 2);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'variant_label' => '1 kg',
            'weight_grams' => 1000,
            'quantity' => 2,
            'is_selected' => true,
        ]);
    }

    public function test_checkout_api_uses_selected_variant_weight(): void
    {
        $customer = User::query()->where('username', 'budisantoso')->firstOrFail();
        Sanctum::actingAs($customer);
        $draftOrder = \App\Models\Order::query()->firstOrCreate(
            [
                'user_id' => $customer->id,
                'status' => 'draft',
            ],
            [
                'code' => 'DRF-TEST-001',
                'payment_method' => 'Transfer manual',
                'payment_status' => 'Draft',
                'unique_code' => 111,
                'used_unique_code' => 0,
            ]
        );

        $draftOrder->items()->delete();

        $product = Product::query()->where('slug', 'pupuk-npk-premium')->firstOrFail();
        $variant = $product->variants()->where('label', '500 gr')->firstOrFail();

        $draftOrder->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'product_sku' => $variant->sku,
            'variant_label' => $variant->label,
            'weight_grams' => $variant->weight_grams,
            'price' => $variant->price,
            'quantity' => 2,
            'subtotal' => $variant->price * 2,
            'is_selected' => true,
        ]);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost*' => Http::response([
                'data' => [
                    [
                        'name' => 'J&T Express',
                        'code' => 'jnt',
                        'service' => 'EZ',
                        'description' => 'Reguler',
                        'cost' => 7000,
                        'etd' => '2 day',
                    ],
                ],
            ]),
        ]);

        $response = $this->getJson('/api/v1/checkout');

        $response->assertOk()
            ->assertJsonPath('data.payment_summary.shipping_total_value', 7000);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'calculate/domestic-cost')
                && (int) $request['weight'] === 1000;
        });
    }
    public function test_admin_customers_api_supports_username_search(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());

        $response = $this->getJson('/api/v1/admin/customers?search=mitrasawah');

        $response->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.username', 'mitrasawah');
    }

    public function test_customer_route_requires_token(): void
    {
        $this->getJson('/api/v1/customer/profile')->assertUnauthorized();
    }

    public function test_customer_cannot_access_admin_route(): void
    {
        Sanctum::actingAs(User::query()->where('username', 'budisantoso')->firstOrFail());

        $this->getJson('/api/v1/admin/products')->assertForbidden();
    }

    public function test_login_returns_sanctum_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'budisantoso',
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.username', 'budisantoso')
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user']]);
    }

    public function test_customer_orders_api_returns_authenticated_order_history(): void
    {
        Sanctum::actingAs(User::query()->where('username', 'budisantoso')->firstOrFail());

        $response = $this->getJson('/api/v1/customer/orders');

        $response->assertOk()
            ->assertJsonPath('data.0.code', 'ORD-003')
            ->assertJsonPath('data.1.code', 'ORD-001');
    }
    public function test_customer_can_cancel_pending_payment_order_and_restore_used_unique_code_ledger(): void
    {
        $customer = User::query()->where('username', 'budisantoso')->firstOrFail();
        $order = \App\Models\Order::query()->where('code', 'ORD-001')->firstOrFail();

        UserUniqueCode::query()->create([
            'user_id' => $order->user_id,
            'value' => 120,
            'ref_id' => $order->id,
            'type' => 'used',
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/customer/orders/'.$order->code.'/cancel');

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'Dibatalkan customer');

        $this->assertDatabaseMissing('user_unique_codes', [
            'user_id' => $order->user_id,
            'ref_id' => $order->id,
            'type' => 'used',
        ]);
    }

    public function test_customer_cannot_cancel_order_that_is_already_paid(): void
    {
        $customer = User::query()->where('username', 'budisantoso')->firstOrFail();
        $order = \App\Models\Order::query()->where('code', 'ORD-003')->firstOrFail();

        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/customer/orders/'.$order->code.'/cancel')
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');
    }


    public function test_customer_can_complete_shipped_order(): void
    {
        $customer = User::query()->where('username', 'budisantoso')->firstOrFail();
        $order = \App\Models\Order::query()->where('code', 'ORD-003')->firstOrFail();

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/customer/orders/'.$order->code.'/complete');

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_customer_cannot_complete_order_that_is_not_shipped(): void
    {
        $customer = User::query()->where('username', 'budisantoso')->firstOrFail();
        $order = \App\Models\Order::query()->where('code', 'ORD-001')->firstOrFail();

        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/customer/orders/'.$order->code.'/complete')
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');
    }
    public function test_admin_accounts_api_returns_admin_users(): void{
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());

        $response = $this->getJson('/api/v1/admin/accounts');

        $response->assertOk()
            ->assertJsonPath('data.0.id', 'ADM-003')
            ->assertJsonPath('meta.count', 3);
    }

    public function test_non_super_admin_cannot_see_super_admin_accounts(): void
    {
        Sanctum::actingAs(User::query()->where('admin_role', 'operational_admin')->firstOrFail());

        $response = $this->getJson('/api/v1/admin/accounts');

        $response->assertOk();

        $this->assertNotContains(
            'super_admin',
            collect($response->json('data'))->pluck('role_key')->all()
        );
    }
    public function test_admin_can_create_account(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());

        $response = $this->postJson('/api/v1/admin/accounts', [
            'name' => 'Nadia Operasional',
            'username' => 'nadiaops',
            'email' => 'nadia.ops@larashop.test',
            'phone' => '081244455566',
            'admin_role' => 'operational_admin',
            'status' => 'active',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Nadia Operasional')
            ->assertJsonPath('data.role_key', 'operational_admin');
    }

    public function test_admin_can_delete_account(): void
    {
        Sanctum::actingAs(User::query()->where('admin_role', 'super_admin')->firstOrFail());
        $target = User::query()->where('admin_role', 'warehouse_admin')->firstOrFail();

        $response = $this->deleteJson('/api/v1/admin/accounts/'.$target->id);

        $response->assertOk();

        $this->assertDatabaseMissing('users', [
            'id' => $target->id,
        ]);
    }

    public function test_admin_orders_api_returns_orders(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());

        $response = $this->getJson('/api/v1/admin/orders');

        $response->assertOk()
            ->assertJsonPath('data.0.code', 'ORD-003')
            ->assertJsonPath('data.1.code', 'ORD-002');
    }

    public function test_admin_can_validate_order_payment(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());
        $order = \App\Models\Order::query()->where('code', 'ORD-001')->firstOrFail();

        $response = $this->postJson('/api/v1/admin/orders/'.$order->id.'/validate-payment');

        $response->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_status', 'Tervalidasi');
    }

    public function test_admin_can_store_unique_code_credit_when_payment_is_validated(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());
        $order = \App\Models\Order::query()->where('code', 'ORD-001')->firstOrFail();

        $this->postJson('/api/v1/admin/orders/'.$order->id.'/validate-payment')
            ->assertOk();

        $this->assertDatabaseHas('user_unique_codes', [
            'user_id' => $order->user_id,
            'ref_id' => $order->id,
            'type' => 'paid',
            'value' => $order->unique_code,
        ]);
    }

    public function test_admin_can_cancel_order_and_restore_unique_code_ledger(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());
        $order = \App\Models\Order::query()->where('code', 'ORD-001')->firstOrFail();

        UserUniqueCode::query()->create([
            'user_id' => $order->user_id,
            'value' => $order->unique_code,
            'ref_id' => $order->id,
            'type' => 'paid',
        ]);

        UserUniqueCode::query()->create([
            'user_id' => $order->user_id,
            'value' => 120,
            'ref_id' => $order->id,
            'type' => 'used',
        ]);

        $response = $this->postJson('/api/v1/admin/orders/'.$order->id.'/cancel');

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'Dibatalkan admin');

        $this->assertDatabaseMissing('user_unique_codes', [
            'user_id' => $order->user_id,
            'ref_id' => $order->id,
            'type' => 'paid',
        ]);

        $this->assertDatabaseMissing('user_unique_codes', [
            'user_id' => $order->user_id,
            'ref_id' => $order->id,
            'type' => 'used',
        ]);
    }


    public function test_admin_can_complete_shipped_order(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());
        $order = \App\Models\Order::query()->where('code', 'ORD-003')->firstOrFail();

        $response = $this->postJson('/api/v1/admin/orders/'.$order->id.'/complete');

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_admin_cannot_complete_order_that_is_not_shipped(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());
        $order = \App\Models\Order::query()->where('code', 'ORD-001')->firstOrFail();

        $this->postJson('/api/v1/admin/orders/'.$order->id.'/complete')
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');
    }
    public function test_admin_shipments_api_returns_shippable_orders(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());

        $response = $this->getJson('/api/v1/admin/shipments');

        $response->assertOk()
            ->assertJsonPath('data.0.code', 'SHP-ORD-003');
    }

    public function test_admin_can_view_shipment_settings(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());

        $response = $this->getJson('/api/v1/admin/shipment-settings');

        $response->assertOk()
            ->assertJsonPath('data.label', 'Gudang Utama Malang')
            ->assertJsonPath('data.city', 'MALANG')
            ->assertJsonPath('data.selected_courier', 'jnt');
    }

    public function test_admin_can_update_shipment_settings(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());

        $response = $this->putJson('/api/v1/admin/shipment-settings', [
            'label' => 'Gudang Cabang Singosari',
            'contact_name' => 'Tim Kirim Larashop',
            'contact_phone' => '0341765432',
            'origin_id' => 47280,
            'selected_courier' => 'jnt:jne:sicepat',
            'province' => 'JAWA TIMUR',
            'city' => 'MALANG',
            'district' => 'SINGOSARI',
            'subdistrict' => 'ARDIMULYO',
            'postal_code' => '65153',
            'address_line' => 'Jl. Sidomulyo Timur No. 83',
            'note' => 'Titik pickup utama untuk wilayah Malang utara.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.label', 'Gudang Cabang Singosari')
            ->assertJsonPath('data.origin_id', 47280)
            ->assertJsonPath('data.selected_courier', 'jnt:jne:sicepat');

        $this->assertDatabaseHas('shipment_origins', [
            'label' => 'Gudang Cabang Singosari',
            'subdistrict' => 'ARDIMULYO',
            'is_default' => true,
            'selected_courier' => 'jnt:jne:sicepat',
        ]);
    }

    public function test_customer_can_create_new_address(): void
    {
        Sanctum::actingAs(User::query()->where('username', 'budisantoso')->firstOrFail());

        $response = $this->postJson('/api/v1/customer/addresses', [
            'label' => 'Cabang Kebun',
            'recipient_name' => 'Budi Santoso',
            'recipient_phone' => '081234567890',
            'province' => 'Jawa Barat',
            'city' => 'Bogor',
            'district' => 'Cibungbulang',
            'subdistrict' => 'Galuga',
            'postal_code' => '16630',
            'address_line' => 'Jl. Kebun Raya Blok C1',
            'note' => 'Samping kios bibit.',
            'is_primary' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.label', 'Cabang Kebun');

        $this->assertDatabaseHas('customer_addresses', [
            'label' => 'Cabang Kebun',
            'subdistrict' => 'Galuga',
        ]);
    }

    public function test_customer_can_change_primary_address(): void
    {
        $customer = User::query()->where('username', 'budisantoso')->firstOrFail();
        Sanctum::actingAs($customer);
        $secondaryAddress = $customer->addresses()->where('is_primary', false)->firstOrFail();

        $response = $this->putJson('/api/v1/customer/addresses/'.$secondaryAddress->id, [
            'label' => $secondaryAddress->label,
            'recipient_name' => $secondaryAddress->recipient_name,
            'recipient_phone' => $secondaryAddress->recipient_phone,
            'province' => $secondaryAddress->province,
            'city' => $secondaryAddress->city,
            'district' => $secondaryAddress->district,
            'subdistrict' => $secondaryAddress->subdistrict,
            'postal_code' => $secondaryAddress->postal_code,
            'address_line' => $secondaryAddress->address_line,
            'note' => $secondaryAddress->note,
            'is_primary' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_primary', true);

        $this->assertDatabaseHas('customer_addresses', [
            'id' => $secondaryAddress->id,
            'is_primary' => true,
        ]);
    }

    public function test_admin_can_create_product(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());

        $response = $this->postJson('/api/v1/admin/products', [
            'sku' => 'PRD-900',
            'slug' => 'sprayer-mini',
            'name' => 'Sprayer Mini',
            'category_slug' => 'perlindungan-tanaman',
            'short_description' => 'Sprayer mini untuk aplikasi ringan.',
            'description' => 'Sprayer mini untuk kebutuhan semprot skala rumahan.',
            'price' => 55000,
            'compare_at_price' => 60000,
            'weight_label' => '1 unit',
            'weight_grams' => 700,
            'stock' => 9,
            'public_status' => 'active',
            'catalog_status' => 'available',
            'badge_label' => 'Baru',
            'sold_count' => 5,
            'highlights' => ['Ringan', 'Mudah dipakai'],
            'image_paths' => ['/images/products/sprayer-mini.svg'],
            'is_featured' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'sprayer-mini')
            ->assertJsonPath('data.category_slug', 'perlindungan-tanaman');

        $this->assertDatabaseHas('products', [
            'sku' => 'PRD-900',
            'slug' => 'sprayer-mini',
        ]);
    }

    public function test_admin_can_update_product_status(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());
        $product = \App\Models\Product::query()->where('slug', 'benih-tomat-unggul')->firstOrFail();

        $response = $this->putJson('/api/v1/admin/products/'.$product->id, [
            'sku' => $product->sku,
            'slug' => $product->slug,
            'name' => $product->name,
            'category_slug' => $product->category->slug,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'price' => $product->price,
            'compare_at_price' => $product->compare_at_price,
            'weight_label' => $product->weight_label,
            'weight_grams' => $product->weight_grams,
            'stock' => $product->stock,
            'public_status' => 'active',
            'catalog_status' => 'available',
            'badge_label' => $product->badge_label,
            'sold_count' => $product->sold_count,
            'highlights' => $product->highlights,
            'image_paths' => $product->images->pluck('path')->all(),
            'is_featured' => $product->is_featured,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.public_status', 'active');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'public_status' => 'active',
        ]);
    }

    public function test_admin_can_create_customer_with_address(): void
    {
        Sanctum::actingAs(User::query()->where('role', 'admin')->firstOrFail());

        $response = $this->postJson('/api/v1/admin/customers', [
            'name' => 'Dewi Pertiwi',
            'username' => 'dewipertiwi',
            'phone' => '081277788899',
            'status' => 'active',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'addresses' => [
                [
                    'label' => 'Rumah',
                    'recipient_name' => 'Dewi Pertiwi',
                    'recipient_phone' => '081277788899',
                    'province' => 'DIY',
                    'city' => 'Sleman',
                    'district' => 'Ngaglik',
                    'subdistrict' => 'Sariharjo',
                    'postal_code' => '55581',
                    'address_line' => 'Jl. Palagan Km 9',
                    'note' => 'Pagar hitam.',
                    'is_primary' => true,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.username', 'dewipertiwi')
            ->assertJsonPath('data.address_count', 1);

        $this->assertDatabaseHas('users', [
            'username' => 'dewipertiwi',
            'role' => 'customer',
        ]);
    }
}


































