<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AdminDashboardController extends Controller
{
    /** Status yang dihitung sebagai omzet (sudah dibayar ke atas). */
    private const PAID_STATUSES = ['paid', 'processing', 'shipped', 'completed'];

    private const STATUS_META = [
        'pending_payment' => ['label' => 'Menunggu pembayaran', 'color' => '#f59e0b'],
        'paid' => ['label' => 'Sudah dibayar', 'color' => '#10b981'],
        'processing' => ['label' => 'Diproses', 'color' => '#3b82f6'],
        'shipped' => ['label' => 'Dikirim', 'color' => '#6366f1'],
        'completed' => ['label' => 'Selesai', 'color' => '#22c55e'],
        'cancelled' => ['label' => 'Dibatalkan', 'color' => '#ef4444'],
    ];

    public function index(): JsonResponse
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        // Tanggal pengakuan omzet = saat dibayar; fallback ke created_at.
        $revenueDate = 'COALESCE(paid_at, created_at)';

        $omzetBulanIni = (int) Order::query()
            ->whereIn('status', self::PAID_STATUSES)
            ->whereRaw("$revenueDate BETWEEN ? AND ?", [$monthStart, $monthEnd])
            ->sum('grand_total');

        $pesananBulanIni = Order::query()
            ->where('status', '!=', 'draft')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();

        $pesananLunas = Order::query()
            ->whereIn('status', self::PAID_STATUSES)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();

        $unitTerjualBulanIni = (int) OrderItem::query()
            ->whereHas('order', fn ($q) => $q
                ->whereIn('status', self::PAID_STATUSES)
                ->whereRaw("$revenueDate BETWEEN ? AND ?", [$monthStart, $monthEnd]))
            ->sum('quantity');

        // Kartu actionable.
        $menungguPembayaran = Order::query()->where('status', 'pending_payment')->count();
        $perluDiproses = Order::query()->where('status', 'paid')->count();
        $perluPembatalan = Order::query()->whereNotNull('cancel_requested_at')->count();

        // Grafik omzet 6 bulan terakhir.
        $omzetChart = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $value = (int) Order::query()
                ->whereIn('status', self::PAID_STATUSES)
                ->whereRaw("$revenueDate BETWEEN ? AND ?", [$start, $end])
                ->sum('grand_total');

            $omzetChart[] = [
                'label' => $month->translatedFormat('M Y'),
                'value' => $value,
                'value_label' => $this->compactRupiah($value),
            ];
        }

        // Distribusi status (kecuali draft).
        $statusCounts = Order::query()
            ->where('status', '!=', 'draft')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statusDistribusi = [];
        foreach (self::STATUS_META as $status => $meta) {
            $count = (int) ($statusCounts[$status] ?? 0);
            if ($count === 0) {
                continue;
            }
            $statusDistribusi[] = [
                'status' => $status,
                'label' => $meta['label'],
                'color' => $meta['color'],
                'count' => $count,
            ];
        }

        // Produk terlaris (berdasarkan order yang sudah dibayar).
        $produkTerlaris = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->whereIn('status', self::PAID_STATUSES))
            ->selectRaw('product_name, SUM(quantity) as qty, SUM(subtotal) as omzet')
            ->groupBy('product_name')
            ->orderByDesc('qty')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->product_name,
                'qty' => (int) $row->qty,
                'omzet_label' => $this->rupiah((int) $row->omzet),
            ])
            ->all();

        // Order terbaru (non-draft).
        $ordersTerbaru = Order::query()
            ->with('user')
            ->where('status', '!=', 'draft')
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(fn (Order $order) => [
                'code' => $order->code,
                'customer' => $order->user?->name ?? $order->recipient_name ?? '-',
                'amount' => $this->rupiah((int) $order->grand_total),
                'status' => $order->status,
                'status_label' => self::STATUS_META[$order->status]['label'] ?? $order->status,
            ])
            ->all();

        return response()->json([
            'data' => [
                'omzet_bulan_ini' => $omzetBulanIni,
                'omzet_bulan_ini_label' => $this->compactRupiah($omzetBulanIni),
                'pesanan_bulan_ini' => $pesananBulanIni,
                'pesanan_lunas' => $pesananLunas,
                'unit_terjual_bulan_ini' => $unitTerjualBulanIni,
                'menunggu_pembayaran' => $menungguPembayaran,
                'perlu_diproses' => $perluDiproses,
                'perlu_pembatalan' => $perluPembatalan,
                'total_pelanggan' => User::query()->where('role', 'customer')->count(),
                'omzet_chart' => $omzetChart,
                'status_distribusi' => $statusDistribusi,
                'produk_terlaris' => $produkTerlaris,
                'orders_terbaru' => $ordersTerbaru,
            ],
        ]);
    }

    private function rupiah(int $value): string
    {
        return 'Rp'.number_format($value, 0, ',', '.');
    }

    /** Format ringkas untuk kartu: Rp 1,2jt / Rp 3,4rb / Rp 0. */
    private function compactRupiah(int $value): string
    {
        if ($value >= 1_000_000) {
            return 'Rp '.rtrim(rtrim(number_format($value / 1_000_000, 1, ',', '.'), '0'), ',').'jt';
        }
        if ($value >= 1_000) {
            return 'Rp '.rtrim(rtrim(number_format($value / 1_000, 1, ',', '.'), '0'), ',').'rb';
        }

        return 'Rp'.number_format($value, 0, ',', '.');
    }
}
