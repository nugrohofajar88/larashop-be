<?php

namespace App\Support;

use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\User;
use App\Support\Contracts\WhatsappGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pemesanan via WhatsApp dengan FORM (deterministik, minim AI).
 *
 * Alur:
 *   /pesan -> bot kirim form kosong (Nama/No HP/Alamat/Pesanan)
 *   Pelanggan isi & kirim -> sistem parse (regex), cocokkan item ke katalog
 *   (product_id, cek stok, ambil berat), cari ongkir dari alamat,
 *   kirim ringkasan -> "YA" -> order pending_payment.
 *
 * State percakapan di Cache per nomor (TTL 1 jam).
 */
class WaOrderService
{
    private const TTL_MINUTES = 60;

    public function __construct(
        private readonly WhatsappGateway $wablas,
        private readonly QrislyService $qrisly,
    ) {
    }

    public function hasSession(string $phone): bool
    {
        return Cache::has($this->key($phone));
    }

    public function isTrigger(string $text): bool
    {
        $t = strtolower(trim($this->cleanInvisible($text)));

        if (in_array($t, ['/pesan', 'pesan', 'order', '/order', 'pesen'], true)) {
            return true;
        }

        // Niat memesan dalam bahasa natural: "(saya) mau pesan", "pengen order",
        // "mau beli", "ingin belanja", atau diawali "pesan/order" ("pesan dong").
        return preg_match('/\b(mau|ingin|pengen|pgn|pingin|hendak|nak|pesan|pesen)\s+(pesan|pesen|order|beli|belanja|barang|produk)\b/iu', $t) === 1
            || preg_match('/^(pesan|pesen|order)\b/iu', $t) === 1;
    }

    public function continueHint(string $phone): ?string
    {
        $session = Cache::get($this->key($phone));

        if ($session === null) {
            return null;
        }

        // Di langkah konfirmasi: tampilkan ulang ringkasan pesanan (halaman proses order).
        if (($session['step'] ?? '') === 'await_confirm') {
            return "📌 Pesananmu tadi belum dikonfirmasi:\n\n".$this->buildConfirmation($session);
        }

        $action = match ($session['step'] ?? '') {
            'await_form' => 'lengkapi & kirim form pesanannya',
            'await_destination' => 'ketik *kelurahan/desa, kecamatan, kota* tujuan',
            default => 'balas pesan terakhir untuk melanjutkan',
        };

        return "📌 Oh ya, pesananmu tadi belum selesai. Kalau mau lanjut, {$action}. Ketik *batal* untuk membatalkan.";
    }

    public function handle(string $phone, string $message): string
    {
        $message = $this->cleanInvisible($message);
        $text = trim($message);
        $lower = strtolower($text);

        if (in_array($lower, ['batal', '/batal', 'cancel'], true)) {
            $this->forget($phone);

            return "Pesanan dibatalkan. Ketik */pesan* untuk mulai lagi.";
        }

        if ($this->isTrigger($lower)) {
            return $this->start($phone);
        }

        $session = Cache::get($this->key($phone));

        if ($session === null) {
            return $this->start($phone);
        }

        return match ($session['step'] ?? '') {
            'await_form' => $this->handleForm($phone, $session, $text),
            'await_destination' => $this->handleDestination($phone, $session, $text),
            'await_confirm' => $this->handleConfirm($phone, $session, $text),
            default => $this->start($phone),
        };
    }

