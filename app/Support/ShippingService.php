<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\ShipmentOrigin;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Layanan ongkir via Komerce Collaborator (paket enterprise):
 *   - cari wilayah: GET /tariff/api/v1/destination/search
 *   - hitung tarif: GET /tariff/api/v1/calculate  (cost + cashback + service_fee)
 * Sumber kebenaran tunggal untuk web/WA. Kode kurir/layanan di sini SAMA dengan
 * yang dipakai untuk booking (store order), jadi order pasti bisa di-booking.
 */
class ShippingService
{
    public const DEFAULT_WEIGHT = 1000; // gram (1 kg)

    /**
     * @return array<int, array{id:mixed,label:string,province_name:string,city_name:string,district_name:string,subdistrict_name:string,zip_code:string}>
     */
    public function searchDestinations(string $search, int $limit = 5): array
    {
        $response = $this->client()->get('/tariff/api/v1/destination/search', [
            'keyword' => $search,
            'limit' => $limit,
        ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('data', []))
            ->map(fn (array $i): array => [
                'id' => $i['id'] ?? null,
                'label' => $i['label'] ?? '',
                'province_name' => $i['province_name'] ?? '',
                'city_name' => $i['city_name'] ?? '',
                'district_name' => $i['district_name'] ?? '',
                'subdistrict_name' => $i['subdistrict_name'] ?? '',
                'zip_code' => $i['zip_code'] ?? '',
            ])
            ->filter(fn (array $i): bool => $i['id'] !== null)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Hitung opsi ongkir dari gudang aktif ke destination.
     * Kode `code`/`service_code` = shipping_name/service_name Komerce (siap booking).
     *
     * @return array<int, array{id:string,code:string,service_code:string,service:string,estimate:string,price:string,price_value:int,cashback_value:int,service_fee_value:int}>
     */
    public function costOptions(int|string $destinationId, int $weightGrams = self::DEFAULT_WEIGHT, int $itemValue = 0): array
    {
        $origin = $this->origin();

        if ($origin === null || empty($origin->origin_id)) {
            return [];
        }

        $response = $this->client()->get('/tariff/api/v1/calculate', array_filter([
            'shipper_destination_id' => $origin->origin_id,
            'receiver_destination_id' => $destinationId,
            'weight' => round(max($weightGrams, 1) / 1000, 2), // KG (float) — API minta kilogram, bukan gram
            'item_value' => max($itemValue, 0),
            'cod' => 'no',
            'origin_pin_point' => $origin->pin_point ?: null,
        ], fn ($v): bool => $v !== null));

        if (! $response->successful()) {
            return [];
        }

        $options = [];

        // Hanya layanan REGULER (standar parsel). Cargo/instant dilewati —
        // cargo untuk barang besar, instant intra-kota (belum tentu bisa store order).
        foreach (['calculate_reguler'] as $group) {
            foreach ((array) $response->json('data.'.$group, []) as $svc) {
                $priceValue = (int) ($svc['shipping_cost'] ?? 0);

                if ($priceValue <= 0) {
                    continue;
                }

                $shippingName = trim((string) ($svc['shipping_name'] ?? ''));
                $serviceName = trim((string) ($svc['service_name'] ?? ''));
                $etd = trim((string) ($svc['etd'] ?? ''));

                $options[] = [
                    'id' => Str::slug($shippingName.'-'.$serviceName),
                    'code' => $shippingName,
                    'service_code' => $serviceName,
                    'service' => trim($shippingName.' - '.$serviceName),
                    'estimate' => $etd === '-' ? '' : $etd,
                    'price' => 'Rp'.number_format($priceValue, 0, ',', '.'),
                    'price_value' => $priceValue,
                    'cashback_value' => (int) ($svc['shipping_cashback'] ?? 0),
                    'service_fee_value' => (int) ($svc['service_fee'] ?? 0),
                ];
            }
        }

        // Hormati allowlist kurir dari admin (shipment_origins.selected_courier).
        // Mis. "jnt" -> hanya J&T; "jnt:jne:sicepat" -> tiga kurir. Kosong -> semua.
        $allowed = collect(explode(':', (string) ($origin->selected_courier ?? '')))
            ->map(fn (string $c): string => strtoupper(trim($c)))
            ->filter()
            ->all();

        if ($allowed !== []) {
            $options = array_values(array_filter(
                $options,
                fn (array $o): bool => in_array(strtoupper((string) $o['code']), $allowed, true),
            ));
        }

        usort($options, fn (array $a, array $b): int => $a['price_value'] <=> $b['price_value']);

        return $options;
    }

    public function origin(): ?ShipmentOrigin
    {
        return ShipmentOrigin::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * Lacak resi (manifest detail per-lokasi + POD) via RajaOngkir.
     * POST {rajaongkir}/track/waybill?awb=&courier=&last_phone_number=
     *
     * CATATAN: ini API RajaOngkir (header `key` = RAJAONGKIR_API_KEY), BUKAN
     * Collaborator delivery (x-api-key). Dua key BERBEDA. Param last_phone_number
     * (5 digit terakhir HP penerima) diminta sebagian kurir (mis. JNE).
     *
     * @return array{ok:bool,message:string,code:int,data:mixed}
     */
    public function trackWaybill(string $awb, string $courier, ?string $lastPhone = null): array
    {
        $awb = trim($awb);
        $courier = strtolower(trim($courier));

        if ($awb === '' || $courier === '') {
            return ['ok' => false, 'message' => 'AWB atau kurir kosong.', 'code' => 0, 'data' => null];
        }

        $query = array_filter([
            'awb' => $awb,
            'courier' => $courier,
            'last_phone_number' => $lastPhone ? preg_replace('/\D/', '', $lastPhone) : null,
        ], fn ($v): bool => $v !== null && $v !== '');

        try {
            $response = Http::acceptJson()
                ->withHeaders(['key' => (string) config('services.rajaongkir.api_key')])
                ->baseUrl(rtrim((string) config('services.rajaongkir.base_url'), '/'))
                ->post('/track/waybill?'.http_build_query($query));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'code' => 0, 'data' => null];
        }

        $body = $response->json() ?? [];
        $ok = $response->successful() && ($body['meta']['status'] ?? '') === 'success';

        return [
            'ok' => $ok,
            'message' => (string) ($body['meta']['message'] ?? ($ok ? 'OK' : 'Gagal melacak resi.')),
            'code' => (int) ($body['meta']['code'] ?? $response->status()),
            'data' => $body['data'] ?? null,
        ];
    }

    /**
     * Petakan nama layanan kirim (mis. "JNT - EZ", "IDEXPRESS - IDFLAT") ke kode
     * kurir RajaOngkir (jnt, ide, dst). Diperlukan karena nama di Collaborator
     * (IDEXPRESS) beda dgn kode tracking RajaOngkir (ide).
     */
    public static function courierCode(?string $shippingName): string
    {
        $name = strtolower(trim((string) $shippingName));
        $name = trim(explode(' - ', $name)[0]); // ambil bagian sebelum " - "

        $map = [
            'idexpress' => 'ide', 'id express' => 'ide', 'ide' => 'ide',
            'j&t' => 'jnt', 'jnt' => 'jnt', 'j&t express' => 'jnt',
            'jne' => 'jne',
            'sicepat' => 'sicepat',
            'sap' => 'sap', 'sap express' => 'sap',
            'ninja' => 'ninja', 'ninja xpress' => 'ninja',
            'lion' => 'lion', 'lion parcel' => 'lion',
            'tiki' => 'tiki', 'anteraja' => 'anteraja',
            'pos' => 'pos', 'pos indonesia' => 'pos',
            'wahana' => 'wahana', 'first' => 'first',
        ];

        return $map[$name] ?? $name;
    }

    protected function client(): PendingRequest
    {
        return Http::acceptJson()
            ->baseUrl(rtrim((string) config('services.komerce_delivery.base_url'), '/'))
            ->withHeaders(['x-api-key' => (string) config('services.komerce_delivery.api_key')]);
    }
}
