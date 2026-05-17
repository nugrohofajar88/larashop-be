<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'address_id' => ['nullable', 'integer'],
            'use_unique_code_balance' => ['nullable', 'boolean'],
        ]);

        $addresses = $user->addresses()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        $selectedAddress = null;

        if (! empty($validated['address_id'])) {
            $selectedAddress = $addresses->firstWhere('id', (int) $validated['address_id']);
        }

        $selectedAddress ??= $addresses->firstWhere('is_primary', true) ?? $addresses->first();

        $shipmentOrigin = \App\Models\ShipmentOrigin::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
        $order = Order::query()
            ->with(['items.product', 'items.variant'])
            ->where('user_id', $user->id)
            ->where('status', 'draft')
            ->latest('id')
            ->first();

        $selectedItems = $order?->items?->where('is_selected', true)->values() ?? collect();
        $itemsTotal = (int) $selectedItems->sum('subtotal');
        $uniqueCode = $this->resolveUniqueCode($order);
        $shippingOptions = $this->resolveShippingOptions(
            $shipmentOrigin,
            $selectedAddress,
            $this->resolveWeightGrams($selectedItems),
            $user->id
        );
        $selectedShipping = collect($shippingOptions)->firstWhere('selected', true) ?? $shippingOptions[0] ?? null;
        $shippingTotal = (int) ($selectedShipping['price_value'] ?? 0);
        $availableUniqueCodeBalance = $this->usesUniqueCode() ? $user->uniqueCodeBalance() : 0;
        $useUniqueCodeBalance = $this->usesUniqueCode()
            && $availableUniqueCodeBalance > 0
            && filter_var($validated['use_unique_code_balance'] ?? false, FILTER_VALIDATE_BOOL);
        $payableBeforeBalance = $itemsTotal + $shippingTotal + $uniqueCode;
        $usedUniqueCode = $useUniqueCodeBalance
            ? min($availableUniqueCodeBalance, $payableBeforeBalance)
            : 0;
        $grandTotal = max(0, $payableBeforeBalance - $usedUniqueCode);

        if ($order !== null) {
            $order->update([
                'customer_address_id' => $selectedAddress?->id,
                'shipping_total' => $shippingTotal,
                'shipping_service_name' => $selectedShipping['service'] ?? null,
                'shipping_estimate_days' => $selectedShipping['estimate'] ?? null,
                'recipient_name' => $selectedAddress?->recipient_name,
                'recipient_phone' => $selectedAddress?->recipient_phone,
                'address_label' => $selectedAddress?->label,
                'address_snapshot' => $selectedAddress ? ApiData::addressSummary($selectedAddress) : null,
                'used_unique_code' => $usedUniqueCode,
                'grand_total' => $grandTotal,
            ]);
        }

        return response()->json([
            'data' => [
                'address' => $selectedAddress ? ApiData::address($selectedAddress) : null,
                'addresses' => $addresses
                    ->map(fn (CustomerAddress $address) => ApiData::address($address) + [
                        'selected' => $selectedAddress !== null && $address->id === $selectedAddress->id,
                    ])
                    ->values()
                    ->all(),
                'shipment_origin' => $shipmentOrigin ? ApiData::shipmentOrigin($shipmentOrigin) : null,
                'shipping_options' => $shippingOptions,
                'payment_summary' => [
                    'unique_code_enabled' => $this->usesUniqueCode(),
                    'items_total' => ApiData::rupiah($itemsTotal),
                    'items_total_value' => $itemsTotal,
                    'shipping_total' => ApiData::rupiah($shippingTotal),
                    'shipping_total_value' => $shippingTotal,
                    'unique_code' => ApiData::rupiah($uniqueCode),
                    'unique_code_value' => $uniqueCode,
                    'use_unique_code_balance' => $useUniqueCodeBalance,
                    'available_unique_code_balance' => ApiData::rupiah($availableUniqueCodeBalance),
                    'available_unique_code_balance_value' => $availableUniqueCodeBalance,
                    'used_unique_code' => ApiData::rupiah($usedUniqueCode),
                    'used_unique_code_value' => $usedUniqueCode,
                    'grand_total' => ApiData::rupiah($grandTotal),
                    'grand_total_value' => $grandTotal,
                ],
            ],
        ]);
    }

    private function resolveWeightGrams(Collection $items): int
    {
        if ($items->isEmpty()) {
            return 1000;
        }

        $weight = (int) $items->sum(function ($item): int {
            $itemWeight = (int) ($item->weight_grams ?? $item->variant?->weight_grams ?? $item->product?->weight_grams ?? 0);
            $quantity = max(1, (int) $item->quantity);

            return max(0, $itemWeight) * $quantity;
        });

        return max($weight, 1000);
    }

    private function resolveShippingOptions(?\App\Models\ShipmentOrigin $origin, ?CustomerAddress $address, int $weight, ?int $userId = null): array
    {
        if (
            $origin === null
            || $address === null
            || $origin->origin_id === null
            || $address->destination_id === null
            || blank($origin->selected_courier)
        ) {
            Log::warning('checkout.shipping_rates.skipped', [
                'user_id' => $userId,
                'origin_present' => $origin !== null,
                'address_present' => $address !== null,
                'origin_id' => $origin?->origin_id,
                'destination_id' => $address?->destination_id,
                'selected_courier' => $origin?->selected_courier,
                'weight' => $weight,
            ]);

            return [];
        }

        $requestPayload = [
            'origin' => $origin->origin_id,
            'destination' => $address->destination_id,
            'weight' => max($weight, 1000),
            'courier' => $origin->selected_courier,
        ];

        Log::info('checkout.shipping_rates.request', [
            'user_id' => $userId,
            'payload' => $requestPayload,
        ]);

        $response = Http::acceptJson()
            ->baseUrl(rtrim((string) config('services.rajaongkir.base_url'), '/').'/')
            ->withHeaders([
                'key' => (string) config('services.rajaongkir.api_key'),
            ])
            ->asForm()
            ->post('calculate/domestic-cost', $requestPayload);

        Log::info('checkout.shipping_rates.response', [
            'user_id' => $userId,
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body' => $response->json(),
        ]);

        if (! $response->successful()) {
            return [];
        }

        $options = collect($response->json('data', []))
            ->map(function (array $item): array {
                $serviceCode = trim((string) ($item['service'] ?? ''));
                $description = trim((string) ($item['description'] ?? ''));
                $courierName = trim((string) ($item['name'] ?? ''));
                $priceValue = (int) ($item['cost'] ?? 0);

                return [
                    'id' => Str::slug(((string) ($item['code'] ?? 'courier')).'-'.$serviceCode),
                    'code' => strtolower((string) ($item['code'] ?? '')),
                    'service_code' => $serviceCode,
                    'description' => $description,
                    'service' => $description !== ''
                        ? $courierName.' - '.$description
                        : trim($courierName.' '.$serviceCode),
                    'estimate' => trim((string) ($item['etd'] ?? '')) ?: 'belum tersedia',
                    'price' => ApiData::rupiah($priceValue),
                    'price_value' => $priceValue,
                    'selected' => false,
                ];
            })
            ->filter(fn (array $option) => $option['price_value'] > 0)
            ->values();

        if ($options->isEmpty()) {
            Log::warning('checkout.shipping_rates.empty_options', [
                'user_id' => $userId,
                'payload' => $requestPayload,
                'body' => $response->json(),
            ]);

            return [];
        }

        $selectedIndex = $options->search(fn (array $option): bool => $this->isRegularService($option));
        $selectedIndex = $selectedIndex === false ? 0 : $selectedIndex;

        return $options
            ->map(fn (array $option, int $index) => $option + ['selected' => $index === $selectedIndex])
            ->values()
            ->all();
    }

    private function isRegularService(array $option): bool
    {
        $haystack = mb_strtolower(trim(($option['description'] ?? '').' '.($option['service_code'] ?? '')));

        return str_contains($haystack, 'reguler')
            || str_contains($haystack, 'regular')
            || preg_match('/\breg\b/', $haystack) === 1
            || preg_match('/\bez\b/', $haystack) === 1;
    }

    private function resolveUniqueCode(?Order $order): int
    {
        if ($order === null) {
            return 0;
        }

        if (! $this->usesUniqueCode()) {
            if ((int) $order->unique_code !== 0) {
                $order->update([
                    'unique_code' => 0,
                    'grand_total' => max(0, (int) $order->items_total + (int) $order->shipping_total - (int) $order->used_unique_code),
                ]);
            }

            return 0;
        }

        if ((int) $order->unique_code > 0) {
            return (int) $order->unique_code;
        }

        $uniqueCode = random_int(101, 999);

        $order->update([
            'unique_code' => $uniqueCode,
            'grand_total' => max(0, (int) $order->items_total + (int) $order->shipping_total + $uniqueCode - (int) $order->used_unique_code),
        ]);

        return $uniqueCode;
    }

    private function usesUniqueCode(): bool
    {
        return (bool) config('services.checkout.use_unique_code', true);
    }
}
