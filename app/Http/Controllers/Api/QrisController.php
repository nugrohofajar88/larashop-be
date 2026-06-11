<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderPaymentService;
use App\Support\QrislyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QrisController extends Controller
{
    /** Customer: generate (atau reuse) QRIS untuk order miliknya. */
    public function generate(Request $request, string $code): JsonResponse
    {
        $order = Order::where('code', $code)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($order->status !== 'pending_payment') {
            return response()->json(['message' => 'Order ini tidak menunggu pembayaran.'], 422);
        }

        $svc = app(QrislyService::class);
        if (! $svc->enabled()) {
            return response()->json(['message' => 'Pembayaran QRIS belum aktif.'], 422);
        }

        $res = $svc->generateForOrder($order);
        if (! ($res['ok'] ?? false)) {
            return response()->json(['message' => $res['message'] ?? 'Gagal membuat QRIS.'], 422);
        }

        $order->refresh();

        return response()->json(['data' => [
            'qris_string' => $res['qris_string'],
            'qris_image' => $svc->qrImageDataUri($order),
            'amount' => $res['final_amount'],
            'expired_at' => optional($res['expired_at'] ?? null)?->toIso8601String(),
            'status' => $order->qris_status,
            'reused' => $res['reused'] ?? false,
        ]]);
    }

    /** Customer: cek status pembayaran. Sekaligus finalisasi (fallback webhook). */
    public function status(Request $request, string $code): JsonResponse
    {
        $order = Order::where('code', $code)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Sudah lewat pending_payment → sudah dibayar/diproses.
        if ($order->status !== 'pending_payment') {
            return response()->json(['data' => ['payment_status' => 'paid', 'order_status' => $order->status]]);
        }

        if (trim((string) $order->qris_history_id) === '') {
            return response()->json(['data' => ['payment_status' => 'none', 'order_status' => $order->status]]);
        }

        $res = app(QrislyService::class)->paymentStatus((string) $order->qris_history_id);

        // Polling jadi fallback webhook: kalau QRISLY bilang paid → finalisasi.
        if (($res['ok'] ?? false) && ($res['payment_status'] ?? '') === 'paid' && $order->status === 'pending_payment') {
            $order->update(['qris_status' => 'paid']);
            app(OrderPaymentService::class)->markPaid($order, 'qrisly');
            $order->refresh();
        }

        return response()->json(['data' => [
            'payment_status' => $order->status === 'pending_payment' ? ($res['payment_status'] ?? 'unpaid') : 'paid',
            'order_status' => $order->status,
        ]]);
    }

    /** Admin: upload QRIS statis (sekali). Simpan qris_id ke Setting. */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'qris_image' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:5120'],
        ]);

        $res = app(QrislyService::class)->uploadQris(
            $request->file('qris_image')->getRealPath(),
            $validated['name'],
        );

        if (! ($res['ok'] ?? false)) {
            return response()->json(['message' => $res['message'] ?? 'Upload QRIS gagal.'], 422);
        }

        return response()->json([
            'data' => $res['data'] ?? [],
            'qris_id' => $res['qris_id'] ?? '',
            'message' => 'QRIS berhasil di-upload. qris_id tersimpan.',
        ]);
    }
}
