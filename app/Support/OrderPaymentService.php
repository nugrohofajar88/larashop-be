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
        // Guard anti-dobel (row lock) - mencegah 2 permintaan yang nyaris bersamaan
        // (klik dobel tombol "Validasi pembayaran", webhook+poll QRIS, dll) sama-sama
        // lolos dan booking ke Komerce 2x untuk order yang sama. Insiden nyata: 11
        // order kena biaya retry-booking ganda (~Rp267rb) krn markPaid() dipanggil
        // dobel tanpa penjagaan ini - lihat shipping_retry_fee di tabel orders.
        // lockForUpdate() bikin request kedua NUNGGU sampai request pertama commit,
        // baru re-cek status - jadi begitu lolos lock, statusnya udah pasti "paid".
        $isDuplicate = DB::transaction(function () use ($order, $source): bool {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending_payment') {
                return true;
            }

            $locked->update([
                'status' => 'paid',
                'payment_status' => $source === 'cod' ? 'COD - bayar saat barang diterima' : 'Tervalidasi',
                'paid_at' => now(),
                'shipment_note' => $source === 'cod'
                    ? 'Pesanan COD dikonfirmasi. Order siap diproses ke shipment.'
                    : 'Pembayaran tervalidasi. Order siap diproses ke shipment.',
            ]);

            if (Setting::uniqueCodeEnabled() && (int) $locked->unique_code > 0) {
                UserUniqueCode::query()->firstOrCreate(
                    [
                        'user_id' => $locked->user_id,
                        'ref_id' => $locked->id,
                        'type' => 'paid',
                    ],
                    [
                        'value' => (int) $locked->unique_code,
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
                $diff = (int) $locked->qris_amount - (int) $locked->grand_total;
                if ((int) $locked->qris_amount > 0 && $diff > 0) {
                    $orderUpdate['unique_code'] = $diff;
                    $orderUpdate['grand_total'] = (int) $locked->qris_amount;

                    UserUniqueCode::query()->firstOrCreate(
                        [
                            'user_id' => $locked->user_id,
                            'ref_id' => $locked->id,
                            'type' => 'paid',
                        ],
                        [
                            'value' => $diff,
                        ]
                    );
                }

                $locked->update($orderUpdate);
            }

            return false;
        });

        $order->refresh();

        if ($isDuplicate) {
            return [
                'message' => 'Order ini sudah divalidasi sebelumnya (permintaan dobel diabaikan, booking ekspedisi tidak diulang).',
                'booking_failed' => false,
                'order_no' => $order->komerce_order_no,
            ];
        }

        // Notif push ke admin (PWA) - order baru siap diproses. Independen dari hasil
        // booking Komerce di bawah (admin tetap perlu tahu meski booking-nya gagal).
        // Dibungkus try/catch - kegagalan kirim notif TIDAK BOLEH menggagalkan validasi
        // order (sama seperti booking Komerce, ini best-effort).
        try {
            app(\App\Support\PushNotificationService::class)->notifyAdmins(
                'Order baru: '.$order->code,
                'Rp'.number_format((int) $order->grand_total, 0, ',', '.').' - '.($order->recipient_name ?? 'Pelanggan'),
                ['url' => '/admin/orders/'.$order->code]
            );
        } catch (\Throwable $e) {
            report($e);
        }

        // Auto-booking ekspedisi via Komerce (store order) — kalau diaktifkan.
        // Dilakukan SETELAH validasi commit, jadi validasi tetap sukses walau booking gagal.
        $bookingMessage = null;
        $komerce = app(KomerceShipmentService::class);

        if ($komerce->enabled()) {
            $result = $komerce->createOrder($order);
            $validatedLabel = $source === 'cod' ? 'Pesanan COD dikonfirmasi' : 'Pembayaran tervalidasi';

            if ($result['ok']) {
                $order->update([
                    'komerce_order_no' => $result['order_no'] ?? null,
                    'komerce_order_id' => $result['order_id'] ?? null,
                    'cod_service_fee' => (int) ($result['service_fee'] ?? 0),
                    'shipment_note' => $validatedLabel.'. Order ekspedisi dibuat: '.($result['order_no'] ?? '-').'.',
                ]);
            } else {
                $bookingMessage = $validatedLabel.', tapi booking ekspedisi GAGAL: '
                    .($result['message'] ?? 'tidak diketahui').'. Bisa dicoba ulang.';
                $order->update(['shipment_note' => $bookingMessage]);
            }
        }

        $order->logTracking('paid', $source);

        // Beri tahu pelanggan via WhatsApp (semua jalur: admin panel, WA, webhook QRIS, poll).
        // Kecuali COD: WaOrderService sudah kirim balasan konfirmasi order COD-nya
        // sendiri di turn yang sama — notifikasi ini akan dobel kalau ikut dikirim.
        if ($source !== 'cod') {
            $this->notifyCustomer($order);
        }

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
