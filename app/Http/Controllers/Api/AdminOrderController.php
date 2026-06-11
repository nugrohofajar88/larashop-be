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
        $result = app(\App\Support\OrderPaymentService::class)->markPaid($order, 'admin');

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => $result['message'],
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

    public function printLabel(Order $order): \Illuminate\Http\Response
    {
        if (trim((string) $order->komerce_order_no) === '') {
            throw ValidationException::withMessages([
                'order' => 'Order ini belum di-booking ke ekspedisi (tidak ada order_no Komerce).',
            ]);
        }

        $result = app(KomerceShipmentService::class)->printLabel((string) $order->komerce_order_no);

        if (! ($result['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'label' => 'Gagal mengambil label: '.($result['message'] ?? 'tidak diketahui'),
            ]);
        }

        return response($result['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.($result['filename'] ?? 'label.pdf').'"',
        ]);
    }

    /**
     * Label DIY (dibuat sendiri pakai dompdf + barcode), TERPISAH dari label resmi
     * Komerce. Untuk evaluasi layout; kalau tak dipakai tinggal hapus method + route.
     */
    public function printLabelDiy(Order $order): \Illuminate\Http\Response
    {
        $order->loadMissing('items');

        $origin = \App\Models\ShipmentOrigin::query()
            ->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();
        $address = \App\Models\CustomerAddress::query()->find($order->customer_address_id);

        $weightGrams = (int) $order->items->sum(fn ($i): int => (int) ($i->weight_grams ?: 0) * (int) $i->quantity);
        $weightKg = number_format(max($weightGrams, 1) / 1000, 2);

        $region = $address ? trim(implode(', ', array_filter([
            $address->subdistrict, $address->district, $address->city, $address->postal_code,
        ]))) : '';

        $awb = (string) ($order->awb ?: '');
        $barcode = '';
        if ($awb !== '') {
            $barcode = (new \Picqer\Barcode\BarcodeGeneratorHTML())
                ->getBarcode($awb, \Picqer\Barcode\BarcodeGeneratorHTML::TYPE_CODE_128, 2, 48);
        }

        $logoFile = public_path('img/label-logo.png');
        $logo = is_file($logoFile)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoFile))
            : '';

        $data = [
            'logo' => $logo,
            'brand' => (string) \App\Models\Setting::get('store_brand', 'Akar Tani Kimia'),
            'courier' => strtoupper((string) ($order->shipping_courier_code ?: '-')),
            'service' => (string) ($order->shipping_service_code ?: ($order->shipping_service_name ?: '-')),
            'awb' => $awb !== '' ? $awb : 'Belum ada resi',
            'barcode' => $barcode,
            'weight' => $weightKg,
            'totalQty' => (int) $order->items->sum('quantity'),
            'sender' => [
                'name' => (string) ($origin->contact_name ?? '-'),
                'phone' => (string) ($origin->contact_phone ?? '-'),
                'address' => (string) ($origin->address_line ?? '-'),
            ],
            'receiver' => [
                'name' => (string) ($order->recipient_name ?: ($address->recipient_name ?? '-')),
                'phone' => (string) ($order->recipient_phone ?: ($address->recipient_phone ?? '-')),
                'address' => (string) ($address->address_line ?? '-'),
                'region' => $region,
            ],
            'items' => $order->items->map(fn ($i): array => [
                'qty' => (int) $i->quantity,
                'name' => trim($i->product_name.' '.($i->variant_label ? '('.$i->variant_label.')' : '')),
            ])->all(),
            'note' => (string) ($order->note ?? ''),
            'orderId' => (string) ($order->komerce_order_no ?: '-'),
            'code' => (string) $order->code,
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('labels.diy', $data)
            ->setPaper([0, 0, 283.465, 425.197]); // 100x150mm dalam point

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="label-diy-'.$order->code.'.pdf"',
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
}

