<?php

namespace App\Support;

use App\Models\Order;
use App\Models\UserUniqueCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pulihkan order yang dibatalkan OTOMATIS karena telat bayar (ExpireUnpaidOrders),
 * mis. pelanggan sudah transfer tapi lupa konfirmasi. Membalik apa yang dilakukan
 * auto-expire: stok dipotong lagi, saldo kode unik yang dipakai dicatat lagi,
 * status kembali pending_payment dgn tenggat baru - lalu admin validasi seperti biasa.
 */
class OrderRestoreService
{
    public function restore(Order $order, string $source = 'admin'): Order
    {
        DB::transaction(function () use ($order, $source): void {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isRestorable()) {
                throw ValidationException::withMessages([
                    'order' => 'Hanya order yang dibatalkan otomatis karena telat bayar yang bisa dipulihkan.',
                ]);
            }

            $used = (int) $locked->used_unique_code;

            // Saldo kode unik dikembalikan ke pelanggan saat order batal; kalau sudah
            // terpakai untuk order lain, order ini tidak bisa dipulihkan apa adanya.
            if ($used > 0 && (int) ($locked->user?->uniqueCodeBalance() ?? 0) < $used) {
                throw ValidationException::withMessages([
                    'order' => 'Saldo kode unik pelanggan sudah tidak cukup (Rp'.number_format($used, 0, ',', '.').') - order tidak bisa dipulihkan.',
                ]);
            }

            // Throw ValidationException kalau stok tidak cukup -> seluruh transaksi rollback.
            app(StockService::class)->reserveForOrder($locked);

            if ($used > 0) {
                UserUniqueCode::query()->create([
                    'user_id' => $locked->user_id,
                    'value' => $used,
                    'ref_id' => $locked->id,
                    'type' => 'used',
                ]);
            }

            $locked->update([
                'status' => 'pending_payment',
                'payment_status' => 'Menunggu transfer',
                'payment_reminder_stage' => 0,
                'restored_at' => now(),
                'shipment_note' => 'Order dipulihkan admin setelah sempat dibatalkan otomatis. Menunggu validasi pembayaran.',
            ]);

            $locked->logTracking('restored', $source);
        });

        return $order->refresh();
    }
}
