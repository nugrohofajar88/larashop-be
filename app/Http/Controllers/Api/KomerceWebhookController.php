<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Komerce — menerima notifikasi status pengiriman real-time.
 * Payload: { order_no, cnote (=awb), status }.
 * Status Komerce: Diajukan / Dijemput / Dikirim / Selesai / Dibatalkan.
 */
class KomerceWebhookController extends Controller
{
    /** Mapping status Komerce -> status order internal. */
    private const STATUS_MAP = [
        'diajukan' => 'processing',
        'dijemput' => 'shipped',
        'dikirim' => 'shipped',
        'selesai' => 'completed',
        // 'dibatalkan' SENGAJA tidak di-auto-map: pembatalan butuh reversal saldo/kode unik,
        // jadi hanya dicatat di note agar admin menanganinya manual.
    ];

    /** Mapping status Komerce -> status di timeline tracking (lebih halus). */
    private const TRACK_MAP = [
        'diajukan' => 'submitted',
        'dijemput' => 'picked_up',
        'dikirim' => 'in_transit',
        'selesai' => 'delivered',
        'dibatalkan' => 'cancelled',
    ];

    public function handle(Request $request, ?string $token = null): JsonResponse
    {
        // Verifikasi token rahasia. Diutamakan dari segment path (/webhooks/komerce/{token})
        // karena form Komerce menolak query string; fallback ke ?token= bila ada.
        $secret = trim((string) config('services.komerce_delivery.webhook_secret'));
        $provided = ($token !== null && $token !== '') ? $token : (string) $request->query('token');
        if ($secret !== '' && $provided !== $secret) {
            Log::warning('komerce.webhook.unauthorized', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $orderNo = trim((string) $request->input('order_no'));
        $awb = trim((string) $request->input('cnote'));
        $statusRaw = trim((string) $request->input('status'));

        Log::info('komerce.webhook.received', ['order_no' => $orderNo, 'cnote' => $awb, 'status' => $statusRaw]);

        // Selalu balas 200 (kecuali unauthorized) supaya Komerce tidak retry terus.
        if ($orderNo === '') {
            return response()->json(['message' => 'order_no kosong'], 200);
        }

        $order = Order::where('komerce_order_no', $orderNo)->first();

        if ($order === null) {
            Log::warning('komerce.webhook.order_not_found', ['order_no' => $orderNo]);

            return response()->json(['message' => 'Order tidak ditemukan'], 200);
        }

        $update = [
            'shipment_note' => 'Update Komerce: '.($statusRaw !== '' ? $statusRaw : '-').($awb !== '' ? ' (AWB '.$awb.')' : ''),
        ];

        // AWB asli dari kurir — simpan apa adanya.
        if ($awb !== '') {
            $update['awb'] = $awb;
        }

        $mapped = self::STATUS_MAP[mb_strtolower($statusRaw)] ?? null;
        if ($mapped !== null) {
            $update['status'] = $mapped;
            if ($mapped === 'shipped' && $order->shipped_at === null) {
                $update['shipped_at'] = now();
            }
        }

        $order->update($update);

        // Catat ke riwayat tracking (state ternormalisasi + status mentah Komerce).
        $trackStatus = self::TRACK_MAP[mb_strtolower($statusRaw)] ?? (mb_strtolower($statusRaw) ?: 'update');
        $order->logTracking($trackStatus, 'webhook', [
            'raw_status' => $statusRaw !== '' ? $statusRaw : null,
            'awb' => $awb !== '' ? $awb : null,
        ]);

        return response()->json(['message' => 'ok'], 200);
    }
}
