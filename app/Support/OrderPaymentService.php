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

            // Bayar via QRIS: samakan data order dengan yang benar-benar dibayar.
            if ($source === 'qrisly') {
                $orderUpdate = ['payment_method' => 'QRIS'];

                // Kelebihan dari kode unik QRISLY (final − grand_total) diperlakukan
                // seperti kode unik: masuk ke unique_code + grand_total, DAN dicatat
                // sebagai SALDO customer (type 'paid') — terlepas dari setting kode
                // unik manual. Jadi data order konsisten & receh tak hilang.
                $diff = (int) $order->qris_amount - (int) $order->grand_total;
                if ((int) $order->qris_amount > 0 && $diff > 0) {
                    $orderUpdate['unique_code'] = $diff;
                    $orderUpdate['grand_total'] = (int) $order->qris_amount;

                    UserUniqueCode::query()->firstOrCreate(
                        [
                            'user_id' => $order->user_id,
                            'ref_id' => $order->id,
                            'type' => 'paid',
                        ],
                        [
                            'value' => $diff,
                        ]
                    );
                }

                $order->update($orderUpdate);
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

        // Beri tahu pelanggan via WhatsApp (semua jalur: admin panel, WA, webhook QRIS, poll).
        $this->notifyCustomer($order);

        return [
            'message' => $bookingMessage ?? 'Pembayaran berhasil divalidasi.',
            'booking_failed' => $bookingMessage !== null,
            'order_no' => $order->komerce_order_no,
        ];
    }

    /** Notifikasi WA ke pelanggan bahwa pembayarannya sudah dikonfirmasi. */
    protected function notifyCustomer(Order $order): void
    {
        $phone = preg_replace('/[^0-9]/', '', (string) ($order->user?->phone ?? $order->recipient_phone ?? '')) ?? '';
        if ($phone === '') {
            return;
        }
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }

        app(\App\Support\Contracts\WhatsappGateway::class)->sendMessage(
            $phone,
            "🎉 Pembayaran untuk pesanan *{$order->code}* sudah *dikonfirmasi*!\n\n"
            ."Pesananmu segera kami proses & kirim. Terima kasih sudah belanja di *Akar Tani Kimia* 🌱"
        );
    }
}
