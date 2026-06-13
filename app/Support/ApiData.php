<?php

namespace App\Support;

use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShipmentOrigin;
use App\Models\ShippingService;
use App\Models\User;

class ApiData
{
    public static function rupiah(int $value): string
    {
        return 'Rp'.number_format($value, 0, ',', '.');
    }

    public static function productStatusLabel(string $status): string
    {
        return match ($status) {
            'available' => 'Tersedia',
            'limited' => 'Stok terbatas',
            'preorder' => 'Pre-order',
            'sold_out' => 'Habis',
            default => 'Tersedia',
        };
    }

    public static function productStockLabel(string $status): string
    {
        return match ($status) {
            'available' => 'Stok siap kirim',
            'limited' => 'Stok terbatas',
            'preorder' => 'Pre-order',
            'sold_out' => 'Stok habis',
            default => 'Stok siap kirim',
        };
    }

    public static function publicStatusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'active' => 'Aktif',
            'inactive' => 'Nonaktif',
            'preorder' => 'Pre-order',
            default => 'Draft',
        };
    }

    public static function customerStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Aktif',
            'inactive' => 'Nonaktif',
            'pending_verification' => 'Menunggu verifikasi',
            default => 'Aktif',
        };
    }

    public static function soldLabel(int $soldCount): string
    {
        if ($soldCount >= 1000) {
            $rounded = number_format($soldCount / 1000, 1, ',', '');

            return $rounded.'RB+ terjual';
        }

        return number_format($soldCount, 0, ',', '.').' terjual';
    }

    public static function discountBadge(?int $compareAtPrice, int $price): ?string
    {
        if ($compareAtPrice === null || $compareAtPrice <= $price || $compareAtPrice === 0) {
            return null;
        }

        $percent = (int) round((($compareAtPrice - $price) / $compareAtPrice) * 100);

        return '-'.$percent.'%';
    }

    public static function addressSummary(CustomerAddress $address): string
    {
        return collect([
            $address->address_line,
            $address->subdistrict,
            'Kec. '.$address->district,
            $address->city,
            $address->province,
            $address->postal_code,
        ])->filter()->implode(', ');
    }

    public static function address(CustomerAddress $address): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'name' => $address->recipient_name,
            'phone' => $address->recipient_phone,
            'destination_id' => $address->destination_id,
            'province' => $address->province,
            'city' => $address->city,
            'district' => $address->district,
            'subdistrict' => $address->subdistrict,
            'postal_code' => $address->postal_code,
            'address_line' => $address->address_line,
            'note' => $address->note,
            'is_primary' => $address->is_primary,
            'detail' => self::addressSummary($address),
        ];
    }

    public static function shipmentOriginSummary(ShipmentOrigin $origin): string
    {
        return collect([
            $origin->address_line,
            $origin->subdistrict,
            'Kec. '.$origin->district,
            $origin->city,
            $origin->province,
            $origin->postal_code,
        ])->filter()->implode(', ');
    }

    public static function shipmentOrigin(ShipmentOrigin $origin): array
    {
        return [
            'id' => $origin->id,
            'label' => $origin->label,
            'contact_name' => $origin->contact_name,
            'contact_phone' => $origin->contact_phone,
            'origin_id' => $origin->origin_id,
            'selected_courier' => $origin->selected_courier,
            'province' => $origin->province,
            'city' => $origin->city,
            'district' => $origin->district,
            'subdistrict' => $origin->subdistrict,
            'postal_code' => $origin->postal_code,
            'address_line' => $origin->address_line,
            'pin_point' => $origin->pin_point,
            'note' => $origin->note,
            'is_default' => $origin->is_default,
            'is_active' => $origin->is_active,
            'detail' => self::shipmentOriginSummary($origin),
        ];
    }

    public static function orderStatusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'pending_payment' => 'Menunggu pembayaran',
            'paid' => 'Paid',
            'processing' => 'Processing',
            'shipped' => 'Shipped',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function shipping(ShippingService $shippingService, bool $selected = false): array
    {
        return [
            'id' => $shippingService->id,
            'code' => $shippingService->code,
            'service' => $shippingService->name.' '.$shippingService->service_level,
            'estimate' => $shippingService->estimate_days,
            'price' => self::rupiah($shippingService->price),
            'price_value' => $shippingService->price,
            'selected' => $selected,
        ];
    }

    public static function dimensionLabel($length, $width, $height): string
    {
        $values = collect([$length, $width, $height])
            ->map(fn ($value) => $value === null ? null : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'));

        if ($values->filter()->isEmpty()) {
            return 'Belum diatur';
        }

        return $values->map(fn ($value) => $value ?: '0')->implode(' x ').' cm';
    }

    public static function productVariant(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'label' => $variant->label,
            'price' => self::rupiah($variant->price),
            'price_value' => $variant->price,
            'compare_at_price' => $variant->compare_at_price,
            'original_price' => $variant->compare_at_price ? self::rupiah($variant->compare_at_price) : null,
            'discount_badge' => self::discountBadge($variant->compare_at_price, $variant->price),
            'stock' => $variant->stock,
            'weight_grams' => $variant->weight_grams,
            'weight_label' => $variant->label,
            'length_cm' => $variant->length_cm,
            'width_cm' => $variant->width_cm,
            'height_cm' => $variant->height_cm,
            'dimension' => self::dimensionLabel($variant->length_cm, $variant->width_cm, $variant->height_cm),
            'is_default' => $variant->is_default,
            'is_active' => $variant->is_active,
        ];
    }

    public static function resolveDefaultVariant(Product $product): ?ProductVariant
    {
        $variants = $product->relationLoaded('variants') ? $product->variants : $product->variants()->get();

        return $variants->firstWhere('is_default', true)
            ?? $variants->firstWhere('is_active', true)
            ?? $variants->first();
    }

    public static function product(Product $product): array
    {
        $primaryImage = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
        $variantsCollection = $product->relationLoaded('variants') ? $product->variants : $product->variants()->get();
        $defaultVariant = self::resolveDefaultVariant($product);
        $variants = $variantsCollection->map(fn (ProductVariant $variant) => self::productVariant($variant))->values()->all();

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'slug' => $product->slug,
            'name' => $product->name,
            'image' => $primaryImage?->path,
            'images' => $product->images->map(fn ($image) => [
                'id' => $image->id,
                'path' => $image->path,
                'alt' => $image->alt,
                'is_primary' => $image->is_primary,
                'sort_order' => $image->sort_order,
            ])->values()->all(),
            'category' => $product->category->name,
            'price' => self::rupiah($product->price),
            'price_value' => $product->price,
            'original_price' => $product->compare_at_price ? self::rupiah($product->compare_at_price) : null,
            'discount_badge' => self::discountBadge($product->compare_at_price, $product->price),
            'weight' => $product->weight_label,
            'weight_grams' => $product->weight_grams,
            'stock' => $product->stock,
            'badge' => $product->badge_label,
            'sold_label' => self::soldLabel($product->sold_count),
            'description' => $product->description,
            'highlights' => $product->highlights ?? [],
            'default_variant' => $defaultVariant ? self::productVariant($defaultVariant) : null,
            'variants' => $variants,
            'variant_count' => count($variants),
        ];
    }

    public static function customer(User $user): array
    {
        $uniqueCodeBalance = $user->uniqueCodeBalance();

        return [
            'id' => $user->id,
            'code' => $user->code,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => self::customerStatusLabel($user->status),
            'role' => $user->role,
            'admin_role' => $user->admin_role,
            'is_super_admin' => $user->role === 'admin' && $user->admin_role === 'super_admin',
            'unique_code_balance' => self::rupiah($uniqueCodeBalance),
            'unique_code_balance_value' => $uniqueCodeBalance,
        ];
    }

    public static function adminProduct(Product $product): array
    {
        $primaryImage = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
        $variantsCollection = $product->relationLoaded('variants') ? $product->variants : $product->variants()->get();
        $defaultVariant = self::resolveDefaultVariant($product);
        $variants = $variantsCollection->map(fn (ProductVariant $variant) => self::productVariant($variant))->values()->all();

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'slug' => $product->slug,
            'name' => $product->name,
            'image' => $primaryImage?->path,
            'images' => $product->images->map(fn ($image) => [
                'id' => $image->id,
                'path' => $image->path,
                'alt' => $image->alt,
                'sort_order' => $image->sort_order,
                'is_primary' => $image->is_primary,
            ])->values()->all(),
            'category' => $product->category->name,
            'category_slug' => $product->category->slug,
            'price' => self::rupiah($product->price),
            'price_value' => $product->price,
            'compare_at_price' => $product->compare_at_price,
            'stock' => $product->stock,
            'weight_label' => $product->weight_label,
            'weight_grams' => $product->weight_grams,
            'length_cm' => $product->length_cm,
            'width_cm' => $product->width_cm,
            'height_cm' => $product->height_cm,
            'dimension' => self::dimensionLabel($product->length_cm, $product->width_cm, $product->height_cm),
            'public_status' => $product->public_status,
            'status' => self::publicStatusLabel($product->public_status),
            'catalog_status' => $product->catalog_status,
            'badge_label' => $product->badge_label,
            'sold_count' => $product->sold_count,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'highlights' => $product->highlights ?? [],
            'published_at' => $product->published_at?->toISOString(),
            'default_variant' => $defaultVariant ? self::productVariant($defaultVariant) : null,
            'variants' => $variants,
            'variant_count' => count($variants),
        ];
    }

    public static function adminCustomer(User $user): array
    {
        $uniqueCodeBalance = $user->uniqueCodeBalance();

        return [
            'id' => $user->id,
            'code' => $user->code,
            'name' => $user->name,
            'username' => $user->username,
            'phone' => $user->phone,
            'email' => $user->email,
            'status' => self::customerStatusLabel($user->status),
            'status_key' => $user->status,
            'address_count' => $user->addresses_count ?? $user->addresses->count(),
            'unique_code_balance' => self::rupiah($uniqueCodeBalance),
            'unique_code_balance_value' => $uniqueCodeBalance,
            'addresses' => $user->relationLoaded('addresses')
                ? $user->addresses->sortByDesc('is_primary')->values()->map(fn (CustomerAddress $address) => self::address($address))->all()
                : [],
        ];
    }

    public static function adminAccount(User $user): array
    {
        return [
            'id' => $user->code,
            'user_id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => match ($user->admin_role) {
                'super_admin' => 'Super Admin',
                'operational_admin' => 'Admin Operasional',
                'warehouse_admin' => 'Admin Gudang',
                default => 'Admin Operasional',
            },
            'role_key' => $user->admin_role,
            'status' => self::customerStatusLabel($user->status),
            'status_key' => $user->status,
            'last_login' => $user->last_login_at?->format('d M Y, H:i') ?? '-',
            'note' => match ($user->admin_role) {
                'super_admin' => 'Memegang akses penuh dashboard dan pengaturan operasional.',
                'operational_admin' => 'Fokus pada validasi order, customer service, dan pembaruan katalog.',
                default => 'Akses gudang dan shipment.',
            },
        ];
    }

    public static function order(Order $order): array
    {
        return [
            'id' => $order->id,
            'code' => $order->code,
            'date' => $order->created_at?->translatedFormat('d F Y') ?? $order->created_at?->format('d M Y'),
            'status' => $order->status,
            'status_label' => self::orderStatusLabel($order->status),
            'total' => self::rupiah($order->grand_total),
            'total_value' => $order->grand_total,
            'customer' => $order->user?->name,
            'phone' => $order->recipient_phone,
            'payment_status' => $order->payment_status,
            'shipping_service' => $order->shipping_service_name,
            'shipping_estimate' => $order->shipping_estimate_days,
            'awb' => $order->awb,
            // Pembatalan: flag pengajuan (paid/processing menunggu admin) + apakah
            // customer masih boleh menekan tombol batal/ajukan batal.
            'cancel_requested' => $order->cancel_requested_at !== null,
            'cancel_requested_at' => $order->cancel_requested_at?->format('Y-m-d H:i'),
            'can_cancel' => trim((string) $order->awb) === ''
                && $order->cancel_requested_at === null
                && in_array($order->status, ['pending_payment', 'paid', 'processing'], true),
            'address' => $order->address_snapshot,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'variant' => $item->variant_label,
                'qty' => $item->quantity,
                'subtotal' => self::rupiah($item->subtotal),
            ])->values()->all(),
            'payment' => [
                'method' => $order->payment_method,
                'items_total' => self::rupiah($order->items_total),
                'shipping_total' => self::rupiah($order->shipping_total),
                'unique_code' => self::rupiah($order->unique_code),
                'used_unique_code' => self::rupiah($order->used_unique_code),
                'grand_total' => self::rupiah($order->grand_total),
            ],
            'shipping' => [
                'service' => $order->shipping_service_name,
                'estimate' => $order->shipping_estimate_days,
                'address' => $order->address_snapshot,
                'awb' => $order->awb,
                'komerce_order_no' => $order->komerce_order_no,
                'note' => $order->shipment_note,
            ],
            // Riwayat status (timeline). Hanya kalau relasi sengaja di-load (detail),
            // supaya halaman daftar tidak N+1.
            'trackings' => $order->relationLoaded('trackings')
                ? $order->trackings->map(fn (\App\Models\OrderTracking $t): array => [
                    'status' => $t->status,
                    'label' => self::trackingLabel($t->status),
                    'source' => $t->source,
                    'raw_status' => $t->raw_status,
                    'awb' => $t->awb,
                    'note' => $t->note,
                    'time' => $t->created_at?->translatedFormat('d M Y, H:i') ?? $t->created_at?->format('d M Y, H:i'),
                ])->values()->all()
                : [],
        ];
    }

    public static function trackingLabel(string $status): string
    {
        return match ($status) {
            'created' => 'Pesanan dibuat',
            'paid' => 'Pembayaran dikonfirmasi',
            'pickup_scheduled' => 'Pickup dijadwalkan',
            'submitted' => 'Diajukan ke kurir',
            'picked_up' => 'Dijemput kurir',
            'in_transit' => 'Dalam pengiriman',
            'delivered' => 'Sampai tujuan',
            'cancelled' => 'Dibatalkan',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function shipment(Order $order): array
    {
        return [
            'id' => $order->id,
            'code' => 'SHP-'.$order->code,
            'order_code' => $order->code,
            'customer' => $order->user?->name,
            'courier' => $order->shipping_service_name,
            'awb' => $order->awb,
            'status' => match ($order->status) {
                'paid' => 'ready_to_create',
                'processing' => 'pickup_scheduled',
                'shipped' => 'in_transit',
                default => 'ready_to_create',
            },
            'note' => $order->shipment_note ?: 'Shipment belum diproses.',
        ];
    }
}
