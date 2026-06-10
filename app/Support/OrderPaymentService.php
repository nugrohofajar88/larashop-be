<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Setting;
use App\Models\UserUniqueCode;
use Illuminate\Support\Facades\DB;

/**
 * Validasi pembayaran order (dipakai admin panel & WA bot — satu sumber logika):
 * set status paid + ledger kode unik + auto-booking Komerce + catat tracking.
 */
class OrderPaymentService
{
    /**
     * @return array{message:string, booking_failed:bool, order_no:?string}
     */
    public function markPaid(Order $order, string $source = 'admin'): array
    {
        DB::transaction(function () use ($order): void {
            $order->update([
                'status' => 'paid',
                'payment_status' => 'Tervalidasi',
                'paid_at' => now(),
                'shipment_note' => 'Pembayaran tervalidasi. Order siap diproses ke shipment.',
            ]);

            if (Setting::uniqueCodeEnabled() && (int) $order->unique_code > 0) {
                UserUniqueCode::query()->firstOrCreate(
                    [
                        'user_id' => $order->user_id,
                        'ref_id' => $order->id,
                        'type' => 'paid',
                    ],
                    [
                        'value' => (int) $order->unique_code,
                    ]
                );
            }
        });

        // Auto-booking ekspedisi via Komerce (store order) — kalau diaktifkan.
        // Dilakukan SETELAH validasi commit, jadi validasi tetap sukses walau booking gagal.
        $bookingMessage = null;
        $komerce = app(KomerceShipmentService::class);

        if ($komerce->enabled()) {
            $result = $komerce->createOrder($order);

            if ($result['ok']) {
                $order->update([
                    'komerce_order_no' => $result['order_no'] ?? null,
                    'komerce_order_id' => $result['order_id'] ?? null,
                    'shipment_note' => 'Pembayaran tervalidasi. Order ekspedisi dibuat: '.($result['order_no'] ?? '-').'.',
                ]);
            } else {
                $bookingMessage = 'Pembayaran tervalidasi, tapi booking ekspedisi GAGAL: '
                    .($result['message'] ?? 'tidak diketahui').'. Bisa dicoba ulang.';
                $order->update(['shipment_note' => $bookingMessage]);
            }
        }

        $order->logTracking('paid', $source);

        return [
            'message' => $bookingMessage ?? 'Pembayaran berhasil divalidasi.',
            'booking_failed' => $bookingMessage !== null,
            'order_no' => $order->komerce_order_no,
        ];
    }
}
