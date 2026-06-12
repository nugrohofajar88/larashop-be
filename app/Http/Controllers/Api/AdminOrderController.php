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
        // 'draft' = order belum disubmit pelanggan (keranjang/checkout belum selesai),
        // jangan tampilkan di daftar pesanan admin.
        $orders = Order::query()
            ->with(['items', 'user'])
            ->where('status', '!=', 'draft')
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
            'pickup_date' => ['required', 'date', 'after_or_equal:today'],
            'pickup_time' => ['required', 'string', 'max:5'],
            'pickup_vehicle' => ['required', 'in:Motor,Mobil,Truk'],
        ]);

        $this->assertPickupNotInPast($validated['pickup_date'], $validated['pickup_time']);

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

    /** Jadwalkan pickup BANYAK order sekaligus (1 request ke Komerce). */
    public function schedulePickupBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_codes' => ['required', 'array', 'min:1'],
            'order_codes.*' => ['string'],
            'pickup_date' => ['required', 'date', 'after_or_equal:today'],
            'pickup_time' => ['required', 'string', 'max:5'],
            'pickup_vehicle' => ['required', 'in:Motor,Mobil,Truk'],
        ]);

        $this->assertPickupNotInPast($validated['pickup_date'], $validated['pickup_time']);

        // Hanya order PAID (belum dijemput) yang boleh dijadwalkan pickup. Order
        // 'processing' (sudah dijemput, punya AWB) sengaja dikecualikan supaya tak
        // ikut ter-pickup ulang saat admin "select all".
        $orders = Order::query()
            ->whereIn('code', $validated['order_codes'])
            ->where('status', 'paid')
            ->whereNotNull('komerce_order_no')
            ->where('komerce_order_no', '!=', '')
            ->get();

        if ($orders->isEmpty()) {
            throw ValidationException::withMessages([
                'order_codes' => 'Tidak ada order berstatus "paid" (siap dijemput) di antara yang dipilih.',
            ]);
        }

        $orderNos = $orders->pluck('komerce_order_no')->map(fn ($v): string => (string) $v)->all();

        $result = app(KomerceShipmentService::class)->requestPickupBulk(
            $orderNos,
            $validated['pickup_date'],
            $validated['pickup_time'],
            $validated['pickup_vehicle'],
        );

        if (! ($result['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'pickup' => 'Pickup gagal: '.($result['message'] ?? 'tidak diketahui'),
            ]);
        }

        $results = (array) ($result['results'] ?? []);
        $note = 'Pickup dijadwalkan '.$validated['pickup_date'].' '.$validated['pickup_time'].' ('.$validated['pickup_vehicle'].')';
        $success = [];
        $failed = [];

        foreach ($orders as $order) {
            $r = $results[(string) $order->komerce_order_no] ?? null;
            $awb = (string) ($r['awb'] ?? '');

            if ($r !== null && ($r['status'] ?? '') === 'success') {
                $order->update([
                    'status' => 'processing',
                    'awb' => $awb !== '' ? $awb : $order->awb,
                    'shipment_note' => $note.($awb !== '' ? '. AWB: '.$awb : '.'),
                ]);
                $order->logTracking('pickup_scheduled', 'admin', [
                    'awb' => $awb !== '' ? $awb : null,
                    'note' => $note,
                ]);
                $success[] = $order->code;
            } else {
                $failed[] = $order->code;
            }
        }

        return response()->json([
            'message' => 'Pickup diproses: '.count($success).' berhasil'.($failed !== [] ? ', '.count($failed).' gagal' : '').'.',
            'summary' => ['success' => $success, 'failed' => $failed],
        ]);
    }

    /** Cetak label BANYAK order sekaligus: ambil tiap label dari Komerce lalu GABUNG jadi 1 PDF. */
    public function printLabelsBulk(Request $request): \Illuminate\Http\Response
    {
        $validated = $request->validate([
            'order_codes' => ['required', 'array', 'min:1'],
            'order_codes.*' => ['string'],
        ]);

        // Hanya order yang sudah di-booking (punya komerce_order_no) yang ada labelnya.
        $orders = Order::query()
            ->whereIn('code', $validated['order_codes'])
            ->whereNotNull('komerce_order_no')
            ->where('komerce_order_no', '!=', '')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            throw ValidationException::withMessages([
                'order_codes' => 'Tidak ada order ber-label (sudah di-booking) di antara yang dipilih.',
            ]);
        }

        // Longgarkan batas eksekusi PHP; tetap dibatasi Connection Timeout web server.
        @set_time_limit(0);

        $svc = app(KomerceShipmentService::class);

        // Ambil semua label PARALEL (bukan satu-per-satu) supaya total cepat dan
        // tidak kena Request Timeout server. Hasil dipetakan per komerce_order_no.
        $labels = $svc->printLabelsConcurrent(
            $orders->pluck('komerce_order_no')->map(fn ($n) => (string) $n)->all()
        );

        $merger = new \setasign\Fpdi\Fpdi();
        $added = 0;
        $failed = [];

        foreach ($orders as $order) {
            $no = (string) $order->komerce_order_no;
            $res = $labels[$no] ?? null;

            // Fallback: kalau versi paralel gagal (mis. Komerce menolak request
            // bersamaan), coba sekali lagi berurutan via jalur single yang known-good.
            if (! is_array($res) || ! ($res['ok'] ?? false) || empty($res['pdf'])) {
                @set_time_limit(60);
                $res = $svc->printLabel($no);
            }

            if (! ($res['ok'] ?? false) || empty($res['pdf'])) {
                $failed[$order->code] = (string) ($res['message'] ?? 'tidak diketahui');

                continue;
            }

            try {
                $pages = $merger->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($res['pdf']));
                for ($i = 1; $i <= $pages; $i++) {
                    $tpl = $merger->importPage($i);
                    $size = $merger->getTemplateSize($tpl);
                    $merger->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $merger->useTemplate($tpl);
                }
                $added++;
            } catch (\Throwable $e) {
                $failed[$order->code] = 'gabung PDF gagal: '.$e->getMessage();
            }
        }

        if ($added === 0) {
            $detail = collect($failed)
                ->map(fn (string $msg, string $code): string => $code.' ('.$msg.')')
                ->implode('; ');

            throw ValidationException::withMessages([
                'order_codes' => 'Gagal mengambil label: '.($detail !== '' ? $detail : 'tidak ada order valid').'.',
            ]);
        }

        return response((string) $merger->Output('S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="labels-'.$added.'.pdf"',
        ]);
    }

    /** Tandai BANYAK order (status processing) jadi "shipped" sekaligus. */
    public function markShippedBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_codes' => ['required', 'array', 'min:1'],
            'order_codes.*' => ['string'],
        ]);

        $orders = Order::query()
            ->whereIn('code', $validated['order_codes'])
            ->where('status', 'processing')
            ->get();

        if ($orders->isEmpty()) {
            throw ValidationException::withMessages([
                'order_codes' => 'Tidak ada order berstatus "processing" untuk ditandai dikirim.',
            ]);
        }

        $shipped = [];
        foreach ($orders as $order) {
            $order->update([
                'status' => 'shipped',
                'shipment_note' => 'Paket sudah diserahkan ke kurir.'.($order->awb ? ' AWB: '.$order->awb : ''),
                'shipped_at' => $order->shipped_at ?? now(),
            ]);
            $order->logTracking('in_transit', 'admin');
            $shipped[] = $order->code;
        }

        return response()->json([
            'message' => count($shipped).' order ditandai dikirim.',
            'summary' => ['success' => $shipped],
        ]);
    }

    /** Pastikan jadwal pickup (tanggal+jam, WIB) tidak di masa lalu. */
    private function assertPickupNotInPast(string $date, string $time): void
    {
        try {
            $when = \Illuminate\Support\Carbon::parse($date.' '.$time, 'Asia/Jakarta');
        } catch (\Throwable $e) {
            return; // format aneh — biar rule lain yang menangani.
        }

        if ($when->isBefore(now())) {
            throw ValidationException::withMessages([
                'pickup_time' => 'Jadwal pickup tidak boleh di waktu yang sudah lewat.',
            ]);
        }
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

