<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminAccountingController extends Controller
{
    /** Status yang dihitung sebagai transaksi nyata (uangnya beneran masuk). */
    private const PAID_STATUSES = ['paid', 'processing', 'shipped', 'completed'];

    /** Mapping filter payment_method (query) -> nilai asli kolom orders.payment_method. */
    private const PAYMENT_METHODS = [
        'cod' => 'COD',
        'transfer' => 'Transfer manual',
        'qris' => 'QRIS',
    ];

    public function index(Request $request): JsonResponse
    {
        $month = trim((string) $request->query('month', ''));
        // seller (default) = cashback ongkir tetap jadi keuntungan penjual.
        // buyer = cashback dikasihkan ke pembeli, jadi TIDAK ikut nambah net penjual.
        $mode = $request->query('mode') === 'buyer' ? 'buyer' : 'seller';
        $paymentMethod = $request->query('payment_method', 'all');
        if (! array_key_exists($paymentMethod, self::PAYMENT_METHODS)) {
            $paymentMethod = 'all';
        }

        try {
            $anchor = $month !== '' ? Carbon::createFromFormat('Y-m', $month) : Carbon::now();
        } catch (\Throwable) {
            $anchor = Carbon::now();
        }

        $start = $anchor->copy()->startOfMonth();
        $end = $anchor->copy()->endOfMonth();

        // Tanggal acuan = kapan order dianggap "terjadi" secara akuntansi: saat
        // dibayar (paid_at), fallback created_at kalau belum ada (sama dgn
        // AdminDashboardController supaya angka omzet konsisten antar halaman).
        $revenueDate = 'COALESCE(paid_at, created_at)';

        $orders = Order::query()
            ->whereIn('status', self::PAID_STATUSES)
            ->whereRaw("$revenueDate BETWEEN ? AND ?", [$start, $end])
            ->when($paymentMethod !== 'all', fn ($query) => $query->where('payment_method', self::PAYMENT_METHODS[$paymentMethod]))
            ->orderByRaw($revenueDate.' DESC')
            ->get();

        $rows = $orders->map(function (Order $order) use ($mode): array {
            $gross = (int) $order->grand_total;
            $itemsTotal = (int) $order->items_total;
            $shippingTotal = (int) $order->shipping_total;
            $codServiceFee = (int) $order->cod_service_fee;
            $shippingCashback = (int) $order->shipping_cashback;
            $uniqueCode = (int) $order->unique_code;
            // items_total, shipping_total, & unique_code dikeluarkan - ketiganya bukan
            // keuntungan penjual (nilai produk, ongkir buat bayar kurir, dan kode unik
            // yang ujungnya jadi saldo/milik pembeli). "net" jadi murni cerminan dampak
            // fee COD & cashback saja.
            $net = $mode === 'buyer'
                ? $gross - $itemsTotal - $shippingTotal - $codServiceFee - $uniqueCode
                : $gross - $itemsTotal - $shippingTotal - $codServiceFee + $shippingCashback - $uniqueCode;

            return [
                'code' => $order->code,
                'awb' => $order->awb,
                'date' => ($order->paid_at ?? $order->created_at)?->translatedFormat('d M Y, H:i'),
                'payment_method' => $order->payment_method,
                'gross' => ApiData::rupiah($gross),
                'gross_value' => $gross,
                'items_total' => ApiData::rupiah($itemsTotal),
                'items_total_value' => $itemsTotal,
                'shipping_total' => ApiData::rupiah($shippingTotal),
                'shipping_total_value' => $shippingTotal,
                'cod_service_fee' => ApiData::rupiah($codServiceFee),
                'cod_service_fee_value' => $codServiceFee,
                'shipping_cashback' => ApiData::rupiah($shippingCashback),
                'shipping_cashback_value' => $shippingCashback,
                'unique_code_pembeli' => ApiData::rupiah($uniqueCode),
                'unique_code_pembeli_value' => $uniqueCode,
                'net' => ApiData::rupiah($net),
                'net_value' => $net,
                'status' => $net > 0 ? 'CUAN' : ($net < 0 ? 'BONCOS' : 'IMPAS'),
            ];
        })->values()->all();

        $totalNet = collect($rows)->sum('net_value');
        $cuanCount = collect($rows)->where('status', 'CUAN')->count();
        $impasCount = collect($rows)->where('status', 'IMPAS')->count();
        $cuanTotal = (int) collect($rows)->where('status', 'CUAN')->sum('net_value');
        $boncosTotal = (int) collect($rows)->where('status', 'BONCOS')->sum('net_value');

        // Snapshot real-time (BUKAN scoped ke bulan/filter di atas) - order COD yang
        // masih "shipped" (dikirim, belum ditandai selesai). Uangnya masih dipegang
        // kurir & belum diremit. Formula ini nyocokin persis ke "Potential Income" di
        // dashboard RajaOngkir/Komerce: ongkir tidak ikut diremit (tetap punya kurir),
        // sementara cashback ongkir ikut ditambahkan.
        $inTransitCod = Order::query()
            ->where('payment_method', 'COD')
            ->where('status', 'shipped')
            ->get(['grand_total', 'shipping_total', 'cod_service_fee', 'shipping_cashback']);

        $potentialIncome = (int) $inTransitCod->sum(fn (Order $o): int => $o->grand_total - $o->shipping_total - $o->cod_service_fee + $o->shipping_cashback
        );

        return response()->json([
            'data' => $rows,
            'meta' => [
                'month' => $anchor->format('Y-m'),
                'month_label' => $anchor->translatedFormat('F Y'),
                'mode' => $mode,
                'payment_method' => $paymentMethod,
                'count' => $orders->count(),
                'cuan_count' => $cuanCount,
                'impas_count' => $impasCount,
                'boncos_count' => $orders->count() - $cuanCount - $impasCount,
                'cuan_total_value' => $cuanTotal,
                'cuan_total' => ApiData::rupiah($cuanTotal),
                'boncos_total_value' => $boncosTotal,
                'boncos_total' => ApiData::rupiah($boncosTotal),
                'total_net' => ApiData::rupiah((int) $totalNet),
                'total_net_value' => (int) $totalNet,
                'total_gross' => ApiData::rupiah((int) $orders->sum('grand_total')),
                'total_gross_value' => (int) $orders->sum('grand_total'),
                'total_shipping_fee' => ApiData::rupiah((int) $orders->sum('shipping_total')),
                'total_shipping_fee_value' => (int) $orders->sum('shipping_total'),
                'total_cashback' => ApiData::rupiah((int) $orders->sum('shipping_cashback')),
                'total_cashback_value' => (int) $orders->sum('shipping_cashback'),
                'total_cod_service_fee' => ApiData::rupiah((int) $orders->sum('cod_service_fee')),
                'total_cod_service_fee_value' => (int) $orders->sum('cod_service_fee'),
                'total_items' => ApiData::rupiah((int) $orders->sum('items_total')),
                'total_items_value' => (int) $orders->sum('items_total'),
                'potential_income' => ApiData::rupiah($potentialIncome),
                'potential_income_value' => $potentialIncome,
                'potential_income_count' => $inTransitCod->count(),
            ],
        ]);
    }
}