    /**
     * Bersihkan karakter tak terlihat yang sering ikut saat copy-paste dari
     * WhatsApp/keyboard (zero-width space, BOM, soft hyphen, non-breaking space)
     * dan merusak parsing form/regex — mis. "A\u{200B}lamat:" jadi tak cocok.
     */
    protected function cleanInvisible(string $text): string
    {
        // Zero-width & sejenis -> hapus.
        $text = (string) preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00AD}\x{180E}]/u', '', $text);

        // Non-breaking / narrow no-break space -> spasi biasa.
        return (string) preg_replace('/[\x{00A0}\x{202F}]/u', ' ', $text);
    }

    /* ----------------------------------------------------------------- */
    /* Steps                                                              */
    /* ----------------------------------------------------------------- */

    protected function start(string $phone): string
    {
        $this->put($phone, ['step' => 'await_form']);

        // Pesan 1: FORM MURNI. Sengaja berhenti tepat setelah "Pesanan:\n- " —
        // tidak ada teks setelahnya, supaya kalau pelanggan meng-copas seluruh
        // pesan tidak ada baris contoh/instruksi yang ikut ter-parse jadi item
        // (parseForm membaca SEMUA teks setelah "Pesanan:").
        $this->wablas->sendMessage(
            $phone,
            "📝 *Form Pesanan Sobat Akar Tani Kimia*\n\n"
            ."Salin pesan ini, isi, lalu kirim:\n\n"
            ."Nama: \n"
            ."No HP: \n"
            ."Alamat: \n"
            ."Pesanan:\n"
            ."- "
        );

        // Pesan 2: INSTRUKSI terpisah. Tanpa header "Pesanan:" dan tanpa baris
        // diawali "-", jadi aman walau ikut di-copas (tak jadi item hantu).
        $this->wablas->sendMessage(
            $phone,
            "ℹ️ *Cara mengisi:*\n\n"
            ."Tulis tiap produk + jumlahnya. Contoh:\n"
            ."Pupuk NPK 5 kg x2\n"
            ."Pestisida Organik 1 liter\n\n"
            ."• Lihat daftar produk → ketik */katalog*\n"
            ."• Batalkan → ketik *batal*"
        );

        // Balasan sudah dikirim langsung (2 pesan); kembalikan kosong supaya
        // WaBotService tidak mengirim pesan ketiga.
        return '';
    }

    protected function handleForm(string $phone, array $session, string $text): string
    {
        $form = $this->parseForm($text);

        $missing = [];
        if ($form['name'] === '') {
            $missing[] = 'Nama';
        }
        if ($form['address'] === '') {
            $missing[] = 'Alamat';
        }
        if ($form['items'] === []) {
            $missing[] = 'Pesanan';
        }

        if ($missing !== []) {
            return "Mohon lengkapi: *".implode('*, *', $missing)."*.\n\nKirim ulang dengan format:\n"
                ."Nama: \nNo HP: \nAlamat: \nPesanan:\n- ";
        }

        // Cocokkan tiap item ke katalog.
        $items = [];
        $errors = [];

        foreach ($form['items'] as $raw) {
            $match = $this->matchItem($raw);

            if ($match === null) {
                $errors[] = "❌ \"{$raw}\" tidak dikenali";
                continue;
            }

            if ($match['qty'] > $match['stock']) {
                $errors[] = "⚠️ {$match['name']} ({$match['variant_label']}): stok tinggal {$match['stock']}";
                continue;
            }

            $items[] = $match;
        }

        if ($errors !== []) {
            return "Ada item yang belum beres:\n".implode("\n", $errors)."\n\n"
                ."Perbaiki bagian *Pesanan*-nya lalu kirim ulang formnya ya. 🙏";
        }

        // Pakai nomor WA pengirim kalau No HP di form kosong.
        if ($form['phone'] === '') {
            $form['phone'] = $phone;
        }

        $session['form'] = $form;
        $session['items'] = $items;

        // Cari wilayah tujuan dari alamat.
        $dest = $this->resolveDestination($form['address']);

        if ($dest === null) {
            $session['step'] = 'await_destination';
            $this->put($phone, $session);

            return "📍 Alamatnya belum bisa kami temukan otomatis untuk hitung ongkir.\n"
                ."Tolong ketik *kelurahan/desa, kecamatan, kota* tujuan.\nContoh: *Pagentan, Singosari, Malang*";
        }

        return $this->proceedToConfirm($phone, $session, $dest);
    }

    protected function handleDestination(string $phone, array $session, string $text): string
    {
        $trimmed = trim($text);

        // Kalau pelanggan membalas NOMOR & ada daftar pilihan wilayah -> pilih itu.
        if (preg_match('/^\d{1,2}$/', $trimmed) === 1 && ! empty($session['dest_options'])) {
            $opts = $session['dest_options'];
            $idx = (int) $trimmed - 1;

            if (! isset($opts[$idx])) {
                return "Nomor tidak valid. Balas angka *1*–*".count($opts)."* sesuai daftar di atas.";
            }

            unset($session['dest_options']);

            return $this->proceedToConfirm($phone, $session, $opts[$idx]);
        }

        $results = $this->searchDestinations($trimmed);

        if ($results === []) {
            return "Wilayah \"{$trimmed}\" tidak ditemukan. Coba ketik *kelurahan/desa, kecamatan, kota* (contoh: *Pagentan, Singosari, Malang*).";
        }

        // Satu kecocokan -> langsung lanjut.
        if (count($results) === 1) {
            unset($session['dest_options']);

            return $this->proceedToConfirm($phone, $session, $results[0]);
        }

        // Banyak kecocokan -> tampilkan daftar bernomor supaya pelanggan pilih yang tepat.
        $session['dest_options'] = $results;
        $this->put($phone, $session);

        $list = collect($results)
            ->map(fn (array $r, int $i): string => '*'.($i + 1).'*. '.$r['label'])
            ->implode("\n");

        return "📍 Ada beberapa wilayah yang cocok. Balas *nomor* tujuan yang benar:\n\n{$list}\n\n"
            ."Kalau belum ada yang pas, ketik lebih lengkap: *kelurahan/desa, kecamatan, kota*.";
    }

    protected function proceedToConfirm(string $phone, array $session, array $dest): string
    {
        $itemsValue = (int) collect($session['items'])->sum(fn (array $i): int => (int) $i['price'] * (int) $i['qty']);
        $options = $this->shippingOptions($dest['id'], $this->totalWeight($session['items']), $itemsValue);

        if ($options === []) {
            $session['step'] = 'await_destination';
            $this->put($phone, $session);

            return "Maaf, ongkir ke {$dest['label']} belum tersedia. Coba ketik wilayah lain (format: *kelurahan/desa, kecamatan, kota*).";
        }

        $shipping = collect($options)->sortBy('price_value')->first();
        $uniqueCode = $this->usesUniqueCode() ? random_int(101, 999) : 0;

        $session['destination'] = $dest;
        $session['shipping'] = $shipping;
        $session['unique_code'] = $uniqueCode;
        $session['step'] = 'await_confirm';
        $this->put($phone, $session);

        return $this->buildConfirmation($session);
    }

    protected function buildConfirmation(array $session): string
    {
        $form = $session['form'];
        $items = $session['items'];
        $shipping = $session['shipping'];
        $dest = $session['destination'];
        $uniqueCode = (int) ($session['unique_code'] ?? 0);

        $lines = collect($items)->map(
            fn (array $i): string => "• {$i['qty']}x {$i['name']}".
                (($i['variant_label'] ?? '') !== '' ? " ({$i['variant_label']})" : '').
                " = ".$this->money($i['price'] * $i['qty'])
        )->implode("\n");

        $itemsTotal = (int) collect($items)->sum(fn (array $i): int => $i['price'] * $i['qty']);
        $shippingTotal = (int) ($shipping['price_value'] ?? 0);
        $grandTotal = $itemsTotal + $shippingTotal + $uniqueCode;

        return "📋 *Konfirmasi Pesanan*\n\n"
            ."Nama: {$form['name']}\n"
            ."HP: {$form['phone']}\n"
            ."Alamat: {$form['address']}\n"
            ."Tujuan: {$dest['label']}\n\n"
            ."{$lines}\n\n"
            ."Subtotal: ".$this->money($itemsTotal)."\n"
            ."Ongkir ({$shipping['service']}): ".$this->money($shippingTotal)."\n"
            .($uniqueCode > 0 ? "Kode unik: ".$this->money($uniqueCode)."\n" : '')
            ."*Total: ".$this->money($grandTotal)."*\n\n"
            ."⚠️ *Pastikan TUJUAN di atas sudah benar* — ini yang menentukan ongkir & area pengiriman.\n\n"
            ."• Balas *YA* untuk konfirmasi.\n"
            ."• Tujuan belum pas? Ketik *ganti wilayah* untuk pilih ulang.\n"
            ."• Atau perbaiki alamat lalu *kirim ulang form* (ongkir dihitung ulang otomatis).\n"
            ."• Ketik *batal* untuk membatalkan.";
    }

    protected function handleConfirm(string $phone, array $session, string $text): string
    {
        $lower = strtolower(trim($text));

        // Pelanggan KIRIM ULANG FORM (mis. perbaiki alamat) -> proses ulang dari awal:
        // re-parse, cocokkan item, resolve wilayah, lalu HITUNG ULANG ONGKIR.
        if (preg_match('/(alamat|pesanan)\s*:/i', $text) === 1) {
            return $this->handleForm($phone, $session, $text);
        }

        // Ganti wilayah tujuan kalau hasil auto-pilih kurang pas.
        if (in_array($lower, ['ganti wilayah', 'ubah wilayah', 'ganti tujuan', 'ubah tujuan', 'ganti alamat', 'wilayah', 'tujuan'], true)) {
            $session['step'] = 'await_destination';
            unset($session['dest_options']);
            $this->put($phone, $session);

            return "Baik 👍 Ketik *kelurahan/desa, kecamatan, kota* tujuan yang benar.\nContoh: *Pagentan, Singosari, Malang*";
        }

        if (! in_array($lower, ['ya', 'y', 'ok', 'oke', 'betul', 'benar', 'setuju', 'iya', 'lanjut'], true)) {
            // Selain YA/batal/ganti wilayah -> tampilkan ulang ringkasan (halaman proses order).
            return $this->buildConfirmation($session);
        }

        $order = $this->createOrder($phone, $session);
        $this->forget($phone);

        // Metode pembayaran yang ditawarkan diatur admin (Pengaturan Pembayaran).
        // QRIS = teknis-aktif (API terkonfig) DAN dipilih admin.
        $qrisOn = $this->qrisly->enabled() && \App\Models\Setting::paymentQrisEnabled();
        $transferOn = \App\Models\Setting::paymentTransferEnabled();
        if (! $qrisOn && ! $transferOn) {
            $transferOn = true; // pengaman: jangan sampai tak ada metode bayar.
        }

        // Pembayaran QRIS: generate QRIS + kirim gambar QR.
        if ($qrisOn) {
            $res = $this->qrisly->generateForOrder($order);
            if (($res['ok'] ?? false)) {
                $imageUrl = $this->qrisly->qrImagePublicUrl($order->fresh());
                if ($imageUrl !== '') {
                    $amountText = $this->money((int) $res['final_amount']);

                    // Link QR DIMASUKKAN ke pesan TEKS (sendMessage) — andal di SEMUA
                    // gateway. Penting: di Wablas, sendImage bisa GAGAL dan ikut menelan
                    // caption-nya (link hilang); pesan teks biasa selalu sampai. Di Fonnte
                    // FREE gambar di-drop tapi teks tetap sampai. Jadi link aman di teks.
                    $this->wablas->sendMessage($phone, $this->orderConfirmationQris($order, (int) $res['final_amount'], $transferOn, $imageUrl));

                    // Bonus: kirim gambar QR (muncul inline di gateway yang mendukung).
                    // Kalau gagal/di-drop, link sudah ada di pesan teks di atas.
                    $this->wablas->sendImage($phone, $imageUrl, '📷 Scan QR untuk bayar *'.$amountText.'*.');

                    return '';
                }
            }
        }

        // QRIS nonaktif/gagal → transfer manual (info rekening).
        return $this->orderConfirmation($order, $session);
    }

    /* ----------------------------------------------------------------- */
    /* Parsing & matching                                                 */
    /* ----------------------------------------------------------------- */

    /**
     * @return array{name:string,phone:string,address:string,items:array<int,string>}
     */
    protected function parseForm(string $text): array
    {
        $name = '';
        if (preg_match('/nama\s*:\s*(.+)/i', $text, $m)) {
            $name = trim($m[1]);
        }

        $phone = '';
        if (preg_match('/\b(?:no\.?\s*hp|nomor|telp|telepon|hp|wa)\b[^\n:]*:\s*([+\d][\d\s().\-]+)/i', $text, $m)) {
            $phone = preg_replace('/\D/', '', $m[1]);
        }

        $address = '';
        if (preg_match('/alamat\s*:\s*(.+?)(?:\n\s*pesanan\s*:|\z)/is', $text, $m)) {
            $address = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        $items = [];
        if (preg_match('/pesanan\s*:\s*(.+)\z/is', $text, $m)) {
            foreach (preg_split('/\n/', $m[1]) as $line) {
                $line = trim(ltrim(trim($line), "-•*\t "));

                if ($line === '') {
                    continue;
                }

                $low = strtolower($line);
                if (str_starts_with($low, 'ongkir') || str_starts_with($low, 'total')
                    || str_starts_with($low, 'subtotal') || str_starts_with($low, 'jumlah')) {
                    continue;
                }

                $items[] = $line;
            }
        }

        return ['name' => $name, 'phone' => (string) $phone, 'address' => $address, 'items' => $items];
    }

    /**
     * Cocokkan satu baris pesanan ke produk+varian di katalog. null kalau tak cocok.
     */
    protected function matchItem(string $raw): ?array
    {
        // Buang harga di akhir baris (": 140.000" / "- 140.000").
        $text = (string) preg_replace('/[:\-]\s*(?:rp)?\s*[\d.,]+\s*$/i', '', $raw);

        // Jumlah: "x2", "2x", "2 pcs/botol/pack/sachet".
        $qty = 1;
        if (preg_match('/\bx\s*(\d{1,3})\b/i', $text, $m)
            || preg_match('/\b(\d{1,3})\s*x\b/i', $text, $m)
            || preg_match('/\b(\d{1,3})\s*(?:pcs|pc|botol|pack|pak|sachet|sct|buah|biji)\b/i', $text, $m)) {
            $qty = max(1, (int) $m[1]);
            $text = (string) preg_replace('/\bx\s*\d{1,3}\b|\b\d{1,3}\s*x\b|\b\d{1,3}\s*(?:pcs|pc|botol|pack|pak|sachet|sct|buah|biji)\b/i', ' ', $text);
        }

        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY),
            fn ($w): bool => strlen($w) >= 2 || is_numeric($w),
        ));

        if ($words === []) {
            return null;
        }

        $candidates = Product::query()->with('variants')->whereSearchTerms($words, true)->limit(25)->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($candidates as $p) {
            $variants = $p->variants->isNotEmpty() ? $p->variants->all() : [null];

            foreach ($variants as $v) {
                // Hanya nama produk + label varian (SKU dilewati supaya angka di
                // kode SKU tidak menimbulkan kecocokan palsu, mis. "1 kg" vs "5 kg").
                $hay = strtolower($p->name.' '.($v->label ?? ''));
                $score = 0;

                foreach ($words as $w) {
                    if (str_contains($hay, $w)) {
                        $score++;
                    }
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [$p, $v];
                }
            }
        }

        if ($best === null || $bestScore < max(1, (int) ceil(count($words) / 2))) {
            return null;
        }

        [$p, $v] = $best;

        return [
            'product_id' => $p->id,
            'product_variant_id' => $v->id ?? null,
            'name' => $p->name,
            'sku' => $v->sku ?? $p->sku,
            'variant_label' => $v->label ?? null,
            'price' => (int) ($v->price ?? $p->price),
            'qty' => $qty,
            'stock' => (int) ($v->stock ?? $p->stock),
            'weight' => (int) ($v->weight_grams ?? $p->weight_grams ?? 0),
            'length_cm' => (float) ($v->length_cm ?? $p->length_cm ?? 0),
            'width_cm' => (float) ($v->width_cm ?? $p->width_cm ?? 0),
            'height_cm' => (float) ($v->height_cm ?? $p->height_cm ?? 0),
        ];
    }

    /**
     * Cari wilayah tujuan dari teks alamat bebas (kode pos -> kecamatan/kota).
     */
    protected function resolveDestination(string $address): ?array
    {
        $address = trim($address);

        if ($address === '') {
            return null;
        }

        // Kumpulkan kandidat keyword dari yang PALING presisi (mengandung nama
        // kelurahan/kecamatan/kota) ke yang paling kasar. Kode pos sengaja TIDAK
        // diprioritaskan: satu kode pos mencakup banyak kelurahan, jadi sering
        // salah ambil. Nama wilayah lebih akurat.
        $candidates = [];

        // a) Ekstrak via prefix "Kec."/"Kecamatan"/"Kabupaten"/"Kota".
        if (preg_match('/kec(?:amatan|\.)?\s+([a-z][a-z ]{2,28})/i', $address, $m)) {
            $candidates[] = trim($m[1]);
        }
        if (preg_match('/(?:kab(?:upaten|\.)?|kota)\s+([a-z][a-z ]{2,28})/i', $address, $m)) {
            $candidates[] = trim($m[1]);
        }

        // b) Ekor alamat (kelurahan, kecamatan, kota) langsung sebagai keyword —
        //    seperti pencarian di web. Banyak pelanggan menulis tanpa prefix,
        //    mis. "Mojokrapak, Tembelang, Jombang". Buang kode pos dari tiap bagian.
        $provinces = [
            'aceh', 'sumatera utara', 'sumatera barat', 'riau', 'kepulauan riau', 'jambi',
            'sumatera selatan', 'bangka belitung', 'kepulauan bangka belitung', 'bengkulu',
            'lampung', 'dki jakarta', 'jakarta', 'jawa barat', 'banten', 'jawa tengah',
            'di yogyakarta', 'd.i. yogyakarta', 'yogyakarta', 'jogja', 'jawa timur', 'bali',
            'nusa tenggara barat', 'ntb', 'nusa tenggara timur', 'ntt', 'kalimantan barat',
            'kalimantan tengah', 'kalimantan selatan', 'kalimantan timur', 'kalimantan utara',
            'sulawesi utara', 'gorontalo', 'sulawesi tengah', 'sulawesi barat',
            'sulawesi selatan', 'sulawesi tenggara', 'maluku', 'maluku utara', 'papua',
            'papua barat', 'papua selatan', 'papua tengah', 'papua pegunungan',
            'papua barat daya', 'indonesia',
        ];

        $parts = array_values(array_filter(
            array_map(fn (string $p): string => trim(preg_replace('/\b\d{5}\b/', '', $p) ?? ''), explode(',', $address)),
            fn (string $p): bool => $p !== '' && ! in_array(strtolower($p), $provinces, true),
        ));

        // Coba jendela 3 lalu 2 bagian terakhir; juga versi yang membuang 1 bagian
        // terakhir (untuk alamat berakhiran provinsi, mis. "..., Jombang, Jawa Timur").
        foreach ([[-3, 3], [-2, 2], [-4, 3], [-3, 2]] as [$start, $len]) {
            $slice = array_slice($parts, $start, $len);
            if ($slice !== []) {
                $candidates[] = trim(implode(' ', $slice));
            }
        }

        foreach (array_values(array_unique(array_filter($candidates))) as $q) {
            $r = $this->searchDestinations($q);
            if ($r !== []) {
                return $r[0];
            }
        }

        // c) Fallback terakhir: kode pos (kurang presisi, ambil hasil pertama di area itu).
        if (preg_match('/\b(\d{5})\b/', $address, $m)) {
            $r = $this->searchDestinations($m[1]);
            if ($r !== []) {
                return $r[0];
            }
        }

        return null;
    }

    /* ----------------------------------------------------------------- */
    /* Order creation                                                     */
    /* ----------------------------------------------------------------- */

    protected function createOrder(string $phone, array $session): Order
    {
        $form = $session['form'];
        $name = $form['name'];
        $recipientPhone = $form['phone'] !== '' ? $form['phone'] : $phone;
        $addressLine = $form['address'];
        $dest = $session['destination'];
        $shipping = $session['shipping'];
        $items = $session['items'];
        $uniqueCode = (int) ($session['unique_code'] ?? 0);

        $itemsTotal = (int) collect($items)->sum(fn (array $i): int => $i['price'] * $i['qty']);
        $shippingTotal = (int) ($shipping['price_value'] ?? 0);
        $grandTotal = max(0, $itemsTotal + $shippingTotal + $uniqueCode);

        return DB::transaction(function () use (
            $phone, $name, $recipientPhone, $addressLine, $dest, $shipping, $items,
            $itemsTotal, $shippingTotal, $uniqueCode, $grandTotal
        ): Order {
            $user = $this->findOrCreateCustomer($phone, $name);

            $address = CustomerAddress::query()->create([
                'user_id' => $user->id,
                'label' => 'Alamat WA',
                'recipient_name' => $name,
                'recipient_phone' => $recipientPhone,
                'destination_id' => $dest['id'],
                'province' => $dest['province_name'] ?? '',
                'city' => $dest['city_name'] ?? '',
                'district' => $dest['district_name'] ?? '',
                'subdistrict' => $dest['subdistrict_name'] ?? '',
                'postal_code' => $dest['zip_code'] ?? '',
                'address_line' => $addressLine,
                'is_primary' => $user->addresses()->count() === 0,
            ]);

            $order = Order::query()->create([
                'code' => $this->generateOrderCode(),
                'user_id' => $user->id,
                'customer_address_id' => $address->id,
                'status' => 'pending_payment',
                'payment_method' => 'Transfer manual',
                'payment_status' => 'Menunggu transfer',
                'items_total' => $itemsTotal,
                'shipping_total' => $shippingTotal,
                'shipping_cashback' => (int) ($shipping['cashback_value'] ?? 0),
                'unique_code' => $uniqueCode,
                'used_unique_code' => 0,
                'grand_total' => $grandTotal,
                'shipping_service_name' => $shipping['service'] ?? null,
                'shipping_courier_code' => $shipping['code'] ?? null,
                'shipping_service_code' => $shipping['service_code'] ?? null,
                'shipping_estimate_days' => $shipping['estimate'] ?? null,
                'shipment_note' => 'Order via WhatsApp (form). Menunggu validasi pembayaran.',
                'recipient_name' => $name,
                'recipient_phone' => $recipientPhone,
                'address_label' => $address->label,
                'address_snapshot' => ApiData::addressSummary($address),
            ]);

            foreach ($items as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'],
                    'product_name' => $item['name'],
                    'product_sku' => $item['sku'],
                    'variant_label' => $item['variant_label'],
                    'weight_grams' => $item['weight'],
                    'price' => $item['price'],
                    'quantity' => $item['qty'],
                    'subtotal' => $item['price'] * $item['qty'],
                ]);
            }

            // Saldo kode unik (ledger UserUniqueCode) TIDAK dibuat di sini.
            // Hanya dibuat saat admin memvalidasi pembayaran
            // (AdminOrderController::validatePayment) supaya order yang masih
            // pending_payment tidak menambah saldo kode unik yang bisa dipakai.

            $order->logTracking('created', 'app');

            return $order->fresh('items');
        });
    }

    protected function orderConfirmation(Order $order, array $session): string
    {
        $form = $session['form'] ?? [];
        $dest = $session['destination'] ?? [];

        $lines = $order->items->map(
            fn ($i): string => "• {$i->quantity}x {$i->product_name}".
                ($i->variant_label ? " ({$i->variant_label})" : '').
                " = ".$this->money((int) $i->subtotal)
        )->implode("\n");

        $rekening = PaymentAccount::query()->active()->ordered()->get();
        $rekText = $rekening->isEmpty() ? '' : "\n💳 *Transfer ke salah satu rekening:*\n".$rekening->map(
            fn (PaymentAccount $a): string => "• {$a->bank_name}: *{$a->account_number}* a.n. {$a->account_holder}"
        )->implode("\n")."\n";

        return "✅ *Pesanan berhasil dibuat!*\n\n"
            ."Kode: *{$order->code}*\n\n"
            ."Nama: ".($form['name'] ?? $order->recipient_name)."\n"
            ."HP: ".($form['phone'] ?? $order->recipient_phone)."\n"
            ."Alamat: ".($form['address'] ?? '')."\n"
            ."Tujuan: ".($dest['label'] ?? '')."\n\n"
            ."{$lines}\n"
            ."Subtotal: ".$this->money((int) $order->items_total)."\n"
            ."Ongkir: ".$this->money((int) $order->shipping_total)."\n"
            .($order->unique_code > 0 ? "Kode unik: ".$this->money((int) $order->unique_code)."\n" : '')
            ."*Total transfer: ".$this->money((int) $order->grand_total)."*\n"
            .$rekText
            ."\nSilakan transfer sesuai *total di atas (termasuk kode unik)* lalu kirim bukti ke chat ini. "
            ."Admin akan memvalidasi pembayaranmu. 🙏";
    }

    protected function orderConfirmationQris(Order $order, int $finalAmount, bool $includeTransfer = false, string $qrUrl = ''): string
    {
        $lines = $order->items->map(
            fn ($i): string => "• {$i->quantity}x {$i->product_name}".
                ($i->variant_label ? " ({$i->variant_label})" : '').
                " = ".$this->money((int) $i->subtotal)
        )->implode("\n");

        // Opsi transfer manual (kalau admin mengaktifkan QRIS + Transfer sekaligus).
        $transferText = '';
        if ($includeTransfer) {
            $rekening = PaymentAccount::query()->active()->ordered()->get();
            if ($rekening->isNotEmpty()) {
                $transferText = "\n\n*Atau transfer manual* sebesar ".$this->money((int) $order->grand_total).":\n"
                    .$rekening->map(
                        fn (PaymentAccount $a): string => "• {$a->bank_name}: *{$a->account_number}* a.n. {$a->account_holder}"
                    )->implode("\n")
                    ."\nLalu kirim bukti transfer ke chat ini.";
            }
        }

        return "✅ *Pesanan berhasil dibuat!*\n\n"
            ."Kode: *{$order->code}*\n\n"
            ."{$lines}\n"
            ."Subtotal: ".$this->money((int) $order->items_total)."\n"
            ."Ongkir: ".$this->money((int) $order->shipping_total)."\n"
            ."*Total bayar: ".$this->money($finalAmount)."*\n\n"
            ."💳 *Pembayaran via QRIS* — scan QR yang dikirim berikut ini. "
            ."Nominal sudah otomatis sesuai total, jadi tinggal scan & bayar."
            .($qrUrl !== '' ? "\n\n🔗 Buka/simpan QR di sini:\n".$qrUrl : '')
            ."\n\nBegitu lunas, pesanan langsung diproses. 🙏"
            .$transferText;
    }

    /* ----------------------------------------------------------------- */
    /* Ongkir (delegasi ke ShippingService)                               */
    /* ----------------------------------------------------------------- */

    protected function searchDestinations(string $search): array
    {
        return app(ShippingService::class)->searchDestinations($search, 5);
    }

    protected function shippingOptions(int|string $destinationId, int $weight, int $itemValue = 0): array
    {
        return app(ShippingService::class)->costOptions($destinationId, $weight, $itemValue);
    }

    protected function totalWeight(array $items): int
    {
        return ShippingWeight::chargeableGrams(array_map(fn (array $i): array => [
            'weight_grams' => (int) $i['weight'],
            'length_cm' => $i['length_cm'] ?? 0,
            'width_cm' => $i['width_cm'] ?? 0,
            'height_cm' => $i['height_cm'] ?? 0,
            'qty' => (int) $i['qty'],
        ], $items));
    }

    /* ----------------------------------------------------------------- */
    /* Helpers                                                            */
    /* ----------------------------------------------------------------- */

    protected function findOrCreateCustomer(string $phone, string $name): User
    {
        $user = User::query()->where('phone', $phone)->first();

        if ($user !== null) {
            return $user;
        }

        return User::query()->create([
            'code' => 'CUST-WA-'.Str::upper(Str::random(6)),
            'name' => $name !== '' ? $name : 'Pelanggan WA '.substr($phone, -4),
            'username' => $phone,
            'phone' => $phone,
            'role' => 'customer',
            'status' => 'active',
            'password' => $phone, // default = nomor WA (cast 'hashed' di model User)
        ]);
    }

    protected function generateOrderCode(): string
    {
        $next = ((int) Order::query()->where('status', '!=', 'draft')->count()) + 1;

        do {
            $code = 'ORD-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $next++;
        } while (Order::query()->where('code', $code)->exists());

        return $code;
    }

    protected function usesUniqueCode(): bool
    {
        return \App\Models\Setting::uniqueCodeEnabled();
    }

    protected function money(int $value): string
    {
        return 'Rp'.number_format($value, 0, ',', '.');
    }

    protected function key(string $phone): string
    {
        return 'wa_order:'.$phone;
    }

    protected function put(string $phone, array $session): void
    {
        Cache::put($this->key($phone), $session, now()->addMinutes(self::TTL_MINUTES));
    }

    protected function forget(string $phone): void
    {
        Cache::forget($this->key($phone));
    }
}
