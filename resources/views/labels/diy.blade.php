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

    {{-- No Resi + barcode --}}
    <div class="p center">
        <div class="lbl">No. Resi</div>
        <div class="resi">{{ $awb }}</div>
    </div>
    @if ($barcode !== '')
        <div class="bc">{!! $barcode !!}</div>
    @endif
    <div class="divider"></div>

    {{-- Layanan / Berat / Qty --}}
    <table>
        <tr>
            <td class="p" style="width:34%; border-right:1px solid #111;">
                <div class="lbl">Layanan</div><div class="b">{{ $service }}</div>
            </td>
            <td class="p" style="width:33%; border-right:1px solid #111;">
                <div class="lbl">Berat</div><div class="b">{{ $weight }} Kg</div>
            </td>
            <td class="p" style="width:33%;">
                <div class="lbl">Qty</div><div class="b">{{ $totalQty }} pcs</div>
            </td>
        </tr>
    </table>
    <div class="divider"></div>

    {{-- Pengirim --}}
    <div class="p wrap">
        <div class="lbl">Pengirim</div>
        <div class="b">{{ $sender['name'] }}</div>
        <div class="small">{{ $sender['phone'] }}</div>
        <div class="small mt1">{{ $sender['address'] }}</div>
    </div>
    <div class="divider"></div>

    {{-- Penerima --}}
    <div class="p wrap">
        <div class="lbl">Penerima</div>
        <div class="title">{{ $receiver['name'] }}</div>
        <div class="small b">{{ $receiver['phone'] }}</div>
        <div class="small mt1">{{ $receiver['address'] }}</div>
        @if ($receiver['region'] !== '')
            <div class="small">{{ $receiver['region'] }}</div>
        @endif
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
