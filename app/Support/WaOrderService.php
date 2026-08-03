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

    /**
     * True kalau teks sudah berbentuk form pesanan (ada baris "Pesanan:" dengan
     * minimal satu item) — dipakai supaya pesan pre-filled dari guest-checkout
     * web (lihat GuestCart::toWhatsappMessage() di larashop-fe) bisa langsung
     * diproses tanpa pelanggan mengetik /pesan dulu.
     */
    public function looksLikeOrderForm(string $text): bool
    {
        return $this->parseForm($this->cleanInvisible($text))['items'] !== [];
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

        $session = Cache::get($this->key($phone));

        // Pesan sudah berbentuk form pesanan (mis. dari guest-checkout web) &
        // belum ada sesi aktif -> proses langsung, tanpa perlu /pesan dulu.
        if ($session === null && $this->looksLikeOrderForm($text)) {
            $session = ['step' => 'await_form'];
            $this->put($phone, $session);

            return $this->handleForm($phone, $session, $text);
        }

        if ($this->isTrigger($lower)) {
            return $this->start($phone);
        }

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
            ."Kelurahan/Desa: \n"
            ."Kecamatan: \n"
            ."Kota/Kabupaten: \n"
            ."Pesanan:\n"
            ."- "
        );

        // Pesan 2: INSTRUKSI terpisah. Tanpa header "Pesanan:" dan tanpa baris
        // diawali "-", jadi aman walau ikut di-copas (tak jadi item hantu).
        $this->wablas->sendMessage(
            $phone,
            "ℹ️ *Cara mengisi:*\n\n"
            ."Alamat cukup nama jalan, nomor rumah, & RT/RW. Kelurahan/Desa,\n"
            ."Kecamatan, Kota/Kabupaten diisi terpisah supaya ongkir & tujuan kirim akurat.\n\n"
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

        // Pertahankan field yang sudah lengkap dari percobaan sebelumnya (mis.
        // Pesanan sudah pernah terbaca) — kalau tidak, customer yang cuma
        // membalas Nama/Alamat tanpa mengetik ulang daftar produk akan melihat
        // seolah pesanannya hilang/tidak terbaca, padahal cuma field lain yang
        // kurang.
        $prev = $session['partial_form'] ?? [];
        foreach (['name', 'phone', 'address', 'kelurahan', 'kecamatan', 'kota'] as $field) {
            if ($form[$field] === '' && ($prev[$field] ?? '') !== '') {
                $form[$field] = $prev[$field];
            }
        }
        if ($form['items'] === [] && ! empty($prev['items'])) {
            $form['items'] = $prev['items'];
        }

        $missing = [];
        if ($form['name'] === '') {
            $missing[] = 'Nama';
        }
        if ($form['address'] === '') {
            $missing[] = 'Alamat';
        }
        if ($form['kelurahan'] === '') {
            $missing[] = 'Kelurahan/Desa';
        }
        if ($form['kecamatan'] === '') {
            $missing[] = 'Kecamatan';
        }
        if ($form['kota'] === '') {
            $missing[] = 'Kota/Kabupaten';
        }
        if ($form['items'] === []) {
            $missing[] = 'Pesanan';
        }

        if ($missing !== []) {
            $session['partial_form'] = $form;
            $this->put($phone, $session);

            // Format kirim-ulang tetap sama seperti biasa, tapi baris Pesanan
            // diisi dengan item yang SUDAH terbaca (kalau ada) — supaya
            // customer tidak mengira pesanannya hilang, cukup lengkapi field
            // yang kurang lalu kirim ulang persis pesan ini.
            $itemLines = $form['items'] !== []
                ? implode("\n", array_map(fn (string $i): string => "- {$i}", $form['items']))
                : '- ';

            // Field yang masih kosong dikasih contoh format (bukan dikosongkan
            // begitu saja) supaya customer tahu apa yang diharapkan diisi;
            // field yang sudah terisi tetap ditampilkan apa adanya.
            $nameLine = $form['name'] !== '' ? $form['name'] : 'Nama_pelanggan/penerima';
            $addressLine = $form['address'] !== '' ? $form['address'] : 'Jl./nomor rumah, RT/RW';
            $kelurahanLine = $form['kelurahan'] !== '' ? $form['kelurahan'] : 'Nama kelurahan/desa';
            $kecamatanLine = $form['kecamatan'] !== '' ? $form['kecamatan'] : 'Nama kecamatan';
            $kotaLine = $form['kota'] !== '' ? $form['kota'] : 'Nama kota/kabupaten';

            return "Mohon lengkapi: *".implode('*, *', $missing)."*.\n\nKirim ulang dengan format:\n"
                ."Nama: {$nameLine}\nNo HP: {$form['phone']}\nAlamat: {$addressLine}\n"
                ."Kelurahan/Desa: {$kelurahanLine}\nKecamatan: {$kecamatanLine}\nKota/Kabupaten: {$kotaLine}\n"
                ."Pesanan:\n{$itemLines}";
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
        // Simpan juga sebagai partial_form SEBELUM panggil API ongkir (di
        // bawah) — kalau API itu timeout/gagal, form yang sudah lengkap ini
        // tetap tersimpan, jadi customer tidak perlu ketik ulang saat retry.
        $session['partial_form'] = $form;
        $this->put($phone, $session);

        // Cari wilayah tujuan dari Kelurahan/Desa + Kecamatan + Kota/Kabupaten
        // yang diisi terpisah (bukan lagi menebak dari teks alamat bebas).
        $dest = $this->resolveDestination($form['kelurahan'], $form['kecamatan'], $form['kota']);

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

        // COD hanya ditawarkan kalau diaktifkan admin DAN kurir yang kepilih
        // (termurah) memang mendukungnya. Totalnya sengaja TANPA kode unik
        // (kurir menagih tunai, harus angka bulat) — beda dari total transfer
        // di atas kalau kode unik aktif.
        $codLine = '';
        if (\App\Models\Setting::paymentCodEnabled() && ($shipping['is_cod'] ?? false)) {
            $codTotal = $itemsTotal + $shippingTotal;
            $codLine = "• Bayar di tempat? Ketik *COD* — kurir menagih tunai ".$this->money($codTotal)." saat barang diterima (tanpa kode unik).\n";
        }

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
            ."• Balas *YA* untuk konfirmasi (Transfer/QRIS).\n"
            ."{$codLine}"
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

        $isCod = in_array($lower, ['cod', 'bayar ditempat', 'bayar di tempat'], true);

        if (! $isCod && ! in_array($lower, ['ya', 'y', 'ok', 'oke', 'betul', 'benar', 'setuju', 'iya', 'lanjut'], true)) {
            // Selain YA/COD/batal/ganti wilayah -> tampilkan ulang ringkasan (halaman proses order).
            return $this->buildConfirmation($session);
        }

        if ($isCod) {
            // State bisa berubah sejak quote dibuat (admin matikan COD, dll) —
            // cek ulang sebelum benar-benar dipakai.
            if (! \App\Models\Setting::paymentCodEnabled() || ! ($session['shipping']['is_cod'] ?? false)) {
                return "Maaf, COD tidak/belum tersedia untuk pesanan ini. Balas *YA* untuk lanjut dengan Transfer/QRIS, atau *batal* untuk membatalkan.";
            }

            // COD: tanpa kode unik — kurir menagih tunai, harus angka bulat.
            $session['unique_code'] = 0;
            $session['payment_method'] = 'COD';
        }

        try {
            $order = $this->createOrder($phone, $session);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Stok tidak cukup (mis. keburu habis dipesan orang lain). Jangan hapus
            // sesi supaya customer bisa ubah qty/produk lalu konfirmasi ulang.
            $msg = collect($e->errors())->flatten()->implode(' ');

            return "⚠️ ".($msg !== '' ? $msg : 'Stok tidak mencukupi untuk pesanan ini.')."\n\nSilakan kurangi jumlah atau ganti produk, lalu ketik *ya* lagi.";
        }

        $this->forget($phone);

        if ($isCod) {
            // Tidak ada apa pun untuk divalidasi (bayar di tempat) -> langsung
            // tandai paid & auto-booking Komerce, sama seperti admin klik
            // "Validasi Pembayaran" untuk order transfer/QRIS.
            app(OrderPaymentService::class)->markPaid($order, 'cod');

            return $this->orderConfirmationCod($order, $session);
        }

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
     * @return array{name:string,phone:string,address:string,kelurahan:string,kecamatan:string,kota:string,items:array<int,string>}
     */
    protected function parseForm(string $text): array
    {
        // Whitespace SETELAH titik dua sengaja dibatasi ke [ \t]* (bukan \s*) dan
        // grup ditangkap dengan * (bukan +): kalau field dibiarkan kosong (mis.
        // pesan pre-filled dari guest-checkout web yang belum diisi pelanggan),
        // \s* akan "makan" newline dan bocor menangkap isi baris berikutnya
        // sebagai value field ini (lihat riwayat bug).
        $name = '';
        if (preg_match('/nama[ \t]*:[ \t]*(.*)/i', $text, $m)) {
            $name = trim($m[1]);
        }

        $phone = '';
        if (preg_match('/\b(?:no\.?\s*hp|nomor|telp|telepon|hp|wa)\b[^\n:]*:\s*([+\d][\d\s().\-]+)/i', $text, $m)) {
            $phone = preg_replace('/\D/', '', $m[1]);
        }

        // Alamat/Kelurahan/Kecamatan/Kota dipisah jadi field sendiri-sendiri
        // (bukan lagi satu blok "Alamat" bebas) supaya pencarian tujuan ongkir
        // di resolveDestination() tidak perlu menebak-nebak dari prosa alamat.
        // Tiap field berhenti menangkap pas ketemu label berikutnya (atau akhir
        // teks), jadi field yang dikosongkan pelanggan tidak "bocor" menangkap
        // isi baris field selanjutnya.
        $nextLabel = '(?:kelurahan|desa|kecamatan|kota|kabupaten|pesanan)\b[^\n]*:';

        $address = '';
        if (preg_match('/alamat[ \t]*:[ \t]*(.*?)(?:\n\s*'.$nextLabel.'|\z)/is', $text, $m)) {
            $address = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        $kelurahan = '';
        if (preg_match('/\b(?:kelurahan|desa)\b[^\n:]*:[ \t]*(.*?)(?:\n\s*'.$nextLabel.'|\z)/is', $text, $m)) {
            $kelurahan = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        $kecamatan = '';
        if (preg_match('/\bkecamatan\b[^\n:]*:[ \t]*(.*?)(?:\n\s*'.$nextLabel.'|\z)/is', $text, $m)) {
            $kecamatan = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        $kota = '';
        if (preg_match('/\b(?:kota|kabupaten)\b[^\n:]*:[ \t]*(.*?)(?:\n\s*'.$nextLabel.'|\z)/is', $text, $m)) {
            $kota = trim(preg_replace('/\s+/', ' ', $m[1]));
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

        return [
            'name' => $name,
            'phone' => (string) $phone,
            'address' => $address,
            'kelurahan' => $kelurahan,
            'kecamatan' => $kecamatan,
            'kota' => $kota,
            'items' => $items,
        ];
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
     * Cari wilayah tujuan dari Kelurahan/Desa + Kecamatan + Kota/Kabupaten yang
     * diisi terpisah oleh pelanggan. Coba dari kombinasi paling presisi (semua
     * 3) ke paling kasar (kota saja) - jauh lebih akurat daripada menebak dari
     * teks alamat bebas, karena tidak perlu tebak-tebak mana bagian yang nama
     * wilayah vs jalan/nomor rumah.
     */
    protected function resolveDestination(string $kelurahan, string $kecamatan, string $kota): ?array
    {
        $candidates = array_values(array_unique(array_filter([
            trim("{$kelurahan} {$kecamatan} {$kota}"),
            trim("{$kecamatan} {$kota}"),
            trim($kota),
        ])));

        foreach ($candidates as $q) {
            $r = $this->searchDestinations($q);
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
        $paymentMethod = $session['payment_method'] ?? 'Transfer manual';
        $paymentStatus = $paymentMethod === 'COD' ? 'COD - bayar saat barang diterima' : 'Menunggu transfer';
        $shipmentNote = $paymentMethod === 'COD'
            ? 'Order via WhatsApp (form), bayar COD. Siap dibooking ke ekspedisi.'
            : 'Order via WhatsApp (form). Menunggu validasi pembayaran.';

        $itemsTotal = (int) collect($items)->sum(fn (array $i): int => $i['price'] * $i['qty']);
        $shippingTotal = (int) ($shipping['price_value'] ?? 0);
        $grandTotal = max(0, $itemsTotal + $shippingTotal + $uniqueCode);

        // retry: lindungi dari race kode order (dua order WA bersamaan → kode sama).
        return retry(5, fn () => DB::transaction(function () use (
            $phone, $name, $recipientPhone, $addressLine, $dest, $shipping, $items,
            $itemsTotal, $shippingTotal, $uniqueCode, $grandTotal, $paymentMethod, $paymentStatus, $shipmentNote
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
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
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
                'shipment_note' => $shipmentNote,
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

            // Potong stok (reserve). Kalau kurang → throw, transaksi rollback,
            // ditangkap di pemanggil untuk dibalas pesan ramah.
            app(\App\Support\StockService::class)->reserveForOrder($order);

            $order->logTracking('created', 'app');

            return $order->fresh('items');
        }), 50, fn ($e) => $e instanceof \Illuminate\Database\QueryException && str_contains($e->getMessage(), 'orders_code_unique'));
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

    protected function orderConfirmationCod(Order $order, array $session): string
    {
        $form = $session['form'] ?? [];
        $dest = $session['destination'] ?? [];

        $lines = $order->items->map(
            fn ($i): string => "• {$i->quantity}x {$i->product_name}".
                ($i->variant_label ? " ({$i->variant_label})" : '').
                " = ".$this->money((int) $i->subtotal)
        )->implode("\n");

        return "✅ *Pesanan COD berhasil dibuat!*\n\n"
            ."Kode: *{$order->code}*\n\n"
            ."Nama: ".($form['name'] ?? $order->recipient_name)."\n"
            ."HP: ".($form['phone'] ?? $order->recipient_phone)."\n"
            ."Alamat: ".($form['address'] ?? '')."\n"
            ."Tujuan: ".($dest['label'] ?? '')."\n\n"
            ."{$lines}\n"
            ."Subtotal: ".$this->money((int) $order->items_total)."\n"
            ."Ongkir: ".$this->money((int) $order->shipping_total)."\n"
            ."*Total bayar tunai ke kurir: ".$this->money((int) $order->grand_total)."*\n\n"
            ."💵 Siapkan uang pas sejumlah di atas — kurir akan menagih saat barang diserahkan. "
            ."Pesananmu segera kami proses. 🙏";
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
        // Format: ATK + tanggal (YYYYMMDD) + nomor urut 5 digit dalam bulan berjalan.
        // Contoh order ke-1 pada 13 Juni 2026: ATK2026061300001. (WIB; app timezone Asia/Jakarta)
        $now = now();
        $datePart = $now->format('Ymd');
        $seq = ((int) Order::query()
            ->where('status', '!=', 'draft')
            ->whereBetween('created_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->count()) + 1;

        do {
            $code = 'ATK'.$datePart.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
            $seq++;
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
