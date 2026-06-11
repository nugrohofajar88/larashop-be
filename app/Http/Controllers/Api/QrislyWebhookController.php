<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderPaymentService;
use App\Support\QrislyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook QRISLY — notifikasi pembayaran real-time.
 * Event: payment.success / payment.expired.
 * Payload: { event, data: { history_id(UUID), qris_id, amount(final), status, paid_at, ... } }.
 *
 * Catatan: webhook.data.history_id BERTIPE UUID (≠ history_id int dari generate yang kita
 * simpan), jadi order dicocokkan via `qris_amount` (final, unik di window aktif) lalu
 * DI-VERIFIKASI ULANG via payment-status (butuh API key kita → anti-spoof) sebelum markPaid.
 */
class QrislyWebhookController extends Controller
{
    public function handle(Request $request, ?string $secret = null): JsonResponse
    {
        $expected = trim((string) config('services.qrisly.webhook_secret'));
        $provided = ($secret !== null && $secret !== '') ? $secret : (string) $request->query('secret');
        if ($expected !== '' && $provided !== $expected) {
            Log::warning('qrisly.webhook.unauthorized', ['ip' => $request->ip()]);

            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $event = (string) $request->input('event');
        $data = (array) $request->input('data', []);
        $status = (string) ($data['status'] ?? '');
        $amount = (int) ($data['amount'] ?? 0);

        Log::info('qrisly.webhook.received', [
            'event' => $event, 'status' => $status, 'amount' => $amount,
            'history_id' => $data['history_id'] ?? null,
        ]);

        // Selain pembayaran sukses (mis. expired) → cukup catat & balas 200.
        if ($event !== 'payment.success' && $status !== 'paid') {
            if ($status === 'expired' && $amount > 0) {
                Order::where('qris_amount', $amount)->where('qris_status', 'unpaid')
                    ->latest('id')->first()?->update(['qris_status' => 'expired']);
            }

            return response()->json(['success' => true, 'message' => 'Webhook received'], 200);
        }

        // Cocokkan order via final_amount (unik di window aktif) + masih menunggu bayar.
        $order = Order::where('qris_amount', $amount)
            ->where('status', 'pending_payment')
            ->latest('id')->first();

        if ($order === null) {
            Log::warning('qrisly.webhook.order_not_found', ['amount' => $amount]);

            return response()->json(['success' => true, 'message' => 'Order tidak ditemukan / sudah diproses'], 200);
        }

        // Anti-spoof: verifikasi ulang ke QRISLY pakai history_id KITA (int).
        $verify = app(QrislyService::class)->paymentStatus((string) $order->qris_history_id);
        if (! ($verify['ok'] ?? false) || ($verify['payment_status'] ?? '') !== 'paid') {
            Log::warning('qrisly.webhook.verify_failed', ['order' => $order->code, 'verify' => $verify]);

            return response()->json(['success' => true, 'message' => 'Belum terverifikasi paid'], 200);
        }

        $order->update(['qris_status' => 'paid']);
        app(OrderPaymentService::class)->markPaid($order, 'qrisly');

        Log::info('qrisly.webhook.paid', ['order' => $order->code]);

        return response()->json(['success' => true, 'message' => 'Webhook received and processed'], 200);
    }
}
