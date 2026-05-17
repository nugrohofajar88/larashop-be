<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\UserUniqueCode;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
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
        $order->load(['items', 'user']);

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

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => 'Pembayaran berhasil divalidasi.',
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

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => 'Order berhasil dibatalkan oleh admin.',
        ]);
    }

    public function processShipment(Order $order): JsonResponse
    {
        $order->update([
            'status' => $order->awb ? 'shipped' : 'processing',
            'awb' => $order->awb ?: 'JNT'.str_pad((string) $order->id, 11, '0', STR_PAD_LEFT),
            'shipment_note' => 'Shipment diproses admin dan pickup dijadwalkan.',
            'shipped_at' => $order->shipped_at ?? now(),
        ]);

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => 'Shipment berhasil diproses.',
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
        return (bool) config('services.checkout.use_unique_code', true);
    }
}

