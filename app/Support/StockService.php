<?php

namespace App\Support;

use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

/**
 * Pengelola stok untuk siklus order: RESERVE saat order dibuat, RELEASE saat
 * order dibatalkan. Dipakai bersama oleh checkout web, order via WhatsApp, dan
 * jalur pembatalan (customer + admin).
 *
 * Sumber kebenaran stok = ProductVariant (setiap produk selalu punya >=1 varian;
 * order item selalu membawa product_variant_id). products tidak lagi menyimpan
 * stok — products.stock adalah turunan live (Σ stok varian aktif), jadi tidak ada
 * yang perlu disinkronkan di sini.
 *
 * Pemotongan dilakukan ATOMIK di level DB (UPDATE ... WHERE stock >= qty) sehingga
 * tidak mungkin minus walau ada order bersamaan (anti oversell). Jejak reservasi
 * disimpan PER ITEM di order_items.reserved_qty supaya idempotent & siap untuk
 * edit order (ubah qty / hapus sebagian item).
 *
 * Panggil di dalam DB::transaction supaya gagal pada satu item me-rollback semua.
 */
class StockService
{
    /**
     * Potong stok varian untuk semua item order (reserve). Throw kalau ada item
     * yang stoknya tidak cukup → transaksi pemanggil ter-rollback.
     */
    public function reserveForOrder(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $qty = (int) $item->quantity;

            // Sudah pernah di-reserve (reserved_qty > 0) → jangan dobel.
            if ($qty <= 0 || (int) $item->reserved_qty > 0) {
                continue;
            }

            $variantId = $item->product_variant_id;
            $label = $item->product_name.($item->variant_label ? ' ('.$item->variant_label.')' : '');

            if (! $variantId) {
                // Model menjamin selalu ada varian; tanpa varian = data tidak valid.
                throw ValidationException::withMessages([
                    'stock' => 'Item '.$label.' tidak punya varian, stok tidak bisa diproses.',
                ]);
            }

            $affected = ProductVariant::query()
                ->where('id', $variantId)
                ->where('stock', '>=', $qty)
                ->decrement('stock', $qty);

            if ($affected === 0) {
                $current = (int) (ProductVariant::query()->whereKey($variantId)->value('stock') ?? 0);

                throw ValidationException::withMessages([
                    'stock' => 'Stok '.$label.' tidak cukup. Tersisa '.$current.', diminta '.$qty.'.',
                ]);
            }

            $item->update(['reserved_qty' => $qty]);
        }
    }

    /**
     * Kembalikan stok varian untuk semua item order (release). Hanya item yang
     * memang menahan stok (reserved_qty > 0) yang dikembalikan, jadi idempotent
     * dan aman untuk order lama (reserved_qty = 0 → tidak menambah apa pun).
     */
    public function releaseForOrder(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $reserved = (int) $item->reserved_qty;
            if ($reserved <= 0) {
                continue;
            }

            if ($item->product_variant_id) {
                ProductVariant::query()->whereKey($item->product_variant_id)->increment('stock', $reserved);
            }

            $item->update(['reserved_qty' => 0]);
        }
    }
}
