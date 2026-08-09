<?php

namespace App\Support;

use App\Mail\OrderCancelledMail;
use App\Models\EmailLog;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Beri tahu pembeli lewat email + WA saat order dibatalkan - dipakai bersama
 * oleh 3 jalur pembatalan (customer self-cancel, admin cancel, auto-expire)
 * supaya aturannya satu tempat.
 */
class OrderCancellationNotifier
{
    public function send(Order $order, bool $wasAlreadyPaid = false): void
    {
        $this->sendEmail($order, $wasAlreadyPaid);
        $this->sendWhatsapp($order, $wasAlreadyPaid);
    }

    protected function sendEmail(Order $order, bool $wasAlreadyPaid): void
    {
        $email = trim((string) ($order->user?->email ?? ''));

        if ($email === '') {
            return;
        }

        $mailable = new OrderCancelledMail($order, $wasAlreadyPaid);
        $subject = $mailable->envelope()->subject;

        try {
            Mail::to($email)->send($mailable);

            EmailLog::create([
                'order_id' => $order->id,
                'to_email' => $email,
                'subject' => $subject,
                'status' => 'sent',
            ]);
        } catch (Throwable $e) {
            // Gagal kirim email tidak boleh menggagalkan proses pembatalan itu sendiri.
            EmailLog::create([
                'order_id' => $order->id,
                'to_email' => $email,
                'subject' => $subject,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            Log::warning('order.cancellation_mail.failed', [
                'order_id' => $order->id,
                'order_code' => $order->code,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Nomor diambil dari akun pembeli, fallback ke nomor penerima paket (mis. order tamu). */
    protected function sendWhatsapp(Order $order, bool $wasAlreadyPaid): void
    {
        $phone = preg_replace('/[^0-9]/', '', (string) ($order->user?->phone ?? $order->recipient_phone ?? '')) ?? '';

        if ($phone === '') {
            return;
        }

        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }

        $amount = 'Rp'.number_format((int) $order->grand_total, 0, ',', '.');

        $message = "❌ Pesanan *{$order->code}* ({$amount}) sudah *dibatalkan*.\n"
            .'Alasan: '.$order->payment_status
            .($wasAlreadyPaid
                ? "\n\nKarena pesanan ini sebelumnya sudah dibayar, tim kami akan segera menghubungimu untuk proses pengembalian dana (refund)."
                : '');

        app(\App\Support\Contracts\WhatsappGateway::class)->sendMessage($phone, $message);
    }
}
