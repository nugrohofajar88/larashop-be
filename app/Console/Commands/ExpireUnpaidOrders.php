<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\UserUniqueCode;
use App\Support\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid {--hours=24}';

    protected $description = 'Batalkan order pending_payment yang melewati batas waktu pembayaran (default 24 jam) & kembalikan stok';

    public function handle(StockService $stock): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);

        $orders = Order::query()
            ->where('status', 'pending_payment')
            ->where('created_at', '<', $cutoff)
            ->get();

        $count = 0;

        foreach ($orders as $order) {
            DB::transaction(function () use ($order, $stock, $hours): void {
                // Hapus penyesuaian saldo kode unik yang sempat dibuat untuk order ini.
                UserUniqueCode::query()
                    ->where('user_id', $order->user_id)
                    ->where('ref_id', $order->id)
                    ->whereIn('type', ['paid', 'used'])
                    ->delete();

                // Kembalikan stok yang dipotong saat order dibuat.
                $stock->releaseForOrder($order);

                $order->update([
                    'status' => 'cancelled',
                    'payment_status' => 'Dibatalkan otomatis (pembayaran melewati batas waktu)',
                    'shipment_note' => 'Order dibatalkan otomatis karena pembayaran tidak dilakukan dalam '.$hours.' jam.',
                    'cancel_requested_at' => null,
                ]);

                $order->logTracking('cancelled', 'system', ['note' => 'Auto-expire pembayaran > '.$hours.' jam']);
            });

            $count++;
        }

        $this->info("Selesai. {$count} order kedaluwarsa dibatalkan & stok dikembalikan.");

        return self::SUCCESS;
    }
}
