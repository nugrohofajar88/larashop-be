<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShipmentOrigin;
use App\Models\UserUniqueCode;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with(['items', 'user'])
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', 'draft')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $orders->map(fn (Order $order) => ApiData::order($order))->values()->all(),
        ]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $order = Order::query()
            ->with(['items', 'user'])
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', 'draft')
            ->where('code', $code)
            ->first();

        abort_if($order === null, 404);

        return response()->json([
            'data' => ApiData::order($order),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => ['required', 'integer'],
            'shipping_option_id' => ['required', 'string', 'max:100'],
            'use_unique_code_balance' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $address = $user->addresses()->findOrFail($validated['address_id']);
        $draftOrder = Order::query()
            ->with(['items.product', 'items.variant'])
            ->where('user_id', $user->id)
            ->where('status', 'draft')
            ->latest('id')
            ->first();

        if ($draftOrder === null) {
            throw ValidationException::withMessages([
                'cart' => 'Belum ada draft keranjang untuk diproses.',
            ]);
        }

        $selectedItems = $draftOrder->items->where('is_selected', true)->values();

        if ($selectedItems->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => 'Pilih minimal satu produk di keranjang sebelum checkout.',
            ]);
        }

        $shipmentOrigin = ShipmentOrigin::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        $shippingOptions = $this->resolveShippingOptions(
            $shipmentOrigin,
            $address,
            $this->resolveWeightGrams($selectedItems),
            $user->id
        );

        $selectedShipping = collect($shippingOptions)->firstWhere('id', $validated['shipping_option_id']);

        if ($selectedShipping === null) {
            throw ValidationException::withMessages([
                'shipping_option_id' => 'Layanan pengiriman tidak valid atau sudah tidak tersedia.',
            ]);
        }

        $itemsTotal = (int) $selectedItems->sum('subtotal');
        $shippingTotal = (int) ($selectedShipping['price_value'] ?? 0);
        $uniqueCode = $this->resolveUniqueCode($draftOrder);
        $availableUniqueCodeBalance = $this->usesUniqueCode() ? $user->uniqueCodeBalance() : 0;
        $useUniqueCodeBalance = $this->usesUniqueCode()
            && $availableUniqueCodeBalance > 0
            && $request->boolean('use_unique_code_balance');
        $usedUniqueCode = $useUniqueCodeBalance
            ? min($availableUniqueCodeBalance, $itemsTotal + $shippingTotal + $uniqueCode)
            : 0;
        $grandTotal = max(0, $itemsTotal + $shippingTotal + $uniqueCode - $usedUniqueCode);

        $order = DB::transaction(function () use (
            $user,
            $address,
            $draftOrder,
            $selectedItems,
            $selectedShipping,
            $itemsTotal,
            $shippingTotal,
            $uniqueCode,
            $usedUniqueCode,
            $grandTotal
        ): Order {
            $order = Order::query()->create([
                'code' => $this->generateOrderCode(),
                'user_id' => $user->id,
                'customer_address_id' => $address->id,
                'status' => 'pending_payment',
                'payment_method' => 'Transfer manual',
                'payment_status' => 'Menunggu transfer',
                'items_total' => $itemsTotal,
                'shipping_total' => $shippingTotal,
                'unique_code' => $uniqueCode,
                'used_unique_code' => $usedUniqueCode,
                'grand_total' => $grandTotal,
                'shipping_service_name' => $selectedShipping['service'] ?? null,
                'shipping_estimate_days' => $selectedShipping['estimate'] ?? null,
                'shipment_note' => 'Menunggu validasi pembayaran sebelum shipment dibuat.',
                'recipient_name' => $address->recipient_name,
                'recipient_phone' => $address->recipient_phone,
                'address_label' => $address->label,
                'address_snapshot' => ApiData::addressSummary($address),
            ]);

            foreach ($selectedItems as $item) {
                $order->items()->create([
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_name' => $item->product_name,
                    'product_sku' => $item->product_sku,
                    'variant_label' => $item->variant_label,
                    'weight_grams' => $item->weight_grams,
                    'price' => $item->price,
                    'quantity' => $item->quantity,
                    'subtotal' => $item->subtotal,
                ]);
            }

            if ($usedUniqueCode > 0) {
                UserUniqueCode::query()->create([
                    'user_id' => $user->id,
                    'value' => $usedUniqueCode,
                    'ref_id' => $order->id,
                    'type' => 'used',
                ]);
            }

            $selectedIds = $selectedItems->pluck('id')->all();
            OrderItem::query()->whereIn('id', $selectedIds)->delete();

            $draftOrder->refresh()->load('items');
            $this->refreshDraftTotals($draftOrder);

            return $order->fresh(['items', 'user']);
        });

        return response()->json([
            'data' => ApiData::order($order),
            'message' => 'Pesanan berhasil dibuat.',
        ], 201);
    }

    public function cancel(Request $request, string $code): JsonResponse
    {
        $order = Order::query()
            ->with(['items', 'user'])
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', 'draft')
            ->where('code', $code)
            ->first();

        abort_if($order === null, 404);

        if ($order->status !== 'pending_payment') {
            throw ValidationException::withMessages([
                'order' => 'Pesanan ini sudah tidak bisa dibatalkan dari sisi customer.',
            ]);
        }

        DB::transaction(function () use ($order): void {
            UserUniqueCode::query()
                ->where('user_id', $order->user_id)
                ->where('ref_id', $order->id)
                ->whereIn('type', ['paid', 'used'])
                ->delete();

            $order->update([
                'status' => 'cancelled',
                'payment_status' => 'Dibatalkan customer',
                'shipment_note' => 'Order dibatalkan oleh customer sebelum pembayaran diverifikasi.',
            ]);
        });

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => 'Order berhasil dibatalkan.',
        ]);
    }

    public function complete(Request $request, string $code): JsonResponse
    {
        $order = Order::query()
            ->with(['items', 'user'])
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', 'draft')
            ->where('code', $code)
            ->first();

        abort_if($order === null, 404);

        if ($order->status !== 'shipped') {
            throw ValidationException::withMessages([
                'order' => 'Pesanan ini belum bisa ditandai selesai dari sisi customer.',
            ]);
        }

        $order->update([
            'status' => 'completed',
            'shipment_note' => 'Pesanan diterima customer dan order dinyatakan selesai.',
        ]);

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => 'Pesanan berhasil ditandai selesai.',
        ]);
    }

    private function resolveShippingOptions(?ShipmentOrigin $origin, ?CustomerAddress $address, int $weight, ?int $userId = null): array
    {
        if (
            $origin === null
            || $address === null
            || $origin->origin_id === null
            || $address->destination_id === null
            || blank($origin->selected_courier)
        ) {
            return [];
        }

        $requestPayload = [
            'origin' => $origin->origin_id,
            'destination' => $address->destination_id,
            'weight' => max($weight, 1000),
            'courier' => $origin->selected_courier,
        ];

        Log::info('order.checkout.shipping_rates.request', [
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

        Log::info('order.checkout.shipping_rates.response', [
            'user_id' => $userId,
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body' => $response->json(),
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
            ->values()
            ->all();
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

    private function resolveUniqueCode(Order $draftOrder): int
    {
        if (! $this->usesUniqueCode()) {
            return 0;
        }

        if ((int) $draftOrder->unique_code > 0) {
            return (int) $draftOrder->unique_code;
        }

        $uniqueCode = random_int(101, 999);
        $draftOrder->update(['unique_code' => $uniqueCode]);

        return $uniqueCode;
    }

    private function refreshDraftTotals(Order $order): void
    {
        $itemsTotal = (int) $order->items->sum('subtotal');
        $uniqueCode = $this->usesUniqueCode() ? ((int) $order->unique_code ?: random_int(101, 999)) : 0;
        $usedUniqueCode = min((int) $order->used_unique_code, $itemsTotal + $uniqueCode);

        $order->update([
            'items_total' => $itemsTotal,
            'shipping_total' => 0,
            'unique_code' => $itemsTotal > 0 ? $uniqueCode : 0,
            'used_unique_code' => $itemsTotal > 0 ? $usedUniqueCode : 0,
            'grand_total' => $itemsTotal > 0 ? max(0, $itemsTotal + $uniqueCode - $usedUniqueCode) : 0,
            'customer_address_id' => $itemsTotal > 0 ? $order->customer_address_id : null,
            'shipping_service_name' => $itemsTotal > 0 ? $order->shipping_service_name : null,
            'shipping_estimate_days' => $itemsTotal > 0 ? $order->shipping_estimate_days : null,
        ]);
    }

    private function generateOrderCode(): string
    {
        $nextNumber = ((int) Order::query()->where('status', '!=', 'draft')->count()) + 1;

        do {
            $code = 'ORD-'.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
            $nextNumber++;
        } while (Order::query()->where('code', $code)->exists());

        return $code;
    }

    private function usesUniqueCode(): bool
    {
        return (bool) config('services.checkout.use_unique_code', true);
    }
}
