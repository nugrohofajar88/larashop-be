<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerCommission;
use App\Models\Setting;

/**
 * Siklus hidup komisi reseller: dibuat "pending" saat order dibuat (kalau
 * pembelinya tertaut ke seorang reseller aktif), lalu ikut status order -
 * "confirmed" saat order selesai, "cancelled" saat order dibatalkan.
 *
 * Dipanggil EKSPLISIT dari setiap titik transisi status order yang sudah ada
 * (checkout web & WA, admin/customer cancel, expire otomatis, restore, admin/
 * customer complete) - bukan lewat Eloquent Observer. Jalur-jalur itu sudah
 * terhitung jumlahnya di codebase ini, jadi lebih mudah ditelusuri lewat kode
 * controller/service ketimbang lewat trigger implisit yang tidak kelihatan.
 */
class ResellerCommissionService
{
    public function createPendingForOrder(Order $order): void
    {
        if (! Setting::resellerEnabled()) {
            return;
        }

        $order->loadMissing('user');
        $referrerId = $order->user?->reseller_id;

        if ($referrerId === null) {
            return;
        }

        $reseller = Reseller::query()->where('id', $referrerId)->where('status', 'active')->first();

        if ($reseller === null) {
            return;
        }

        // Idempotent - mencegah dobel kalau sampai terpanggil 2x untuk order yang sama.
        if (ResellerCommission::query()->where('order_id', $order->id)->exists()) {
            return;
        }

        $amount = $this->calculateAmount($reseller, (int) $order->items_total);

        if ($amount <= 0) {
            return;
        }

        ResellerCommission::query()->create([
            'reseller_id' => $reseller->id,
            'order_id' => $order->id,
            'order_code' => $order->code,
            'order_items_total' => (int) $order->items_total,
            'commission_type' => $reseller->commission_type,
            'commission_rate' => $reseller->commission_value,
            'amount' => $amount,
            'status' => 'pending',
        ]);
    }

    public function confirmForOrder(Order $order): void
    {
        $commission = ResellerCommission::query()
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->first();

        if ($commission === null) {
            return;
        }

        $commission->update(['status' => 'confirmed']);

        app(ResellerNotifier::class)->sendCommissionConfirmed($commission);
    }

    public function cancelForOrder(Order $order): void
    {
        ResellerCommission::query()
            ->where('order_id', $order->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->update(['status' => 'cancelled']);
    }

    /** Order yang batal-otomatis dipulihkan admin (OrderRestoreService) - komisinya ikut hidup lagi. */
    public function resetForRestore(Order $order): void
    {
        ResellerCommission::query()
            ->where('order_id', $order->id)
            ->where('status', 'cancelled')
            ->update(['status' => 'pending']);
    }

    protected function calculateAmount(Reseller $reseller, int $itemsTotal): int
    {
        if ($reseller->commission_type === 'fixed') {
            return (int) $reseller->commission_value;
        }

        return (int) round($itemsTotal * ((int) $reseller->commission_value) / 100);
    }
}
