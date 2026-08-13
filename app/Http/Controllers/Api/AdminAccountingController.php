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

    public function index(Request $request): JsonResponse
    {
        $month = trim((string) $request->query('month', ''));
        // seller (default) = cashback ongkir tetap jadi keuntungan penjual.
        // buyer = cashback dikasihkan ke pembeli, jadi TIDAK ikut nambah net penjual.
        $mode = $request->query('mode') === 'buyer' ? 'buyer' : 'seller';

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
            ->orderByRaw($revenueDate.' DESC')
            ->get();

        $rows = $orders->map(function (Order $order) use ($mode): array {
            $gross = (int) $order->grand_total;
            $itemsTotal = (int) $order->items_total;
            $shippingTotal = (int) $order->shipping_total;
            $codServiceFee = (int) $order->cod_service_fee;
            $shippingCashback = (int) $order->shipping_cashback;
            // items_total & shipping_total dikeluarkan - keduanya cuma "lewat" (nilai
            // produk & ongkir buat bayar kurir), bukan keuntungan penjual. "net" jadi
            // murni cerminan dampak fee COD & cashback (+ sisa kode unik) saja.
            $net = $mode === 'buyer'
                ? $gross - $itemsTotal - $shippingTotal - $codServiceFee
                : $gross - $itemsTotal - $shippingTotal - $codServiceFee + $shippingCashback;

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
                'net' => ApiData::rupiah($net),
                'net_value' => $net,
                'status' => $net > 0 ? 'CUAN' : 'BONCOS',
            ];
        })->values()->all();

        $totalNet = collect($rows)->sum('net_value');
        $cuanCount = collect($rows)->where('status', 'CUAN')->count();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'month' => $anchor->format('Y-m'),
                'month_label' => $anchor->translatedFormat('F Y'),
                'mode' => $mode,
                'count' => $orders->count(),
                'cuan_count' => $cuanCount,
                'boncos_count' => $orders->count() - $cuanCount,
                'total_net' => ApiData::rupiah((int) $totalNet),
                'total_net_value' => (int) $totalNet,
                'total_shipping_fee' => ApiData::rupiah((int) $orders->sum('shipping_total')),
                'total_shipping_fee_value' => (int) $orders->sum('shipping_total'),
                'total_cashback' => ApiData::rupiah((int) $orders->sum('shipping_cashback')),
                'total_cashback_value' => (int) $orders->sum('shipping_cashback'),
                'total_cod_service_fee' => ApiData::rupiah((int) $orders->sum('cod_service_fee')),
                'total_cod_service_fee_value' => (int) $orders->sum('cod_service_fee'),
                'total_items' => ApiData::rupiah((int) $orders->sum('items_total')),
                'total_items_value' => (int) $orders->sum('items_total'),
            ],
        ]);
    }
}
