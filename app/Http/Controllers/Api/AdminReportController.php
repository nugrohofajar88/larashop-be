<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Support\ApiData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminReportController extends Controller
{
    /** Status yang dihitung sebagai transaksi nyata (uangnya beneran masuk) - sama seperti AdminAccountingController. */
    private const PAID_STATUSES = ['paid', 'processing', 'shipped', 'completed'];

    /** Ambang stok menipis - sama seperti dipakai di halaman katalog admin. */
    private const LOW_STOCK_THRESHOLD = 12;

    private function resolveMonth(Request $request): Carbon
    {
        $month = trim((string) $request->query('month', ''));

        try {
            return $month !== '' ? Carbon::createFromFormat('Y-m', $month) : Carbon::now();
        } catch (\Throwable) {
            return Carbon::now();
        }
    }

    /** Laporan penjualan per produk/varian - buat keputusan restock & procurement. */
    public function products(Request $request): JsonResponse
    {
        $anchor = $this->resolveMonth($request);
        $start = $anchor->copy()->startOfMonth();
        $end = $anchor->copy()->endOfMonth();
        $revenueDate = 'COALESCE(orders.paid_at, orders.created_at)';

        // Group dari SNAPSHOT nama produk/varian yang tersimpan di order_items
        // (bukan data produk live) - konsisten dgn cara order menyimpan data
        // historis, jadi tetap akurat walau nama produk berubah belakangan.
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::PAID_STATUSES)
            ->whereRaw("$revenueDate BETWEEN ? AND ?", [$start, $end])
            ->selectRaw('order_items.product_name, order_items.variant_label, SUM(order_items.quantity) as qty, SUM(order_items.subtotal) as omzet, COUNT(DISTINCT order_items.order_id) as order_count')
            ->groupBy('order_items.product_name', 'order_items.variant_label')
            ->get();

        // Urutkan produk (bukan baris varian) berdasarkan total omzet gabungan semua
        // variannya, lalu varian di dalam tiap produk diurutkan omzet-nya sendiri -
        // supaya varian dari produk yang sama selalu berdekatan (tidak "loncat" jauh
        // gara-gara ke-interleave sama produk lain kalau sortnya cuma per baris varian).
        $data = $rows->groupBy('product_name')
            ->map(fn ($variants, $productName) => [
                'product_name' => $productName,
                'total_omzet_value' => (int) $variants->sum('omzet'),
                'variants' => $variants->sortByDesc('omzet')->values(),
            ])
            ->sortByDesc('total_omzet_value')
            ->values()
            ->flatMap(fn (array $product) => $product['variants']->map(fn ($r): array => [
                'product_name' => $r->product_name,
                'variant_label' => $r->variant_label,
                'qty' => (int) $r->qty,
                'omzet' => ApiData::rupiah((int) $r->omzet),
                'omzet_value' => (int) $r->omzet,
                'order_count' => (int) $r->order_count,
            ]))
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'month' => $anchor->format('Y-m'),
                'month_label' => $anchor->translatedFormat('F Y'),
                'product_count' => $rows->count(),
                'total_qty' => (int) $rows->sum('qty'),
                'total_omzet' => ApiData::rupiah((int) $rows->sum('omzet')),
                'total_omzet_value' => (int) $rows->sum('omzet'),
            ],
        ]);
    }

    /**
     * Laporan performa ekspedisi per kurir. Catatan keterbatasan data: kolom
     * shipment_note cuma nyimpen pesan TERAKHIR (bukan histori tiap percobaan
     * booking), jadi "gagal" di sini = order dalam periode yg SAAT INI masih
     * macet (belum ada AWB & catatannya mengandung kata gagal) - bukan total
     * historis semua percobaan gagal (termasuk yg akhirnya sukses di-retry).
     */
    public function shipping(Request $request): JsonResponse
    {
        $anchor = $this->resolveMonth($request);
        $start = $anchor->copy()->startOfMonth();
        $end = $anchor->copy()->endOfMonth();
        $revenueDate = 'COALESCE(paid_at, created_at)';

        $orders = Order::query()
            ->whereIn('status', self::PAID_STATUSES)
            ->whereRaw("$revenueDate BETWEEN ? AND ?", [$start, $end])
            ->get(['shipping_courier_code', 'shipping_service_name', 'shipping_total', 'shipping_cashback', 'awb', 'shipment_note', 'paid_at', 'created_at', 'shipped_at']);

        $rows = $orders->groupBy(fn (Order $o) => $o->shipping_courier_code ?: '-')
            ->map(function ($group, $courier): array {
                $count = $group->count();
                $failedNow = $group->filter(fn (Order $o): bool => trim((string) $o->awb) === ''
                    && str_contains(strtolower((string) $o->shipment_note), 'gagal')
                )->count();

                $durationsHours = $group->filter(fn (Order $o): bool => $o->shipped_at !== null)
                    ->map(fn (Order $o) => ($o->paid_at ?? $o->created_at)->diffInHours($o->shipped_at));

                return [
                    'courier' => $courier,
                    'service_names' => $group->pluck('shipping_service_name')->filter()->unique()->values()->all(),
                    'order_count' => $count,
                    'failed_now_count' => $failedNow,
                    'avg_shipping_cost' => ApiData::rupiah((int) round($group->avg('shipping_total') ?? 0)),
                    'avg_shipping_cost_value' => (int) round($group->avg('shipping_total') ?? 0),
                    'total_cashback' => ApiData::rupiah((int) $group->sum('shipping_cashback')),
                    'total_cashback_value' => (int) $group->sum('shipping_cashback'),
                    'avg_hours_to_ship' => $durationsHours->isNotEmpty() ? round($durationsHours->avg(), 1) : null,
                ];
            })
            ->values()
            ->sortByDesc('order_count')
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'month' => $anchor->format('Y-m'),
                'month_label' => $anchor->translatedFormat('F Y'),
                'total_orders' => $orders->count(),
            ],
        ]);
    }

    /** Laporan stok: produk menipis (perlu restock) & produk lama tidak terjual (slow-moving). */
    public function stock(): JsonResponse
    {
        $slowMovingDays = 60;
        $cutoff = Carbon::now()->subDays($slowMovingDays);

        $variants = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('product_variants.deleted_at')
            ->whereNull('products.deleted_at')
            ->where('product_variants.is_active', true)
            ->select([
                'product_variants.id',
                'products.name as product_name',
                'product_variants.label as variant_label',
                'product_variants.sku',
                'product_variants.stock',
            ])
            ->orderBy('product_variants.stock')
            ->get();

        // Tanggal terakhir laku per varian - dari order_items yg order-nya
        // berstatus "nyata" (uangnya beneran masuk), bukan semua order mentah.
        $lastSoldByVariant = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::PAID_STATUSES)
            ->whereNotNull('order_items.product_variant_id')
            ->selectRaw('order_items.product_variant_id, MAX(COALESCE(orders.paid_at, orders.created_at)) as last_sold_at')
            ->groupBy('order_items.product_variant_id')
            ->get()
            ->keyBy('product_variant_id');

        $lowStock = $variants->filter(fn ($v) => (int) $v->stock <= self::LOW_STOCK_THRESHOLD)
            ->map(fn ($v): array => [
                'product_name' => $v->product_name,
                'variant_label' => $v->variant_label,
                'sku' => $v->sku,
                'stock' => (int) $v->stock,
            ])->values()->all();

        $slowMoving = $variants->filter(function ($v) use ($lastSoldByVariant, $cutoff): bool {
            if ((int) $v->stock <= 0) {
                return false;
            }
            $lastSold = $lastSoldByVariant->get($v->id)?->last_sold_at;

            return $lastSold === null || Carbon::parse($lastSold)->lt($cutoff);
        })->map(function ($v) use ($lastSoldByVariant): array {
            $lastSold = $lastSoldByVariant->get($v->id)?->last_sold_at;

            return [
                'product_name' => $v->product_name,
                'variant_label' => $v->variant_label,
                'sku' => $v->sku,
                'stock' => (int) $v->stock,
                'last_sold' => $lastSold ? Carbon::parse($lastSold)->translatedFormat('d M Y') : 'Belum pernah terjual',
            ];
        })->values()->all();

        return response()->json([
            'data' => [
                'low_stock' => $lowStock,
                'slow_moving' => $slowMoving,
            ],
            'meta' => [
                'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
                'slow_moving_days' => $slowMovingDays,
                'low_stock_count' => count($lowStock),
                'slow_moving_count' => count($slowMoving),
            ],
        ]);
    }

    /** Laporan pelanggan: top customer & pembeda baru vs berulang, dalam periode terpilih. */
    public function customers(Request $request): JsonResponse
    {
        $anchor = $this->resolveMonth($request);
        $start = $anchor->copy()->startOfMonth();
        $end = $anchor->copy()->endOfMonth();
        $revenueDate = 'COALESCE(paid_at, created_at)';

        $rows = Order::query()
            ->whereIn('status', self::PAID_STATUSES)
            ->whereRaw("$revenueDate BETWEEN ? AND ?", [$start, $end])
            ->selectRaw('user_id, COUNT(*) as order_count, SUM(grand_total) as total_spent')
            ->groupBy('user_id')
            ->orderByDesc('total_spent')
            ->limit(50)
            ->get();

        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'phone'])->keyBy('id');

        $data = $rows->map(function ($r) use ($users): array {
            $user = $users->get($r->user_id);

            return [
                'name' => $user->name ?? 'Customer #'.$r->user_id,
                'phone' => $user->phone ?? '-',
                'order_count' => (int) $r->order_count,
                'total_spent' => ApiData::rupiah((int) $r->total_spent),
                'total_spent_value' => (int) $r->total_spent,
                'is_repeat' => (int) $r->order_count > 1,
            ];
        })->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'month' => $anchor->format('Y-m'),
                'month_label' => $anchor->translatedFormat('F Y'),
                'customer_count' => $rows->count(),
                'repeat_count' => collect($data)->where('is_repeat', true)->count(),
                'new_count' => collect($data)->where('is_repeat', false)->count(),
            ],
        ]);
    }
}
