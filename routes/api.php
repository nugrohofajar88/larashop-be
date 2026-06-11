<?php

use App\Http\Controllers\Api\AdminAccountController;
use App\Http\Controllers\Api\AdminCategoryController;
use App\Http\Controllers\Api\AdminCustomerController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminPaymentAccountController;
use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\AdminSettingController;
use App\Http\Controllers\Api\AdminShipmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ShippingController;
use App\Http\Controllers\Api\AdminQrisController;
use App\Http\Controllers\Api\FonnteWebhookController;
use App\Http\Controllers\Api\KomerceWebhookController;
use App\Http\Controllers\Api\QrisController;
use App\Http\Controllers\Api\QrislyWebhookController;
use App\Http\Controllers\Api\WablasWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook publik (dipanggil layanan luar). Tanpa auth & tanpa throttle ketat.
Route::post('v1/webhooks/wablas', [WablasWebhookController::class, 'handle'])->name('webhooks.wablas');
Route::match(['get', 'post'], 'v1/webhooks/fonnte/{secret?}', [FonnteWebhookController::class, 'handle'])->name('webhooks.fonnte');
Route::match(['post', 'put'], 'v1/webhooks/komerce/{token?}', [KomerceWebhookController::class, 'handle'])->name('webhooks.komerce');
Route::post('v1/webhooks/qrisly/{secret?}', [QrislyWebhookController::class, 'handle'])->name('webhooks.qrisly');

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
        Route::post('/register', [AuthController::class, 'register'])->middleware('guest');
    });

    Route::get('/products', [CatalogController::class, 'index']);
    Route::get('/products/{slug}', [CatalogController::class, 'show']);

    // Cek ongkir publik (tanpa login) — cari wilayah + hitung tarif.
    Route::get('/shipping/destinations', [ShippingController::class, 'destinations']);
    Route::get('/shipping/cost', [ShippingController::class, 'cost']);

    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
    });

    Route::middleware(['auth:sanctum', 'role:customer'])->group(function (): void {
        Route::get('/customer/profile', [CustomerController::class, 'profile']);
        Route::put('/customer/profile', [CustomerController::class, 'updateProfile']);
        Route::get('/customer/addresses', [CustomerController::class, 'addresses']);
        Route::get('/customer/destinations/search', [CustomerController::class, 'searchDestinations']);
        Route::post('/customer/addresses', [CustomerController::class, 'storeAddress']);
        Route::put('/customer/addresses/{address}', [CustomerController::class, 'updateAddress']);
        Route::delete('/customer/addresses/{address}', [CustomerController::class, 'destroyAddress']);
        Route::get('/customer/cart', [CartController::class, 'index']);
        Route::post('/customer/cart/items', [CartController::class, 'storeItem']);
        Route::put('/customer/cart/items/{item}', [CartController::class, 'updateItem']);
        Route::delete('/customer/cart/items/{item}', [CartController::class, 'destroyItem']);
        Route::get('/customer/orders', [OrderController::class, 'index']);
        Route::post('/customer/orders', [OrderController::class, 'store']);
        Route::get('/customer/orders/{code}', [OrderController::class, 'show']);
        Route::post('/customer/orders/{code}/cancel', [OrderController::class, 'cancel']);
        Route::post('/customer/orders/{code}/complete', [OrderController::class, 'complete']);
        Route::post('/customer/orders/{code}/qris', [QrisController::class, 'generate']);
        Route::get('/customer/orders/{code}/qris/status', [QrisController::class, 'status']);
        Route::get('/checkout', [CheckoutController::class, 'show']);
    });

    Route::prefix('admin')->middleware(['auth:sanctum', 'role:admin'])->group(function (): void {
        Route::get('/accounts', [AdminAccountController::class, 'index']);
        Route::post('/accounts', [AdminAccountController::class, 'store']);
        Route::get('/accounts/{account}', [AdminAccountController::class, 'show']);
        Route::put('/accounts/{account}', [AdminAccountController::class, 'update']);
        Route::delete('/accounts/{account}', [AdminAccountController::class, 'destroy']);
        Route::get('/products', [AdminProductController::class, 'index']);
        Route::post('/products', [AdminProductController::class, 'store']);
        Route::get('/products/{product}', [AdminProductController::class, 'show']);
        Route::put('/products/{product}', [AdminProductController::class, 'update']);
        Route::delete('/products/{product}', [AdminProductController::class, 'destroy']);
        Route::get('/customers', [AdminCustomerController::class, 'index']);
        Route::post('/customers', [AdminCustomerController::class, 'store']);
        Route::get('/customers/{customer}', [AdminCustomerController::class, 'show']);
        Route::put('/customers/{customer}', [AdminCustomerController::class, 'update']);
        Route::delete('/customers/{customer}', [AdminCustomerController::class, 'destroy']);
        Route::post('/customers/{customer}/addresses', [AdminCustomerController::class, 'storeAddress']);
        Route::put('/customers/{customer}/addresses/{address}', [AdminCustomerController::class, 'updateAddress']);
        Route::delete('/customers/{customer}/addresses/{address}', [AdminCustomerController::class, 'destroyAddress']);
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::post('/orders/schedule-pickup-bulk', [AdminOrderController::class, 'schedulePickupBulk']);
        Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
        Route::post('/orders/{order}/validate-payment', [AdminOrderController::class, 'validatePayment']);
        Route::post('/orders/{order}/cancel', [AdminOrderController::class, 'cancel']);
        Route::post('/orders/{order}/process-shipment', [AdminOrderController::class, 'processShipment']);
        Route::post('/orders/{order}/schedule-pickup', [AdminOrderController::class, 'schedulePickup']);
        Route::get('/orders/{order}/label', [AdminOrderController::class, 'printLabel']);
        Route::get('/orders/{order}/label-diy', [AdminOrderController::class, 'printLabelDiy']);
        Route::post('/orders/{order}/complete', [AdminOrderController::class, 'complete']);
        Route::get('/qrisly', [AdminQrisController::class, 'index']);
        Route::post('/qrisly/upload', [AdminQrisController::class, 'upload']);
        Route::post('/qrisly/{qris}/activate', [AdminQrisController::class, 'activate']);
        Route::delete('/qrisly/{qris}', [AdminQrisController::class, 'destroy']);
        Route::get('/shipments', [AdminShipmentController::class, 'index']);
        Route::get('/shipment-destinations/search', [AdminShipmentController::class, 'searchDestinations']);
        Route::get('/shipment-settings', [AdminShipmentController::class, 'settings']);
        Route::put('/shipment-settings', [AdminShipmentController::class, 'updateSettings']);
        Route::get('/shipments/{code}', [AdminShipmentController::class, 'show']);

        Route::get('/payment-accounts', [AdminPaymentAccountController::class, 'index']);
        Route::post('/payment-accounts', [AdminPaymentAccountController::class, 'store']);
        Route::put('/payment-accounts/{paymentAccount}', [AdminPaymentAccountController::class, 'update']);
        Route::delete('/payment-accounts/{paymentAccount}', [AdminPaymentAccountController::class, 'destroy']);

        Route::get('/categories', [AdminCategoryController::class, 'index']);
        Route::post('/categories', [AdminCategoryController::class, 'store']);
        Route::put('/categories/{category}', [AdminCategoryController::class, 'update']);
        Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy']);

        Route::get('/settings', [AdminSettingController::class, 'index']);
        Route::put('/settings', [AdminSettingController::class, 'update']);
    });
});
