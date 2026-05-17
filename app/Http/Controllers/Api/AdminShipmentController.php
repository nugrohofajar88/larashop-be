<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ShipmentOrigin;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AdminShipmentController extends Controller
{
    public function index(): JsonResponse
    {
        $shipments = Order::query()
            ->with('user')
            ->whereIn('status', ['paid', 'processing', 'shipped'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $shipments->map(fn (Order $order) => ApiData::shipment($order))->values()->all(),
        ]);
    }

    public function searchDestinations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['required', 'string', 'min:3', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $response = Http::acceptJson()
            ->baseUrl(rtrim((string) config('services.rajaongkir.base_url'), '/').'/')
            ->withHeaders([
                'key' => (string) config('services.rajaongkir.api_key'),
            ])
            ->get('destination/domestic-destination', [
                'search' => $validated['search'],
                'limit' => $validated['limit'] ?? 5,
                'offset' => $validated['offset'] ?? 0,
            ]);

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Gagal mengambil data destinasi.',
            ], 502);
        }

        $items = collect($response->json('data', []))
            ->map(fn (array $item) => [
                'id' => $item['id'] ?? null,
                'label' => $item['label'] ?? '',
                'province_name' => $item['province_name'] ?? '',
                'city_name' => $item['city_name'] ?? '',
                'district_name' => $item['district_name'] ?? '',
                'subdistrict_name' => $item['subdistrict_name'] ?? '',
                'zip_code' => $item['zip_code'] ?? '',
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $items,
        ]);
    }

    public function settings(): JsonResponse
    {
        $origin = ShipmentOrigin::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        $data = $origin ? ApiData::shipmentOrigin($origin) : [];
        $data['available_couriers'] = config('services.rajaongkir.available_couriers', []);

        return response()->json([
            'data' => $data,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $availableCouriers = config('services.rajaongkir.available_couriers', []);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'origin_id' => ['nullable', 'integer'],
            'selected_courier' => ['required', 'string'],
            'province' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'subdistrict' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'max:10'],
            'address_line' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $selectedCouriers = collect(explode(':', (string) ($validated['selected_courier'] ?? '')))
            ->map(fn (string $courier) => trim($courier))
            ->filter()
            ->unique()
            ->values();

        if ($selectedCouriers->isEmpty() || $selectedCouriers->diff($availableCouriers)->isNotEmpty()) {
            return response()->json([
                'message' => 'Courier yang dipilih tidak valid.',
                'errors' => [
                    'selected_courier' => ['Courier yang dipilih tidak valid.'],
                ],
            ], 422);
        }

        $validated['selected_courier'] = $selectedCouriers->implode(':');

        $origin = ShipmentOrigin::query()
            ->where('is_default', true)
            ->orderBy('id')
            ->first();

        if ($origin === null) {
            $origin = new ShipmentOrigin();
        }

        $origin->fill($validated + [
            'is_default' => true,
            'is_active' => true,
        ]);
        $origin->save();

        ShipmentOrigin::query()
            ->where('id', '!=', $origin->id)
            ->update(['is_default' => false]);

        $data = ApiData::shipmentOrigin($origin->fresh());
        $data['available_couriers'] = $availableCouriers;

        return response()->json([
            'data' => $data,
        ]);
    }

    public function show(string $code): JsonResponse
    {
        $orderCode = str_starts_with($code, 'SHP-ORD-') ? substr($code, 4) : $code;
        $order = Order::query()
            ->with('user')
            ->where('code', $orderCode)
            ->first();

        abort_if($order === null, 404);

        return response()->json([
            'data' => ApiData::shipment($order),
        ]);
    }
}
