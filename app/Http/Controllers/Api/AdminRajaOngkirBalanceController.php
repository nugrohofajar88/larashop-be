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

        $estimatedBalance = $totalTopup - $totalOngkir - $totalQrisFee + $totalCodRemitted;

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
}
