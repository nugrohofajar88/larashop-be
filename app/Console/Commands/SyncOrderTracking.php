<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Support\ShippingService;
use Illuminate\Console\Command;

class SyncOrderTracking extends Command
{
    protected $signature = 'orders:sync-tracking';

    protected $description = 'Auto-heal status order (processing/shipped) via tracking resi live - jaga-jaga webhook kurir (mis. Lion Parcel) tidak konsisten masuk';

    public function handle(ShippingService $shipping): int
    {
        $orders = Order::query()
            ->whereIn('status', ['processing', 'shipped'])
            ->whereNotNull('awb')
            ->where('awb', '!=', '')
            ->where('updated_at', '>=', now()->subDays(14))
            ->get();

        $changed = 0;

        foreach ($orders as $order) {
            $result = $shipping->syncOrderStatus($order, 'system');

            if ($result['changed']) {
                $changed++;
            }
        }

        $this->info("Selesai. {$orders->count()} order diperiksa, {$changed} berubah status.");

        return self::SUCCESS;
    }
}
