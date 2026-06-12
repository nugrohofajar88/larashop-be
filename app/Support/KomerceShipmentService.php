<?php

namespace App\Support;

use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Setting;
use App\Models\ShipmentOrigin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Booking ekspedisi (store order) via Komerce Collaborator (paket enterprise).
 *   - Tarif: GET {base}/tariff/api/v1/calculate  (untuk cost + cashback yg harus cocok)
 *   - Order: POST {base}/order/api/v1/orders/store
 * Auth: header x-api-key.
 */
class KomerceShipmentService
{
    public function enabled(): bool
    {
        return (bool) config('services.komerce_delivery.enabled')
            && trim((string) config('services.komerce_delivery.api_key')) !== '';
    }

    /**
     * @return array{ok:bool,order_no?:string,order_id?:int,message?:string,payload?:array,response?:array}
     */
    public function createOrder(Order $order): array
    {
        $origin = ShipmentOrigin::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($origin === null) {
            return ['ok' => false, 'message' => 'Gudang asal (shipment origin) belum diatur.'];
        }

        $address = CustomerAddress::query()->find($order->customer_address_id);

        if ($address === null || empty($address->destination_id)) {
            return ['ok' => false, 'message' => 'Alamat penerima belum punya destination_id.'];
        }

        if (trim((string) $order->shipping_courier_code) === '' || trim((string) $order->shipping_service_code) === '') {
            return ['ok' => false, 'message' => 'Kode kurir/layanan order belum tersimpan (order lama sebelum fitur ini).'];
        }

        $order->loadMissing('items.variant', 'user');
        // Berat dalam KG (API minta kilogram). Sama dgn total berat order_details (gram/1000)
        // supaya cost yang dihitung store order SAMA dengan tarif.
        $weightGrams = (int) $order->items->sum(fn ($i): int => (int) ($i->weight_grams ?: 0) * (int) $i->quantity);
        $weightKg = round(max($weightGrams, 1) / 1000, 2);

        $tariff = $this->tariff($order, (int) $origin->origin_id, (int) $address->destination_id, $weightKg, (int) $order->items_total, (string) $origin->pin_point);

        if ($tariff === null) {
            return ['ok' => false, 'message' => 'Tarif untuk '.strtoupper((string) $order->shipping_courier_code).'/'.$order->shipping_service_code.' tidak ditemukan di Komerce (cek kode kurir/layanan).'];
        }

        $payload = $this->buildPayload($order, $origin, $address, $tariff);

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-api-key' => (string) config('services.komerce_delivery.api_key')])
                ->baseUrl(rtrim((string) config('services.komerce_delivery.base_url'), '/'))
                ->post('/order/api/v1/orders/store', $payload);
        } catch (\Throwable $e) {
            Log::error('komerce.store_order.exception', ['order' => $order->code, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage(), 'payload' => $payload];
        }

        $body = $response->json() ?? [];
        Log::info('komerce.store_order.response', ['order' => $order->code, 'status' => $response->status(), 'body' => $body]);

        if (! $response->successful() || ($body['meta']['status'] ?? '') !== 'success') {
            return [
                'ok' => false,
                'message' => (string) ($body['meta']['message'] ?? 'Gagal membuat order ekspedisi.'),
                'payload' => $payload,
                'response' => $body,
            ];
        }

        return [
            'ok' => true,
            'order_no' => (string) ($body['data']['order_no'] ?? ''),
            'order_id' => (int) ($body['data']['order_id'] ?? 0),
            'response' => $body,
        ];
    }

    /**
     * Jadwalkan pickup (kurir jemput) untuk order yang sudah di-booking.
     *
     * @return array{ok:bool,message?:string,data?:mixed,response?:array}
     */
    public function requestPickup(string $orderNo, string $date, string $time, string $vehicle): array
    {
        if (trim($orderNo) === '') {
            return ['ok' => false, 'message' => 'Order belum punya order_no Komerce (belum di-booking).'];
        }

        $payload = [
            'pickup_date' => $date,        // YYYY-MM-DD
            'pickup_time' => $time,        // HH:MM
            'pickup_vehicle' => $vehicle,  // Motor / Mobil / Truk
            'orders' => [['order_no' => $orderNo]],
        ];

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-api-key' => (string) config('services.komerce_delivery.api_key')])
                ->baseUrl(rtrim((string) config('services.komerce_delivery.base_url'), '/'))
                ->post('/order/api/v1/pickup/request', $payload);
        } catch (\Throwable $e) {
            Log::error('komerce.pickup.exception', ['order_no' => $orderNo, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage(), 'response' => ['payload' => $payload]];
        }

        $body = $response->json() ?? [];
        Log::info('komerce.pickup.response', ['order_no' => $orderNo, 'status' => $response->status(), 'body' => $body]);

        if (! $response->successful() || ($body['meta']['status'] ?? '') !== 'success') {
            return [
                'ok' => false,
                'message' => (string) ($body['meta']['message'] ?? 'Gagal menjadwalkan pickup.'),
                'response' => $body,
            ];
        }

        // Hasil per-order ada di data[]: {status: success|failed, order_no, awb}.
        $first = (array) ($body['data'][0] ?? []);
        $itemStatus = (string) ($first['status'] ?? '');
        $awb = (string) ($first['awb'] ?? '');

        if ($itemStatus !== 'success') {
            return [
                'ok' => false,
                'message' => 'Pickup belum berhasil (status: '.($itemStatus ?: 'unknown').'). Di sandbox sering "failed"; di produksi seharusnya sukses + AWB.',
                'awb' => $awb,
                'response' => $body,
            ];
        }

        return ['ok' => true, 'awb' => $awb, 'data' => $body['data'], 'response' => $body];
    }

