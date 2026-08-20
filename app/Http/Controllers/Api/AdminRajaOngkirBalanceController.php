<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\QrisGenerationLog;
use App\Models\RajaOngkirTopup;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ledger ESTIMASI saldo deposit RajaOngkir/Komerce - bukan data real-time dari
 * mereka (kita tidak punya akses API saldo mereka), tapi hasil rekonstruksi dari
 * apa yang kita tahu: top up manual (dicatat admin) dikurangi ongkir yang sudah
 * di-booking & biaya generate QRIS, ditambah remitansi COD yang sudah selesai.
 * Dipakai admin buat bandingkan dgn saldo ASLI di dashboard RajaOngkir - kalau
 * selisihnya jauh, berarti ada yang perlu ditelusuri (topup lupa dicatat, dll).
 */
class AdminRajaOngkirBalanceController extends Controller
{
    public function index(): JsonResponse
    {
        $topups = RajaOngkirTopup::query()->orderByDesc('topup_date')->orderByDesc('id')->get();
        $totalTopup = (int) $topups->sum('amount');

        // Ongkir yang beneran kepotong dari saldo - HANYA order NON-COD yang sudah
        // punya AWB (berhasil di-booking). Order COD TIDAK masuk sini - potongan
        // ongkir mereka sudah otomatis ke-netto dalam 1 transaksi "COD Diterima"
        // (lihat $totalCodRemitted di bawah), jadi kalau ikut dihitung di sini akan
        // dobel. Debit yg beneran kepotong = shipping_total DIKURANGI cashback (bukan
        // shipping_total mentah) - tervalidasi cocok persis ke data mutasi asli
        // RajaOngkir (20 Agustus 2026). Kalau order sudah di-flag (shipping_actual_value
        // terisi, dari verifikasi manual ke mutasi asli - lihat shipping_discrepancy_note),
        // pakai angka REAL itu, bukan hasil hitungan shipping_total-cashback yang bisa
        // salah kalau order sempat kena bug snapshot berat produk.
        $nonCodShipped = Order::query()
            ->whereNotNull('awb')->where('awb', '!=', '')
            ->where('payment_method', '!=', 'COD')
            ->get(['shipping_total', 'shipping_cashback', 'shipping_actual_value']);
        $totalOngkir = (int) $nonCodShipped->sum(fn (Order $o): int => $o->shipping_actual_value ?? ($o->shipping_total - $o->shipping_cashback)
        );

        $flaggedDiscrepancies = Order::query()
            ->whereNotNull('shipping_discrepancy_note')
            ->orderByDesc('shipping_reconciled_at')
            ->get(['code', 'shipping_total', 'shipping_cashback', 'shipping_actual_value', 'shipping_discrepancy_note', 'shipping_reconciled_at']);

        $qrisCount = QrisGenerationLog::query()->count();
        $totalQrisFee = (int) QrisGenerationLog::query()->sum('fee');

        // COD yang sudah "completed" dianggap sudah diremit ke saldo deposit -
        // formula sama persis dgn Potential Income (sudah tervalidasi cocok ke
        // dashboard RajaOngkir), bedanya di sini utk order yang SUDAH selesai,
        // bukan yang masih dalam perjalanan.
        $remittedCod = Order::query()
            ->where('payment_method', 'COD')
            ->where('status', 'completed')
            ->get(['grand_total', 'shipping_total', 'cod_service_fee', 'shipping_cashback']);
        $totalCodRemitted = (int) $remittedCod->sum(fn (Order $o): int => $o->grand_total - $o->shipping_total - $o->cod_service_fee + $o->shipping_cashback
        );

        // Dana COD yang masih di jalan - order sudah "paid" (perlu dikirim), "processing"
        // (menunggu penjemputan), atau "shipped" (dalam pengiriman), belum "completed".
        // Ikut dikurangi dari $estimatedBalance (Estimasi Saldo ditampilkan net setelah
        // dikurangi potensi dana yang masih di jalan ini). Formula sama persis dgn
        // Potential Income di AdminAccountingController (tervalidasi cocok ke dashboard
        // RajaOngkir/Komerce), bedanya di sini scope status-nya lebih luas (paid s.d.
        // shipped, bukan cuma shipped) karena mencakup seluruh order COD yang belum diremit.
        $inTransitCod = Order::query()
            ->where('payment_method', 'COD')
            ->whereIn('status', ['paid', 'processing', 'shipped'])
            ->get(['grand_total', 'shipping_total', 'cod_service_fee', 'shipping_cashback']);
        $codInTransit = (int) $inTransitCod->sum(fn (Order $o): int => $o->grand_total - $o->shipping_total - $o->cod_service_fee + $o->shipping_cashback
        );

        $estimatedBalance = $totalTopup - $totalOngkir - $totalQrisFee + $totalCodRemitted - $codInTransit;

        return response()->json([
            'data' => [
                'topups' => $topups->map(fn (RajaOngkirTopup $t): array => [
                    'id' => $t->id,
                    'amount' => ApiData::rupiah((int) $t->amount),
                    'amount_value' => (int) $t->amount,
                    'topup_date' => $t->topup_date->translatedFormat('d M Y'),
                    'note' => $t->note,
                    'created_by' => $t->created_by,
                ])->values()->all(),
                'flagged_discrepancies' => $flaggedDiscrepancies->map(fn (Order $o): array => [
                    'code' => $o->code,
                    'shipping_total' => ApiData::rupiah((int) $o->shipping_total),
                    'shipping_cashback' => ApiData::rupiah((int) $o->shipping_cashback),
                    'shipping_actual_value' => ApiData::rupiah((int) $o->shipping_actual_value),
                    'note' => $o->shipping_discrepancy_note,
                    'reconciled_at' => $o->shipping_reconciled_at?->translatedFormat('d M Y'),
                ])->values()->all(),
            ],
            'meta' => [
                'total_topup' => ApiData::rupiah($totalTopup),
                'total_topup_value' => $totalTopup,
                'total_ongkir' => ApiData::rupiah($totalOngkir),
                'total_ongkir_value' => $totalOngkir,
                'total_qris_fee' => ApiData::rupiah($totalQrisFee),
                'total_qris_fee_value' => $totalQrisFee,
                'qris_count' => $qrisCount,
                'total_cod_remitted' => ApiData::rupiah($totalCodRemitted),
                'total_cod_remitted_value' => $totalCodRemitted,
                'cod_in_transit' => ApiData::rupiah($codInTransit),
                'cod_in_transit_value' => $codInTransit,
                'cod_in_transit_count' => $inTransitCod->count(),
                'estimated_balance' => ApiData::rupiah($estimatedBalance),
                'estimated_balance_value' => $estimatedBalance,
                'flagged_discrepancies_count' => $flaggedDiscrepancies->count(),
            ],
        ]);
    }

