<x-mail::message>
# Pesanan Dibatalkan

Halo **{{ $customerName }}**,

Pesanan kamu berikut ini sudah **dibatalkan**:

<x-mail::table>
| | |
| :--- | ---: |
| Nomor Pesanan | **{{ $order->code }}** |
| Total | {{ $grandTotal }} |
| Alasan | {{ $order->payment_status }} |
</x-mail::table>

@if (! empty($items) && $items->count() > 0)
**Produk:**
<x-mail::table>
| Produk | Qty |
| :--- | ---: |
@foreach ($items as $item)
| {{ $item->product_name }}{{ $item->variant_label ? ' ('.$item->variant_label.')' : '' }} | {{ $item->quantity }} |
@endforeach
</x-mail::table>
@endif

@if ($wasAlreadyPaid)
Karena pesanan ini **sebelumnya sudah dibayar**, tim kami akan segera menghubungi kamu untuk proses pengembalian dana (refund).
@endif

Kalau ada pertanyaan, silakan hubungi kami lewat WhatsApp{{ $storeWhatsapp ? ' di '.$storeWhatsapp : '' }}.

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
