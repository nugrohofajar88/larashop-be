<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Support\ApiData;
use App\Support\ShippingService;
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
            $user->id,
            $itemsTotal
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
                'items' => $selectedItems->map(fn ($item): array => [
                    'name' => $item->product_name,
                    'variant_label' => $item->variant_label,
                    'quantity' => (int) $item->quantity,
                    'price' => ApiData::rupiah((int) $item->price),
                    'subtotal' => ApiData::rupiah((int) $item->subtotal),
                ])->values()->all(),
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

        $lines = $items->map(fn ($item): array => [
            'weight_grams' => (int) ($item->weight_grams ?? $item->variant?->weight_grams ?? $item->product?->weight_grams ?? 0),
            'length_cm' => (float) ($item->variant?->length_cm ?? $item->product?->length_cm ?? 0),
            'width_cm' => (float) ($item->variant?->width_cm ?? $item->product?->width_cm ?? 0),
            'height_cm' => (float) ($item->variant?->height_cm ?? $item->product?->height_cm ?? 0),
            'qty' => max(1, (int) $item->quantity),
        ])->all();

        return \App\Support\ShippingWeight::chargeableGrams($lines);
    }

    private function resolveShippingOptions(?\App\Models\ShipmentOrigin $origin, ?CustomerAddress $address, int $weight, ?int $userId = null, int $itemValue = 0): array
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

        $options = app(ShippingService::class)->costOptions($address->destination_id, $weight, $itemValue);

        if ($options === []) {
            return [];
        }

        // FE checkout butuh field 'description' & 'selected'; tandai termurah (index 0) sbg default.
        return collect($options)
            ->map(fn (array $option, int $index): array => $option + ['description' => '', 'selected' => $index === 0])
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
        return \App\Models\Setting::uniqueCodeEnabled();
    }
}