    public function storeTopup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'topup_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        RajaOngkirTopup::query()->create([
            'amount' => $validated['amount'],
            'topup_date' => $validated['topup_date'],
            'note' => $validated['note'] ?? null,
            'created_by' => (string) ($request->user()->name ?? 'Admin'),
        ]);

        return response()->json(['message' => 'Top up berhasil dicatat.']);
    }

    public function destroyTopup(RajaOngkirTopup $topup): JsonResponse
    {
        $topup->delete();

        return response()->json(['message' => 'Catatan top up dihapus.']);
    }

    /**
     * Sinkronisasi total biaya generate QRIS dari file mutasi RajaOngkir/Komerce
     * (CSV, kolom: Tanggal, Jenis Transaksi, Resi, Mutasi, Debit/Credit, Saldo,
     * Detail - hasil export dashboard mereka). Cuma proses baris "generate_qris".
     * Dedup berdasarkan timestamp persis (created_at) supaya upload ulang file yang
     * rentang tanggalnya overlap tidak dobel-catat.
     */
    public function syncQris(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'File tidak bisa dibaca.'], 422);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return response()->json(['message' => 'File kosong atau format tidak dikenali.'], 422);
        }

        $tanggalIdx = array_search('Tanggal', $header, true);
        $jenisIdx = array_search('Jenis Transaksi', $header, true);
        $mutasiIdx = array_search('Mutasi', $header, true);

        if ($tanggalIdx === false || $jenisIdx === false || $mutasiIdx === false) {
            fclose($handle);

            return response()->json(['message' => 'Kolom "Tanggal", "Jenis Transaksi", atau "Mutasi" tidak ditemukan di file.'], 422);
        }

        $existingTimestamps = QrisGenerationLog::query()->pluck('created_at')
            ->map(fn ($t) => $t->format('Y-m-d H:i:s'))
            ->flip();

        $added = 0;
        $skipped = 0;
        $addedFee = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (($row[$jenisIdx] ?? null) !== 'generate_qris') {
                continue;
            }

            $timestamp = \Carbon\Carbon::parse($row[$tanggalIdx], 'Asia/Jakarta');
            $key = $timestamp->format('Y-m-d H:i:s');

            if (isset($existingTimestamps[$key])) {
                $skipped++;

                continue;
            }

            $fee = (int) $row[$mutasiIdx];

            $log = new QrisGenerationLog();
            $log->fee = $fee;
            $log->created_at = $timestamp;
            $log->updated_at = $timestamp;
            $log->save();

            $existingTimestamps[$key] = true;
            $added++;
            $addedFee += $fee;
        }

        fclose($handle);

        return response()->json([
            'message' => "Sinkronisasi selesai: {$added} baris baru ditambahkan (Rp".number_format($addedFee, 0, ',', '.').'), '."{$skipped} baris dilewati karena sudah tercatat.",
            'meta' => ['added' => $added, 'skipped' => $skipped, 'added_fee_value' => $addedFee],
        ]);
    }
}
