<?php

namespace App\Support;

use App\Mail\OrderCancelledMail;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Kirim email pembatalan ke pembeli - dipakai bersama oleh 3 jalur pembatalan
 * (customer self-cancel, admin cancel, auto-expire) supaya aturannya satu tempat.
 */
class OrderCancellationMailer
{
    public function send(Order $order, bool $wasAlreadyPaid = false): void
    {
        $email = trim((string) ($order->user?->email ?? ''));

        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new OrderCancelledMail($order, $wasAlreadyPaid));
        } catch (Throwable $e) {
            // Gagal kirim email tidak boleh menggagalkan proses pembatalan itu sendiri.
            Log::warning('order.cancellation_mail.failed', [
                'order_id' => $order->id,
                'order_code' => $order->code,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
