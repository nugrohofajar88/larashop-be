<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\UserUniqueCode;
use App\Support\ApiData;
use App\Support\KomerceShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminOrderController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::query()
            ->with(['items', 'user'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $orders->map(fn (Order $order) => ApiData::order($order))->values()->all(),
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['items', 'user', 'trackings']);

        return response()->json([
            'data' => ApiData::order($order),
        ]);
    }

    public function validatePayment(Order $order): JsonResponse
    {
        DB::transaction(function () use ($order): void {
            $order->update([
                'status' => 'paid',
                'payment_status' => 'Tervalidasi',
                'paid_at' => now(),
                'shipment_note' => 'Pembayaran tervalidasi. Order siap diproses ke shipment.',
            ]);

            if ($this->usesUniqueCode() && (int) $order->unique_code > 0) {
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

        $order->logTracking('paid', 'admin');

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => $bookingMessage ?? 'Pembayaran berhasil divalidasi.',
        ]);
    }

    public function cancel(Order $order): JsonResponse
    {
        if (! in_array($order->status, ['pending_payment', 'paid', 'processing'], true)) {
            throw ValidationException::withMessages([
                'order' => 'Order ini tidak bisa dibatalkan dari sisi admin.',
            ]);
        }

        DB::transaction(function () use ($order): void {
            UserUniqueCode::query()
                ->where('user_id', $order->user_id)
                ->where('ref_id', $order->id)
                ->whereIn('type', ['paid', 'used'])
                ->delete();

            $order->update([
                'status' => 'cancelled',
                'payment_status' => 'Dibatalkan admin',
                'shipment_note' => 'Order dibatalkan oleh admin dan seluruh penyesuaian saldo sudah dikembalikan.',
            ]);
        });

        $order->logTracking('cancelled', 'admin');

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => 'Order berhasil dibatalkan oleh admin.',
        ]);
    }

    public function processShipment(Order $order): JsonResponse
    {
        // Dipakai untuk transisi processing -> shipped (paket sudah diserahkan ke kurir).
        // AWB diisi saat schedulePickup; di sini hanya update status.
        $order->update([
            'status' => 'shipped',
            'shipment_note' => 'Paket sudah diserahkan ke kurir.'.($order->awb ? ' AWB: '.$order->awb : ''),
            'shipped_at' => $order->shipped_at ?? now(),
        ]);

        $order->logTracking('in_transit', 'admin');

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => 'Shipment berhasil diproses.',
        ]);
    }


    public function schedulePickup(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'pickup_date' => ['required', 'date'],
            'pickup_time' => ['required', 'string', 'max:5'],
            'pickup_vehicle' => ['required', 'in:Motor,Mobil,Truk'],
        ]);

        if (trim((string) $order->komerce_order_no) === '') {
            throw ValidationException::withMessages([
                'order' => 'Order ini belum di-booking ke ekspedisi (tidak ada order_no Komerce).',
            ]);
        }

        $result = app(KomerceShipmentService::class)->requestPickup(
            (string) $order->komerce_order_no,
            $validated['pickup_date'],
            $validated['pickup_time'],
            $validated['pickup_vehicle'],
        );

        if (! ($result['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'pickup' => 'Pickup gagal: '.($result['message'] ?? 'tidak diketahui'),
            ]);
        }

        $awb = (string) ($result['awb'] ?? '');
        $order->update([
            'status' => 'processing',
            'awb' => $awb !== '' ? $awb : $order->awb,
            'shipment_note' => 'Pickup dijadwalkan '.$validated['pickup_date'].' '.$validated['pickup_time'].' ('.$validated['pickup_vehicle'].')'.($awb !== '' ? '. AWB: '.$awb : '.'),
        ]);

        $order->logTracking('pickup_scheduled', 'admin', [
            'awb' => $awb !== '' ? $awb : null,
            'note' => 'Pickup '.$validated['pickup_date'].' '.$validated['pickup_time'].' ('.$validated['pickup_vehicle'].')',
        ]);

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => 'Pickup berhasil dijadwalkan.',
        ]);
    }

    public function complete(Order $order): JsonResponse
    {
        if ($order->status !== 'shipped') {
            throw ValidationException::withMessages([
                'order' => 'Order ini belum bisa ditandai selesai dari sisi admin.',
            ]);
        }

        $order->update([
            'status' => 'completed',
            'shipment_note' => 'Order ditandai selesai oleh admin setelah barang dipastikan diterima customer.',
        ]);

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => 'Order berhasil ditandai selesai.',
        ]);
    }
    private function usesUniqueCode(): bool
    {
        return \App\Models\Setting::uniqueCodeEnabled();
    }
}

