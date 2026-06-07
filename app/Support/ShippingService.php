<?php

namespace App\Support;

use App\Models\ShipmentOrigin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Layanan ongkir (RajaOngkir) yang dipakai bersama: cari wilayah tujuan +
 * hitung tarif. Sumber kebenaran tunggal untuk web, WA, dan alur order.
 */
class ShippingService
{
    public const DEFAULT_WEIGHT = 1000; // gram (1 kg)

    /**
     * Cari wilayah tujuan (RajaOngkir domestic-destination).
     *
     * @return array<int, array{id:mixed,label:string,province_name:string,city_name:string,district_name:string,subdistrict_name:string,zip_code:string}>
     */
    public function searchDestinations(string $search, int $limit = 5): array
    {
        $response = Http::acceptJson()
            ->baseUrl($this->base())
            ->withHeaders(['key' => (string) config('services.rajaongkir.api_key')])
            ->get('destination/domestic-destination', [
                'search' => $search,
                'limit' => $limit,
                'offset' => 0,
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
            ->filter(fn (array $i) => $i['id'] !== null)
            ->values()
            ->all();
    }

    /**
     * Hitung opsi ongkir dari gudang aktif ke destination (RajaOngkir domestic-cost).
     *
     * @return array<int, array{id:string,code:string,service_code:string,service:string,estimate:string,price:string,price_value:int}>
     */
    public function costOptions(int|string $destinationId, int $weightGrams = self::DEFAULT_WEIGHT): array
    {
        $origin = $this->origin();

        if ($origin === null || $origin->origin_id === null || blank($origin->selected_courier)) {
            return [];
        }

        $response = Http::acceptJson()
            ->baseUrl($this->base())
            ->withHeaders(['key' => (string) config('services.rajaongkir.api_key')])
            ->asForm()
            ->post('calculate/domestic-cost', [
                'origin' => $origin->origin_id,
                'destination' => $destinationId,
                'weight' => max($weightGrams, self::DEFAULT_WEIGHT),
                'courier' => $origin->selected_courier,
            ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('data', []))
            ->map(function (array $item): array {
                $serviceCode = trim((string) ($item['service'] ?? ''));
                $description = trim((string) ($item['description'] ?? ''));
                $courierName = trim((string) ($item['name'] ?? ''));
                $priceValue = (int) ($item['cost'] ?? 0);

                return [
                    'id' => Str::slug(((string) ($item['code'] ?? 'courier')).'-'.$serviceCode),
                    'code' => strtolower((string) ($item['code'] ?? '')),
                    'service_code' => $serviceCode,
                    'service' => $description !== '' ? $courierName.' - '.$description : trim($courierName.' '.$serviceCode),
                    'estimate' => trim((string) ($item['etd'] ?? '')) ?: 'belum tersedia',
                    'price' => 'Rp'.number_format($priceValue, 0, ',', '.'),
                    'price_value' => $priceValue,
                ];
            })
            ->filter(fn (array $o) => $o['price_value'] > 0)
            ->values()
            ->all();
    }

    public function origin(): ?ShipmentOrigin
    {
        return ShipmentOrigin::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    protected function base(): string
    {
        return rtrim((string) config('services.rajaongkir.base_url'), '/').'/';
    }
}