    /**
     * Pickup BANYAK order sekaligus (Komerce mendukung field `orders` array).
     * Mengembalikan hasil per order_no: ['ok'=>bool, 'results'=>[order_no => ['status','awb']], 'message'].
     *
     * @param  array<int,string>  $orderNos
     * @return array{ok:bool, results?:array<string,array{status:string,awb:string}>, message?:string}
     */
    public function requestPickupBulk(array $orderNos, string $date, string $time, string $vehicle): array
    {
        $orderNos = array_values(array_unique(array_filter(array_map('trim', $orderNos))));
        if ($orderNos === []) {
            return ['ok' => false, 'message' => 'Tidak ada order_no untuk dijemput.'];
        }

        $payload = [
            'pickup_date' => $date,
            'pickup_time' => $time,
            'pickup_vehicle' => $vehicle,
            'orders' => array_map(fn (string $no): array => ['order_no' => $no], $orderNos),
        ];

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-api-key' => (string) config('services.komerce_delivery.api_key')])
                ->baseUrl(rtrim((string) config('services.komerce_delivery.base_url'), '/'))
                ->post('/order/api/v1/pickup/request', $payload);
        } catch (\Throwable $e) {
            Log::error('komerce.pickup.bulk.exception', ['count' => count($orderNos), 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $body = $response->json() ?? [];
        Log::info('komerce.pickup.bulk.response', ['count' => count($orderNos), 'status' => $response->status(), 'body' => $body]);

        if (! $response->successful() || ($body['meta']['status'] ?? '') !== 'success') {
            return ['ok' => false, 'message' => (string) ($body['meta']['message'] ?? 'Gagal menjadwalkan pickup.'), 'response' => $body];
        }

        // data[] berisi per-order: {status, order_no, awb}. Petakan by order_no.
        $results = [];
        foreach ((array) ($body['data'] ?? []) as $item) {
            $no = (string) (((array) $item)['order_no'] ?? '');
            if ($no !== '') {
                $results[$no] = [
                    'status' => (string) (((array) $item)['status'] ?? ''),
                    'awb' => (string) (((array) $item)['awb'] ?? ''),
                ];
            }
        }

        return ['ok' => true, 'results' => $results, 'response' => $body];
    }

    /**
     * Ambil label/resi siap-print dari Komerce (paket enterprise: Print Label).
     * POST /order/api/v1/orders/print-label?page=...&order_no=...
     * Respons JSON: data.base_64 = PDF (base64), data.path = nama file. Return PDF mentah.
     *
     * @return array{ok:bool, pdf?:string, filename?:string, message?:string}
     */
    // page_6 = 100x150mm (label thermal standar). page_5=100x100, page_1/2/4=A4.
    public function printLabel(string $orderNo, string $page = 'page_6'): array
    {
        if (trim($orderNo) === '') {
            return ['ok' => false, 'message' => 'Order belum punya order_no Komerce (belum di-booking).'];
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-api-key' => (string) config('services.komerce_delivery.api_key')])
                ->baseUrl(rtrim((string) config('services.komerce_delivery.base_url'), '/'))
                ->connectTimeout(10)
                ->timeout(20)
                ->post('/order/api/v1/orders/print-label?page='.urlencode($page).'&order_no='.urlencode($orderNo));
        } catch (\Throwable $e) {
            Log::error('komerce.print_label.exception', ['order_no' => $orderNo, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['meta']['status'] ?? '') !== 'success') {
            Log::warning('komerce.print_label.failed', ['order_no' => $orderNo, 'status' => $response->status(), 'meta' => $body['meta'] ?? null]);

            return ['ok' => false, 'message' => (string) ($body['meta']['message'] ?? 'Gagal generate label.')];
        }

        $pdf = base64_decode((string) ($body['data']['base_64'] ?? ''), true);

        if ($pdf === false || $pdf === '' || ! str_starts_with($pdf, '%PDF')) {
            return ['ok' => false, 'message' => 'Respons label tidak valid (bukan PDF).'];
        }

        $path = (string) ($body['data']['path'] ?? '');

        return [
            'ok' => true,
            'pdf' => $pdf,
            'filename' => $path !== '' ? basename($path) : 'label-'.$orderNo.'.pdf',
        ];
    }

    /**
     * Cari tarif yang cocok dengan kurir + layanan order.
     *
     * @return array{shipping_name:string,service_name:string,shipping_cost:int,shipping_cashback:int,service_fee:int}|null
     */
    protected function tariff(Order $order, int $shipperDestination, int $receiverDestination, float $weightKg, int $itemValue, ?string $pinPoint = null): ?array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-api-key' => (string) config('services.komerce_delivery.api_key')])
                ->baseUrl(rtrim((string) config('services.komerce_delivery.base_url'), '/'))
                ->get('/tariff/api/v1/calculate', array_filter([
                    'shipper_destination_id' => $shipperDestination,
                    'receiver_destination_id' => $receiverDestination,
                    'weight' => $weightKg,
                    'item_value' => $itemValue,
                    'cod' => 'no',
                    'origin_pin_point' => $pinPoint ?: null,
                ], fn ($v): bool => $v !== null));
        } catch (\Throwable $e) {
            Log::error('komerce.tariff.exception', ['order' => $order->code, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $courier = strtoupper(trim((string) $order->shipping_courier_code));
        $service = strtoupper(trim((string) $order->shipping_service_code));

        foreach (['calculate_reguler', 'calculate_cargo', 'calculate_instant'] as $group) {
            foreach ((array) $response->json('data.'.$group, []) as $svc) {
                if (strtoupper(trim((string) ($svc['shipping_name'] ?? ''))) === $courier
                    && strtoupper(trim((string) ($svc['service_name'] ?? ''))) === $service) {
                    return [
                        'shipping_name' => (string) $svc['shipping_name'],
                        'service_name' => (string) $svc['service_name'],
                        'shipping_cost' => (int) ($svc['shipping_cost'] ?? 0),
                        'shipping_cashback' => (int) ($svc['shipping_cashback'] ?? 0),
                        'service_fee' => (int) ($svc['service_fee'] ?? 0),
                    ];
                }
            }
        }

        return null;
    }

    protected function buildPayload(Order $order, ShipmentOrigin $origin, CustomerAddress $address, array $tariff): array
    {
        $details = $order->items->map(function ($item): array {
            $variant = $item->variant;

            return [
                'product_name' => (string) $item->product_name,
                'product_variant_name' => (string) ($item->variant_label ?? ''),
                'product_price' => (int) $item->price,
                'product_weight' => (int) ($item->weight_grams ?: 1000), // gram (sesuai contoh store order)
                'product_width' => max(1, (int) round((float) ($variant->width_cm ?? 1))),
                'product_height' => max(1, (int) round((float) ($variant->height_cm ?? 1))),
                'product_length' => max(1, (int) round((float) ($variant->length_cm ?? 1))),
                'qty' => (int) $item->quantity,
                'subtotal' => (int) $item->subtotal,
            ];
        })->values()->all();

        $shippingCost = (int) $tariff['shipping_cost'];
        // payment_method = BANK TRANSFER -> service_fee = 0 (service_fee 2.8% hanya utk COD).
        $serviceFee = 0;
        $additionalCost = 0;
        // grand_total = total produk + ongkir + biaya tambahan (sesuai dokumentasi).
        $grandTotal = (int) $order->items_total + $shippingCost + $additionalCost;

        return [
            // Komerce wajib WIB. Dipaksa Asia/Jakarta sbg pengaman: walau APP_TIMEZONE
            // di host lupa di-set (balik UTC), order_date tetap benar, jadi tak kena
            // tolak "date order can't before today" saat booking lewat tengah malam.
            'order_date' => now()->setTimezone('Asia/Jakarta')->format('Y-m-d'),
            'brand_name' => (string) Setting::get('store_brand', 'Akar Tani Kimia'),
            'shipper_name' => (string) $origin->contact_name,
            'shipper_phone' => (string) $origin->contact_phone,
            'shipper_destination_id' => (int) $origin->origin_id,
            'shipper_address' => (string) $origin->address_line,
            'shipper_email' => (string) Setting::get('store_email', 'admin@akartanikimia.id'),
            'origin_pin_point' => (string) $origin->pin_point,
            'receiver_name' => (string) $order->recipient_name,
            'receiver_phone' => (string) $order->recipient_phone,
            'receiver_destination_id' => (int) $address->destination_id,
            'receiver_address' => (string) $address->address_line,
            'receiver_email' => (string) ($order->user->email ?? 'customer@akartanikimia.id'),
            'shipping' => (string) $tariff['shipping_name'],
            'shipping_type' => (string) $tariff['service_name'],
            'payment_method' => 'BANK TRANSFER',
            'shipping_cost' => $shippingCost,
            'shipping_cashback' => (int) $tariff['shipping_cashback'],
            'service_fee' => $serviceFee,
            'additional_cost' => $additionalCost,
            'grand_total' => $grandTotal,
            'cod_value' => 0,
            'insurance_value' => 0,
            'order_details' => $details,
        ];
    }
}
