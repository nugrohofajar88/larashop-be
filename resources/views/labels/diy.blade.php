<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #111; font-size: 8.5px; width: 100mm; }
        .frame { margin: 3mm; border: 1px solid #111; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td { vertical-align: top; }
        .p { padding: 1.6mm 2mm; }
        .b { font-weight: bold; }
        .right { text-align: right; }
        .center { text-align: center; }
        .muted { color: #666; }
        .divider { border-top: 1px solid #111; }
        .brand { font-size: 12px; font-weight: bold; color: #4f8a1f; }
        .courier { font-size: 12px; font-weight: bold; color: #c0392b; }
        .resi { font-size: 12px; font-weight: bold; letter-spacing: 1px; }
        .title { font-size: 11px; font-weight: bold; }
        .small { font-size: 7.5px; }
        .lbl { font-size: 7px; color: #666; text-transform: uppercase; letter-spacing: .3px; }
        .mt1 { margin-top: 1mm; }
        .wrap { word-wrap: break-word; }
        .bc { padding: 0 2mm 1.6mm; text-align: center; }
        .bc div { display: inline-block; }
        .bc-sm svg, .bc-sm img { max-height: 9mm; }
    </style>
</head>
<body>
<div class="frame">
    {{-- Header --}}
    <table>
        <tr>
            <td class="p" style="width:55%;">
                @if (($logo ?? '') !== '')
                    <img src="{{ $logo }}" style="height:6mm;" alt="">
                @else
                    <span class="brand">{{ $brand }}</span>
                @endif
            </td>
            <td class="p right" style="width:45%;">
                <span class="courier">{{ $courier }}</span><br>
                <span class="small b">{{ $service }}</span>
            </td>
        </tr>
    </table>
    <div class="divider"></div>

    {{-- No Resi (1 baris) + barcode kecil --}}
    <div class="p center">
        <div class="small"><span class="lbl">No. Resi:</span> <span class="b">{{ $awb }}</span></div>
    </div>
    @if ($barcode !== '')
        <div class="bc bc-sm">{!! $barcode !!}</div>
    @endif
    <div class="divider"></div>

    {{-- Layanan / Berat / Qty (1 baris) --}}
    <div class="p small">
        <span class="lbl">Layanan:</span> <span class="b">{{ $service }}</span>
        &nbsp;|&nbsp; <span class="lbl">Berat:</span> <span class="b">{{ $weight }} Kg</span>
        &nbsp;|&nbsp; <span class="lbl">Qty:</span> <span class="b">{{ $totalQty }} pcs</span>
    </div>
    <div class="divider"></div>

    {{-- Pengirim (1 baris) --}}
    <div class="p small wrap">
        <span class="lbl">Pengirim:</span> <span class="b">{{ $sender['name'] }}</span>
        ({{ $sender['phone'] }}{{ $sender['city'] !== '' ? ' - '.$sender['city'] : '' }})
    </div>
    <div class="divider"></div>

    {{-- Penerima: nama+telp 1 baris, alamat baris berikut --}}
    <div class="p wrap">
        <div class="small"><span class="lbl">Penerima:</span> <span class="b">{{ $receiver['name'] }}</span> ({{ $receiver['phone'] }})</div>
        <div class="small mt1">{{ $receiver['address'] }}{{ $receiver['region'] !== '' ? ', '.$receiver['region'] : '' }}</div>
    </div>
    <div class="divider"></div>

    {{-- Isi paket --}}
    <div class="p wrap">
        <div class="lbl">Isi Paket</div>
        @foreach ($items as $it)
            <div class="small">• {{ $it['qty'] }}× {{ $it['name'] }}</div>
        @endforeach
        @if ($note !== '')
            <div class="small mt1 muted">Catatan: {{ $note }}</div>
        @endif
    </div>
    <div class="divider"></div>

    {{-- Order ID --}}
    <table>
        <tr>
            <td class="p small"><span class="muted">Order ID:</span> <span class="b">{{ $orderId }}</span></td>
            <td class="p small right muted">{{ $code }}</td>
        </tr>
    </table>
    <div class="divider"></div>

    <div class="p small muted">* Penerima — jangan terima paket bila bukan atas nama Anda / orang dikenal.</div>
</div>
</body>
</html>
