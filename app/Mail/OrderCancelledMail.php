<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public bool $wasAlreadyPaid = false)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Pesanan {$this->order->code} Dibatalkan",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.orders.cancelled',
            with: [
                'order' => $this->order,
                'items' => $this->order->items,
                'customerName' => $this->order->user?->name ?? 'Pelanggan',
                'grandTotal' => $this->money((int) $this->order->grand_total),
                'wasAlreadyPaid' => $this->wasAlreadyPaid,
                'storeWhatsapp' => \App\Models\Setting::get('store_whatsapp', ''),
            ],
        );
    }

    protected function money(int $value): string
    {
        return 'Rp'.number_format($value, 0, ',', '.');
    }
}
