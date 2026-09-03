<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\UserUniqueCode;
use App\Support\ApiData;
use App\Support\KomerceShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());
        $perPage = (int) $request->integer('per_page', 20);

        // 'draft' = order belum disubmit pelanggan (keranjang/checkout belum selesai),
        // jangan tampilkan di daftar pesanan admin.
        $query = Order::query()
            ->with(['items', 'user'])
            ->where('status', '!=', 'draft');

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('recipient_phone', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        $paginator = $query->orderByDesc('id')->paginate($perPage);

        // Hitungan per status TIDAK ikut kena filter status/search - dipakai utk badge
        // jumlah di tab status & kartu statistik (harus tetap tunjukkan total global,
        // bukan cuma yang kebetulan cocok dgn filter/halaman saat ini).
        $statusCounts = Order::query()
            ->where('status', '!=', 'draft')
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Order $order) => $this->orderWithAdminExtras($order))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'status_counts' => $statusCounts,
                'total_count' => (int) $statusCounts->sum(),
            ],
        ]);
    }

    /**
     * Export data penjualan sebagai CSV: marketplace, order_no, resi, sku, nama_produk, qty.
     * Semua order KECUALI cancelled & draft (draft = keranjang belum disubmit, bukan
     * penjualan) - order yang belum punya AWB tetap ikut, kolom resi dikosongkan.
     * Satu baris per order_item (bukan per order) supaya sku & qty presisi.
     * 'marketplace' selalu 'Website' - larashop cuma satu channel, bukan
     * aggregator multi-marketplace seperti Shopee/TikTok Shop.
     */
    public function export(Request $request): \Illuminate\Http\Response
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $orders = Order::query()
            ->with('items')
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->when(! empty($validated['date_from']), fn ($q) => $q->where('created_at', '>=', Carbon::parse($validated['date_from'])->startOfDay()))
            ->when(! empty($validated['date_to']), fn ($q) => $q->where('created_at', '<=', Carbon::parse($validated['date_to'])->endOfDay()))
            ->orderBy('created_at')
            ->get();

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['marketplace', 'order_no', 'resi', 'sku', 'nama_produk', 'qty']);

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                fputcsv($csv, [
                    'Website',
                    $order->code,
                    $order->awb,
                    $item->product_sku,
                    trim($item->product_name.($item->variant_label ? ' ('.$item->variant_label.')' : '')),
                    $item->quantity,
                ]);
            }
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        $filename = 'orders-penjualan-'.now()->format('Y-m-d_His').'.csv';

        return response($content, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['items', 'user', 'trackings']);

        return response()->json([
            'data' => $this->orderWithAdminExtras($order),
        ]);
    }

    /**
     * Cari order via kode (bukan ID numerik) - dipakai FE (findOrderByCode) supaya
     * tidak perlu fetch seluruh daftar order (yang sekarang di-paging) cuma buat
     * resolve kode -> ID.
     */
    public function showByCode(string $code): JsonResponse
    {
        $order = Order::query()->where('code', strtoupper($code))->firstOrFail();
        $order->load(['items', 'user', 'trackings']);

        return response()->json([
            'data' => $this->orderWithAdminExtras($order),
        ]);
    }

    /**
     * ApiData::order() dipakai bersama customer (OrderController) — field yang
     * sensitif secara bisnis (mis. biaya jasa COD yang kita tanggung) TIDAK
     * boleh ikut di sana, jadi ditambahkan terpisah di sini, admin-only.
     */
    protected function orderWithAdminExtras(Order $order): array
    {
        return ApiData::order($order) + [
            'cod_service_fee' => (int) $order->cod_service_fee,
            'is_printed' => $order->printed_at !== null,
            'printed_label' => $order->printed_at
                ? 'Dicetak '.$order->printed_at->translatedFormat('d M Y, H:i').' oleh '.($order->printed_by ?: '-')
                : 'Belum dicetak',
        ];
    }

    /**
     * Perbaiki nama/nomor HP penerima - dibatasi cuma untuk order yang belum
     * di-booking ke ekspedisi (AWB masih kosong), supaya tidak mismatch dgn
     * data yang sudah terlanjur dikirim ke Komerce/kurir. Kasus nyata: booking
     * gagal karena "receiver phone number invalid" (customer salah ketik saat
     * checkout, mis. cuma "88") dan admin butuh cara memperbaikinya tanpa
     * minta customer batalkan & order ulang.
     */
    public function updateRecipient(Request $request, Order $order): JsonResponse
    {
        if (trim((string) $order->awb) !== '') {
            throw ValidationException::withMessages([
                'order' => 'Order ini sudah punya AWB - data penerima tidak boleh diubah lagi (sudah dikirim ke ekspedisi).',
            ]);
        }

        $validated = $request->validate([
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
        ]);

        $phoneDigits = preg_replace('/\D/', '', $validated['recipient_phone']);
        if (strlen($phoneDigits) < 9) {
            throw ValidationException::withMessages([
                'recipient_phone' => 'Nomor HP penerima terlalu pendek/tidak valid.',
            ]);
        }

        $order->update([
            'recipient_name' => $validated['recipient_name'],
            'recipient_phone' => $validated['recipient_phone'],
        ]);

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => $this->orderWithAdminExtras($order),
            'message' => 'Data penerima berhasil diperbarui.',
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

    /**
     * Coba lagi booking ke Komerce untuk order yang sudah dibayar tapi gagal
     * di-booking (mis. shipping_cost sempat tidak match karena harga ongkir
     * live berubah). Aman dipanggil berkali-kali - hanya proses kalau order
     * belum punya komerce_order_no.
     */
    public function retryBooking(Order $order): JsonResponse
    {
        if (! in_array($order->status, ['paid', 'processing'], true)) {
            throw ValidationException::withMessages([
                'order' => 'Order ini belum dibayar, belum bisa di-booking ke ekspedisi.',
            ]);
        }

        if (trim((string) $order->komerce_order_no) !== '') {
            throw ValidationException::withMessages([
                'order' => 'Order ini sudah di-booking ke ekspedisi ('.$order->komerce_order_no.').',
            ]);
        }

        $komerce = app(KomerceShipmentService::class);

        if (! $komerce->enabled()) {
            throw ValidationException::withMessages([
                'order' => 'Integrasi Komerce sedang tidak aktif.',
            ]);
        }

        $result = $komerce->createOrder($order);
        $label = $order->payment_method === 'COD' ? 'Pesanan COD dikonfirmasi' : 'Pembayaran tervalidasi';

        if ($result['ok']) {
            $order->update([
                'komerce_order_no' => $result['order_no'] ?? null,
                'komerce_order_id' => $result['order_id'] ?? null,
                'cod_service_fee' => (int) ($result['service_fee'] ?? 0),
                'shipment_note' => $label.'. Order ekspedisi dibuat: '.($result['order_no'] ?? '-').'.',
            ]);
        } else {
            $order->update([
                'shipment_note' => $label.', tapi booking ekspedisi GAGAL: '.($result['message'] ?? 'tidak diketahui').'. Bisa dicoba ulang.',
            ]);
        }

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => $result['ok']
                ? 'Booking ekspedisi berhasil: '.$order->komerce_order_no
                : 'Booking ekspedisi masih gagal: '.($result['message'] ?? 'tidak diketahui'),
        ]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        if (! in_array($order->status, ['pending_payment', 'paid', 'processing'], true)) {
            throw ValidationException::withMessages([
                'order' => 'Order ini tidak bisa dibatalkan dari sisi admin.',
            ]);
        }

        $reason = trim((string) $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ])['reason'] ?? '');

        // Kalau order sudah di-booking ke Komerce, batalkan juga di sana supaya kurir
        // tidak tetap menjemput (ghost order). Komerce menolak (422) bila sudah "Dikirim".
        $komerceWarning = null;
        $komerceOrderNo = trim((string) $order->komerce_order_no);

        if ($komerceOrderNo !== '') {
            $svc = app(KomerceShipmentService::class);

            if ($svc->enabled()) {
                $cancel = $svc->cancelOrder($komerceOrderNo);

                if (! ($cancel['ok'] ?? false)) {
                    // 422 = order sudah diproses/dikirim kurir -> JANGAN batalkan lokal.
                    if ((int) ($cancel['code'] ?? 0) === 422) {
                        throw ValidationException::withMessages([
                            'order' => 'Tidak bisa dibatalkan: di Komerce order sudah diproses kurir ('.($cancel['message'] ?? 'shipped').').',
                        ]);
                    }

                    // Gagal non-422 (mis. jaringan) -> tetap batalkan lokal, tapi beri peringatan
                    // agar admin menutup order manual di dashboard Komerce.
                    $komerceWarning = 'Pembatalan di Komerce GAGAL ('.($cancel['message'] ?? 'tidak diketahui').'). Mohon batalkan manual di dashboard Komerce untuk order '.$komerceOrderNo.'.';
                }
            }
        }

        // Simpan sebelum di-update: dipakai untuk isi email/WA pembatalan di bawah.
        // COD yang statusnya "paid" itu cuma berarti "dikonfirmasi" - uang belum benar-benar
        // diterima (baru dibayar saat barang sampai), jadi TIDAK butuh pesan "refund".
        $wasAlreadyPaid = in_array($order->status, ['paid', 'processing'], true)
            && $order->payment_method !== 'COD';

        DB::transaction(function () use ($order, $komerceWarning, $reason): void {
            UserUniqueCode::query()
                ->where('user_id', $order->user_id)
                ->where('ref_id', $order->id)
                ->whereIn('type', ['paid', 'used'])
                ->delete();

            // Kembalikan stok yang sempat dipotong saat order dibuat.
            app(\App\Support\StockService::class)->releaseForOrder($order);

            $order->update([
                'status' => 'cancelled',
                'payment_status' => 'Dibatalkan admin',
                'shipment_note' => 'Order dibatalkan oleh admin dan seluruh penyesuaian saldo sudah dikembalikan.'.($komerceWarning ? ' ⚠️ '.$komerceWarning : ''),
                'cancel_requested_at' => null,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);
        });

        $order->logTracking('cancelled', 'admin', $komerceWarning ? ['note' => $komerceWarning] : []);

        $order->refresh()->load(['items', 'user']);

        app(\App\Support\OrderCancellationNotifier::class)->send($order, $wasAlreadyPaid);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => 'Order berhasil dibatalkan oleh admin.'.($komerceWarning ? ' (Catatan: '.$komerceWarning.')' : ''),
        ]);
    }

    /** Tolak permintaan pembatalan customer (order tetap berjalan, flag dihapus). */
    public function rejectCancellation(Order $order): JsonResponse
    {
        if ($order->cancel_requested_at === null) {
            throw ValidationException::withMessages([
                'order' => 'Order ini tidak punya permintaan pembatalan.',
            ]);
        }

        $order->update([
            'cancel_requested_at' => null,
            'shipment_note' => 'Permintaan pembatalan ditolak admin. Pesanan tetap diproses.',
        ]);

        $order->refresh()->load(['items', 'user']);

        return response()->json([
            'data' => ApiData::order($order),
            'message' => 'Permintaan pembatalan ditolak. Pesanan tetap berjalan.',
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

        // Batas wajar untuk satu call gabungan (panjang URL + waktu generate server).
        $maxLabels = 20;
        if ($orders->count() > $maxLabels) {
            throw ValidationException::withMessages([
                'order_codes' => 'Maksimal '.$maxLabels.' label per cetak (kamu pilih '.$orders->count().' order ber-label). Kurangi dulu pilihannya.',
            ]);
        }

        @set_time_limit(0);

        $svc = app(KomerceShipmentService::class);
        $orderNos = $orders->pluck('komerce_order_no')->map(fn ($n) => (string) $n)->all();
        $expected = count($orderNos);

        // Jalur utama: SATU call gabungan (order_no dipisah koma). Komerce kembalikan
        // PDF berisi semua label (1 label = 1 halaman). Verifikasi via FPDI: kalau
        // jumlah halaman >= jumlah order, berarti lengkap dan langsung dikirim.
        $combined = $svc->printLabelCombined($orderNos);
        if (($combined['ok'] ?? false) && ! empty($combined['pdf'])) {
            try {
                $probe = new \setasign\Fpdi\Fpdi();
                $pages = $probe->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($combined['pdf']));
            } catch (\Throwable $e) {
                $pages = 0;
            }

            if ($pages >= $expected) {
                $this->markPrinted($orders, $request);

                return response((string) $combined['pdf'], 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="labels-'.$expected.'.pdf"',
                ]);
            }

            \Illuminate\Support\Facades\Log::warning('komerce.print_label.combined_incomplete', [
                'orders' => $expected,
                'pages' => $pages,
            ]);
        }

        // Fallback: kalau call gabungan gagal/tidak lengkap, ambil per-order PARALEL
        // lalu gabung sendiri via FPDI. Hasil dipetakan per komerce_order_no.
        $labels = $svc->printLabelsConcurrent($orderNos);

        $merger = new \setasign\Fpdi\Fpdi();
        $added = 0;
        $failed = [];
        $printedOrders = collect();

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
                $printedOrders->push($order);
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

        $this->markPrinted($printedOrders, $request);

        return response((string) $merger->Output('S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="labels-'.$added.'.pdf"',
        ]);
    }

    /** Tandai order (satu/banyak) sebagai sudah dicetak labelnya. */
    private function markPrinted(\Illuminate\Support\Collection $orders, Request $request): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        Order::query()->whereIn('id', $orders->pluck('id'))->update([
            'printed_at' => now(),
            'printed_by' => (string) ($request->user()->name ?? 'Admin'),
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

    public function printLabel(Request $request, Order $order): \Illuminate\Http\Response
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

        $this->markPrinted(collect([$order]), $request);

        return response($result['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.($result['filename'] ?? 'label.pdf').'"',
        ]);
    }

    /**
     * Label DIY (dibuat sendiri pakai dompdf + barcode), TERPISAH dari label resmi
     * Komerce. Untuk evaluasi layout; kalau tak dipakai tinggal hapus method + route.
     */
    public function printLabelDiy(Request $request, Order $order): \Illuminate\Http\Response
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
                ->getBarcode($awb, \Picqer\Barcode\BarcodeGeneratorHTML::TYPE_CODE_128, 2, 32);
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
                'city' => (string) ($origin->city ?? ''),
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

        $this->markPrinted(collect([$order]), $request);

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

