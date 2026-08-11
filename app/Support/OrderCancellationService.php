<?php

namespace App\Support;

use App\Models\Order;
use App\Models\UserUniqueCode;
use Illuminate\Support\Facades\DB;

/**
 * Aturan pembatalan order oleh CUSTOMER sendiri (bukan admin) — dipakai
 * bersama oleh web (OrderController::cancel()) dan WA bot (WaBotService),
 * satu sumber logika supaya aturannya tidak bisa beda antara dua jalur itu.
 */
class OrderCancellationService
{
    /**
     * @return array{ok:bool,message:string}
     */
    public function cancel(Order $order): array
    {
        // Gate utama: resi (AWB) sudah terbit = sudah di-request pickup ke kurir,
        // tidak bisa dibatalkan dari sisi customer.
        if (trim((string) $order->awb) !== '') {
            return [
                'ok' => false,
                'message' => 'Pesanan sudah diproses kurir (resi sudah terbit) dan tidak bisa dibatalkan. Hubungi admin bila ada kendala.',
            ];
        }

        // 1) pending_payment -> batal LANGSUNG (belum ada pembayaran).
        if ($order->status === 'pending_payment') {
            DB::transaction(function () use ($order): void {
                UserUniqueCode::query()
                    ->where('user_id', $order->user_id)
                    ->where('ref_id', $order->id)
                    ->whereIn('type', ['paid', 'used'])
                    ->delete();

                // Kembalikan stok yang sempat dipotong saat order dibuat.
                app(StockService::class)->releaseForOrder($order);

                $order->update([
                    'status' => 'cancelled',
                    'payment_status' => 'Dibatalkan customer',
                    'shipment_note' => 'Order dibatalkan oleh customer sebelum pembayaran diverifikasi.',
                    'cancel_requested_at' => null,
                ]);
            });

            app(OrderCancellationNotifier::class)->send($order->fresh(['user']));

            return ['ok' => true, 'message' => 'Order berhasil dibatalkan.'];
        }

        // 2) COD berstatus "paid" tapi BELUM diproses admin (belum di-booking/pickup,
        // makanya masih "processing" belum tercapai) -> batal LANGSUNG juga, sama
        // seperti pending_payment. Uang COD belum benar-benar diterima (baru dibayar
        // saat barang sampai), jadi tidak ada pembayaran nyata yang perlu ditinjau admin.
        if ($order->status === 'paid' && $order->payment_method === 'COD') {
            DB::transaction(function () use ($order): void {
                app(StockService::class)->releaseForOrder($order);

                $order->update([
                    'status' => 'cancelled',
                    'payment_status' => 'Dibatalkan customer',
                    'shipment_note' => 'Order COD dibatalkan oleh customer sebelum diproses admin.',
                    'cancel_requested_at' => null,
                ]);
            });

            app(OrderCancellationNotifier::class)->send($order->fresh(['user']));

            return ['ok' => true, 'message' => 'Order berhasil dibatalkan.'];
        }

        // 3) paid (non-COD) / processing (AWB masih kosong) -> AJUKAN pembatalan, tunggu admin.
        if (in_array($order->status, ['paid', 'processing'], true)) {
            if ($order->cancel_requested_at !== null) {
                return [
                    'ok' => false,
                    'message' => 'Permintaan pembatalan sudah dikirim dan sedang menunggu konfirmasi admin.',
                ];
            }

            $order->update([
                'cancel_requested_at' => now(),
                'shipment_note' => 'Customer mengajukan pembatalan. Menunggu konfirmasi admin.',
            ]);

            return ['ok' => true, 'message' => 'Permintaan pembatalan dikirim. Menunggu konfirmasi admin.'];
        }

        // 4) shipped / completed / cancelled -> tidak bisa.
        return ['ok' => false, 'message' => 'Pesanan ini sudah tidak bisa dibatalkan.'];
    }
}
