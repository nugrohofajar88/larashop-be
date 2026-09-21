<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\PaymentAccount;
use App\Support\Contracts\WhatsappGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RemindUnpaidOrders extends Command
{
    protected $signature = 'orders:remind-unpaid';

    protected $description = 'Kirim pengingat WA (jam ke-6 & ke-20) untuk order transfer manual yang belum dibayar sebelum kedaluwarsa';

    // Umur order (jam sejak dibuat/dipulihkan) saat pengingat dikirim.
    private const FIRST_AT_HOURS = 6;

    private const LAST_AT_HOURS = 20;

    // Jangan kirim WA di jam istirahat pelanggan (WIB).
    private const QUIET_FROM_HOUR = 21;

    private const QUIET_UNTIL_HOUR = 6;

    public function handle(WhatsappGateway $wa): int
    {
        $hour = (int) now()->format('G');

        if ($hour >= self::QUIET_FROM_HOUR || $hour < self::QUIET_UNTIL_HOUR) {
            $this->info('Jam istirahat, pengingat ditunda ke run berikutnya.');

            return self::SUCCESS;
        }

        $orders = Order::query()
            ->with('user')
            ->where('status', 'pending_payment')
            ->where('payment_method', 'Transfer manual')
            ->where(fn ($q) => $q->whereNull('qris_amount')->orWhere('qris_amount', 0))
            ->where('payment_reminder_stage', '<', 2)
            ->get();

        $sent = 0;

        foreach ($orders as $order) {
            $age = ($order->restored_at ?? $order->created_at)->diffInHours(now(), true);
            $stage = (int) $order->payment_reminder_stage;

            $next = match (true) {
                $age >= self::LAST_AT_HOURS && $stage < 2 => 2,
                $age >= self::FIRST_AT_HOURS && $age < self::LAST_AT_HOURS && $stage < 1 => 1,
                default => null,
            };

            if ($next === null) {
                continue;
            }

            $phone = preg_replace('/[^0-9]/', '', (string) ($order->user?->phone ?? $order->recipient_phone ?? '')) ?? '';
            if (str_starts_with($phone, '0')) {
                $phone = '62'.substr($phone, 1);
            }

            // Tandai dulu apa pun hasilnya: pengingat yang gagal kirim tidak diulang tiap jam.
            $order->update(['payment_reminder_stage' => $next]);

            if ($phone === '') {
                continue;
            }

            try {
                $wa->sendMessage($phone, $this->message($order, $next === 2));
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('order.payment_reminder.failed', ['order' => $order->code, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Selesai. {$sent} pengingat pembayaran terkirim.");

        return self::SUCCESS;
    }

    private function message(Order $order, bool $isLast): string
    {
        $money = fn (int $v): string => 'Rp'.number_format($v, 0, ',', '.');

        $rekening = PaymentAccount::query()->active()->ordered()->get();
        $rekText = $rekening->isEmpty() ? '' : "\n💳 *Transfer ke salah satu rekening:*\n".$rekening->map(
            fn (PaymentAccount $a): string => "• {$a->bank_name}: *{$a->account_number}* a.n. {$a->account_holder}"
        )->implode("\n")."\n";

        $deadline = $order->paymentDeadline()->translatedFormat('d M Y, H:i');

        return ($isLast ? "⚠️ *Pengingat terakhir* — " : "⏰ *Pengingat pembayaran* — ")
            ."pesanan *{$order->code}* masih menunggu pembayaran.\n\n"
            ."*Total transfer: ".$money((int) $order->grand_total)."* (termasuk kode unik)\n"
            .$rekText
            ."\nSudah transfer? Balas chat ini dengan *bukti transfer* ya, supaya segera kami validasi. 🙏\n\n"
            ."Batas pembayaran: *{$deadline} WIB* — lewat dari itu pesanan otomatis dibatalkan & stok dilepas.";
    }
}
