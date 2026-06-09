<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $draftOrder = $this->draftOrder($request->user()->id);

        return response()->json([
            'data' => [
                'items' => $draftOrder->items
                    ->map(fn (OrderItem $item) => $this->mapItem($item))
                    ->values()
                    ->all(),
                'summary' => $this->summary($draftOrder->items),
            ],
        ]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'product_variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'selected' => ['nullable', 'boolean'],
        ]);

        $product = Product::query()->with(['images', 'variants'])->findOrFail($validated['product_id']);
        $variant = $this->resolveVariant($product, $validated['product_variant_id'] ?? null);
        $draftOrder = $this->draftOrder($request->user()->id);
        $quantity = min(max(1, (int) ($validated['quantity'] ?? 1)), max(1, (int) $variant->stock));
        $selected = (bool) ($validated['selected'] ?? false);

        $item = DB::transaction(function () use ($draftOrder, $product, $variant, $quantity, $selected) {
            $item = $draftOrder->items()
                ->where('product_id', $product->id)
                ->where('product_variant_id', $variant->id)
                ->first();

            if ($item !== null) {
                $newQuantity = min($item->quantity + $quantity, max(1, (int) $variant->stock));
                $item->update([
                    'product_name' => $product->name,
                    'product_sku' => $variant->sku,
                    'variant_label' => $variant->label,
                    'weight_grams' => $variant->weight_grams,
                    'price' => $variant->price,
                    'quantity' => $newQuantity,
                    'subtotal' => $variant->price * $newQuantity,
                    'is_selected' => $selected ? true : $item->is_selected,
                ]);
            } else {
                $item = $draftOrder->items()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'product_name' => $product->name,
                    'product_sku' => $variant->sku,
                    'variant_label' => $variant->label,
                    'weight_grams' => $variant->weight_grams,
                    'price' => $variant->price,
                    'quantity' => $quantity,
                    'subtotal' => $variant->price * $quantity,
                    'is_selected' => $selected,
                ]);
            }

            $this->refreshDraftTotals($draftOrder->fresh('items'));

            return $item->fresh(['product.images', 'variant']);
        });

        $draftOrder = $draftOrder->fresh(['items.product.images', 'items.variant']);

        return response()->json([
            'data' => [
                'item' => $this->mapItem($item),
                'summary' => $this->summary($draftOrder->items),
            ],
            'message' => 'Produk berhasil masuk ke keranjang.',
        ], 201);
    }

    public function updateItem(Request $request, OrderItem $item): JsonResponse
    {
        $draftOrder = $this->draftOrder($request->user()->id);
        abort_unless($item->order_id === $draftOrder->id, 404);

        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1'],
            'selected' => ['nullable', 'boolean'],
        ]);

        $productStock = $this->resolveItemStock($item);
        $quantity = min((int) ($validated['quantity'] ?? $item->quantity), $productStock);
        $selected = array_key_exists('selected', $validated) ? (bool) $validated['selected'] : $item->is_selected;

        DB::transaction(function () use ($item, $quantity, $selected, $draftOrder): void {
            $item->update([
                'quantity' => $quantity,
                'subtotal' => $item->price * $quantity,
                'is_selected' => $selected,
            ]);

            $this->refreshDraftTotals($draftOrder->fresh('items'));
        });

        $item->refresh()->load(['product.images', 'variant']);
        $draftOrder = $draftOrder->fresh(['items.product.images', 'items.variant']);

        return response()->json([
            'data' => [
                'item' => $this->mapItem($item),
                'summary' => $this->summary($draftOrder->items),
            ],
        ]);
    }

    public function destroyItem(Request $request, OrderItem $item): JsonResponse
    {
        $draftOrder = $this->draftOrder($request->user()->id);
        abort_unless($item->order_id === $draftOrder->id, 404);

        DB::transaction(function () use ($item, $draftOrder): void {
            $item->delete();
            $this->refreshDraftTotals($draftOrder->fresh('items'));
        });

        $draftOrder = $draftOrder->fresh(['items.product.images', 'items.variant']);

        return response()->json([
            'data' => [
                'summary' => $this->summary($draftOrder->items),
            ],
            'message' => 'Item berhasil dihapus dari keranjang.',
        ]);
    }

    private function draftOrder(int $userId): Order
    {
        return Order::query()
            ->with(['items.product.images', 'items.variant'])
            ->firstOrCreate(
                [
                    'user_id' => $userId,
                    'status' => 'draft',
                ],
                [
                    'code' => 'DRF-'.Str::upper(Str::random(8)),
                    'payment_method' => 'Transfer manual',
                    'payment_status' => 'Draft',
                    'unique_code' => $this->generateUniqueCode(),
                    'used_unique_code' => 0,
                ]
            );
    }

    private function resolveVariant(Product $product, ?int $variantId): ProductVariant
    {
        $variant = $variantId !== null
            ? $product->variants->firstWhere('id', $variantId)
            : ($product->variants->firstWhere('is_default', true) ?? $product->variants->firstWhere('is_active', true) ?? $product->variants->first());

        if ($variant === null || ! $variant->is_active) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Varian produk tidak valid atau sudah tidak aktif.',
            ]);
        }

        if ((int) $variant->stock < 1) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Varian produk ini sedang habis.',
            ]);
        }

        return $variant;
    }

    private function resolveItemStock(OrderItem $item): int
    {
        $variantStock = (int) ($item->variant?->stock ?? 0);

        if ($variantStock > 0) {
            return $variantStock;
        }

        return max(1, (int) ($item->product?->stock ?? $item->quantity));
    }

    private function mapItem(OrderItem $item): array
    {
        $image = $item->product?->images->firstWhere('is_primary', true)?->path
            ?? $item->product?->images->first()?->path
            ?? '/images/products/gallery-detail.svg';
        $stock = $this->resolveItemStock($item);

        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'name' => $item->product_name,
            'variant' => $item->variant_label ?: ($item->variant?->label ?? $item->product?->weight_label ?? '-'),
            'image' => $image,
            'price' => 'Rp'.number_format($item->price, 0, ',', '.'),
            'price_value' => (int) $item->price,
            'qty' => (int) $item->quantity,
            'stock' => $stock,
            'selected' => (bool) $item->is_selected,
            'subtotal' => 'Rp'.number_format($item->subtotal, 0, ',', '.'),
            'subtotal_value' => (int) $item->subtotal,
        ];
    }

    private function summary(Collection $items): array
    {
        $selectedItems = $items->where('is_selected', true);
        $selectedProductCount = (int) $selectedItems->sum('quantity');
        $selectedTotalValue = (int) $selectedItems->sum('subtotal');

        return [
            'selected_product_count' => $selectedProductCount,
            'selected_total_value' => $selectedTotalValue,
            'selected_total' => 'Rp'.number_format($selectedTotalValue, 0, ',', '.'),
        ];
    }

    private function refreshDraftTotals(Order $order): void
    {
        $itemsTotal = (int) $order->items->sum('subtotal');
        $uniqueCode = $this->resolveUniqueCode($order);
        $usedUniqueCode = min((int) $order->used_unique_code, $itemsTotal + $uniqueCode);

        $order->update([
            'items_total' => $itemsTotal,
            'shipping_total' => 0,
            'unique_code' => $itemsTotal > 0 ? $uniqueCode : 0,
            'used_unique_code' => $itemsTotal > 0 ? $usedUniqueCode : 0,
            'grand_total' => $itemsTotal > 0 ? max(0, $itemsTotal + $uniqueCode - $usedUniqueCode) : 0,
        ]);
    }

    private function resolveUniqueCode(Order $order): int
    {
        if (! $this->usesUniqueCode()) {
            return 0;
        }

        return $order->unique_code ?: $this->generateUniqueCode();
    }

    private function usesUniqueCode(): bool
    {
        return \App\Models\Setting::uniqueCodeEnabled();
    }

    private function generateUniqueCode(): int
    {
        return $this->usesUniqueCode() ? random_int(101, 999) : 0;
    }
}
