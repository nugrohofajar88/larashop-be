<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Models\WaMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sekali-pakai sebelum go-live: hapus PERMANEN semua order & akun customer
 * hasil testing beserta transkrip chat WA-nya. TIDAK menyentuh produk,
 * kategori, akun admin, pengaturan toko, rekening, QRIS, atau stok - itu
 * dibiarkan apa adanya (stok diatur ulang manual oleh admin setelah ini).
 */
class CleanupTestData extends Command
{
    protected $signature = 'cleanup:test-data {--force : Jalankan tanpa konfirmasi interaktif}';

    protected $description = 'HAPUS SEMUA order, akun customer, dan transkrip chat WA (data testing sebelum go-live)';

    public function handle(): int
    {
        $orderCount = Order::query()->count();
        $customerCount = User::query()->where('role', 'customer')->count();
        $waMessageCount = WaMessage::query()->count();

        if ($orderCount === 0 && $customerCount === 0 && $waMessageCount === 0) {
            $this->info('Tidak ada data untuk dibersihkan.');

            return self::SUCCESS;
        }

        $this->warn('Akan MENGHAPUS PERMANEN:');
        $this->line("- {$orderCount} order (+ item & riwayat statusnya)");
        $this->line("- {$customerCount} akun customer (+ alamat & saldo kode uniknya)");
        $this->line("- {$waMessageCount} transkrip chat WA");
        $this->newLine();
        $this->comment('TIDAK disentuh: produk, kategori, akun admin, pengaturan toko, rekening, QRIS, stok.');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Yakin lanjutkan? Pastikan backup database sudah dibuat.', false)) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            // Order dulu, baru customer - orders.user_id pakai restrictOnDelete()
            // (bukan cascade), jadi customer tidak bisa dihapus selama masih
            // ada order miliknya. Cascade DB otomatis membereskan
            // order_items & order_trackings (ikut orders), serta
            // customer_addresses & user_unique_codes (ikut users).
            Order::query()->delete();

            User::query()->where('role', 'customer')->delete();

            WaMessage::query()->delete();
        });

        $this->info("Selesai. {$orderCount} order, {$customerCount} customer, {$waMessageCount} pesan WA terhapus.");

        return self::SUCCESS;
    }
}
