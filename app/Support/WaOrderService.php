<?php

namespace App\Support;

use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\User;
use App\Models\WaMessage;
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
    // Sengaja beberapa jam (bukan 60 menit spt semula) - pelanggan sering
    // butuh waktu mikir/sibuk sebelum balas pertanyaan konfirmasi (mis.
    // "Mungkin maksudmu ...?"), apalagi makin lama alur order-nya (sekarang
    // ada langkah konfirmasi tambahan). Ketahuan dari kasus nyata: sesi
    // kedaluwarsa pas pelanggan balas "Ya" 1 jam 15 menit kemudian -> bot
    // diam total, pelanggan merasa diabaikan.
    private const TTL_MINUTES = 360;

    // Batas panjang alamat milik Komerce - lihat konstanta di sana.
    private const MAX_ADDRESS_LENGTH = KomerceShipmentService::MAX_RECEIVER_ADDRESS_LENGTH;

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

        // Di langkah konfirmasi nama produk: tampilkan ulang pertanyaannya.
        if (($session['step'] ?? '') === 'await_item_fix') {
            return "📌 Pesananmu tadi masih nunggu konfirmasi nama produk:\n\n".$this->askItemFix($session);
        }

        $action = match ($session['step'] ?? '') {
            'await_form' => 'lengkapi & kirim form pesanannya',
            'await_destination' => 'ketik *kelurahan/desa, kecamatan, kota* tujuan',
            'await_courier' => 'balas *nomor* kurir dari daftar yang dikirim',
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
            'await_courier' => $this->handleCourier($phone, $session, $text),
            'await_item_fix' => $this->handleItemFix($phone, $session, $text),
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
        $formMessage = "📝 *Form Pesanan Sobat Akar Tani Kimia*\n\n"
            ."Salin pesan ini, isi, lalu kirim:\n\n"
            ."Nama: \n"
            ."No HP: \n"
            ."Alamat: \n"
            ."Kelurahan/Desa: \n"
            ."Kecamatan: \n"
            ."Kota/Kabupaten: \n"
            ."Pesanan:\n"
            ."- ";
        $this->wablas->sendMessage($phone, $formMessage);
        $this->log($phone, $formMessage);

        // Pesan 2: INSTRUKSI terpisah. Tanpa header "Pesanan:" dan tanpa baris
        // diawali "-", jadi aman walau ikut di-copas (tak jadi item hantu).
        $instructionMessage = "ℹ️ *Cara mengisi:*\n\n"
            ."Alamat cukup nama jalan, nomor rumah, & RT/RW. Kelurahan/Desa,\n"
            ."Kecamatan, Kota/Kabupaten diisi terpisah supaya ongkir & tujuan kirim akurat.\n\n"
            ."Tulis tiap produk + jumlahnya. Contoh:\n"
            ."Pupuk NPK 5 kg x2\n"
            ."Pestisida Organik 1 liter\n\n"
            ."• Lihat daftar produk → ketik */katalog*\n"
            ."• Batalkan → ketik *batal*";
        $this->wablas->sendMessage($phone, $instructionMessage);
        $this->log($phone, $instructionMessage);

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

        // Alamat kepanjangan -> order PASTI gagal dibooking ke ekspedisi.
        // Dicegat di sini, sebelum order terbentuk, supaya tidak jadi order
        // "lunas tapi tak bisa dikirim" yang harus dibetulkan manual lewat
        // admin. Penyebab paling sering: pelanggan menyalin balik seluruh
        // pesan bot ke dalam kolom Alamat.
        $addressLength = mb_strlen($form['address']);
        if ($addressLength > self::MAX_ADDRESS_LENGTH) {
            // Alamat dikosongkan di partial_form supaya pelanggan menulis
            // ulang; field lain yang sudah benar tetap dipertahankan.
            $form['address'] = '';
            $session['partial_form'] = $form;
            $this->put($phone, $session);

            $itemLines = $form['items'] !== []
                ? implode("\n", array_map(fn (string $i): string => "- {$i}", $form['items']))
                : '- ';

            return "Alamatnya kepanjangan ({$addressLength} karakter, maksimal ".self::MAX_ADDRESS_LENGTH.") 🙏\n\n"
                ."Isi *Alamat* dengan nama jalan, nomor rumah, dan RT/RW saja. "
                ."Kelurahan, kecamatan, dan kota sudah punya barisnya sendiri, "
                ."jadi tidak perlu diulang di sini.\n\n"
                ."Kirim ulang dengan format:\n"
                ."Nama: {$form['name']}\nNo HP: {$form['phone']}\nAlamat: Jl./nomor rumah, RT/RW\n"
                ."Kelurahan/Desa: {$form['kelurahan']}\nKecamatan: {$form['kecamatan']}\nKota/Kabupaten: {$form['kota']}\n"
                ."Pesanan:\n{$itemLines}";
        }

        // Cocokkan tiap item ke katalog.
        $items = [];
        $errors = [];
        // Item yang gagal cocok TAPI ada saran ejaan (lihat suggestProductNames())
        // - dipisah dari $errors supaya, kalau cuma ini satu-satunya masalah,
        // customer bisa konfirmasi/pilih nomor alih-alih ketik ulang form utuh.
        $fixable = [];

        foreach ($form['items'] as $raw) {
            $match = $this->matchItem($raw);

            if ($match === null) {
                $suggestions = $this->suggestProductNames($raw);

                if ($suggestions !== []) {
                    $fixable[] = ['raw' => $raw, 'candidates' => $suggestions];
                } else {
                    $errors[] = "❌ \"{$raw}\" tidak dikenali";
                }
                continue;
            }

            if ($match['qty'] > $match['stock']) {
                $errors[] = "⚠️ {$match['name']} ({$match['variant_label']}): stok tinggal {$match['stock']}";
                continue;
            }

            $items[] = $match;
        }

        // Kalau ada error LAIN (stok kurang / sama sekali tak ada saran) di luar
        // item yang fixable, jangan masuk alur konfirmasi - tetap minta kirim
        // ulang form utuh seperti biasa (lebih sederhana daripada campur dua
        // mekanisme perbaikan sekaligus dalam satu giliran chat).
        if ($errors !== []) {
            foreach ($fixable as $f) {
                $errors[] = "❌ \"{$f['raw']}\" tidak dikenali. Mungkin maksudmu *{$f['candidates'][0]}*? Kalau benar, perbaiki nama produknya persis seperti itu.";
            }

            return "Ada item yang belum beres:\n".implode("\n", $errors)."\n\n"
                ."Perbaiki bagian *Pesanan*-nya lalu kirim ulang formnya ya. 🙏";
        }

        if ($fixable !== []) {
            $session['form'] = $form;
            $session['item_fix_matched'] = $items;
            $session['item_fix_queue'] = $fixable;
            $session['item_fix_index'] = 0;
            $session['step'] = 'await_item_fix';
            $session['partial_form'] = $form;
            $this->put($phone, $session);

            return $this->askItemFix($session);
        }

        return $this->finalizeItems($phone, $session, $form, $items);
    }

    /**
     * Tampilkan pertanyaan konfirmasi/pilihan untuk item fixable yang lagi
     * diproses (session['item_fix_queue'][item_fix_index]).
     */
    protected function askItemFix(array $session): string
    {
        $current = $session['item_fix_queue'][$session['item_fix_index']];
        $candidates = $current['candidates'];

        if (count($candidates) === 1) {
            return "❓ \"{$current['raw']}\" tidak dikenali persis. Mungkin maksudmu *{$candidates[0]}*?\n\n"
                ."Balas *YA* kalau benar, atau *kirim ulang form* kalau ternyata beda.";
        }

        $list = collect($candidates)->map(fn (string $name, int $i): string => ($i + 1).". {$name}")->implode("\n");

        return "❓ \"{$current['raw']}\" tidak dikenali persis. Maksudmu yang mana?\n\n{$list}\n\n"
            ."Balas *nomor*nya, atau *kirim ulang form* kalau ternyata beda.";
    }

    /**
     * Proses balasan customer saat ada item fixable yang nunggu
     * konfirmasi/pilihan nomor (lihat askItemFix()). Nama yang dipilih
     * digabung KE teks pesanan ASLI (bukan menggantikannya) lalu dicocokkan
     * ulang lewat matchItem() biasa - supaya kata-kata asli dari customer
     * (mis. ukuran "250") tetap ikut menentukan varian yang benar, dan tetap
     * lolos ambang batas anti-salah-pilih yang sama seperti order normal.
     */
    protected function handleItemFix(string $phone, array $session, string $text): string
    {
        // Customer kirim ulang form (mis. ganti sendiri nama produknya) ->
        // keluar dari alur konfirmasi, proses dari awal seperti biasa.
        if (preg_match('/(alamat|pesanan)\s*:/i', $text) === 1) {
            $session['step'] = 'await_form';

            return $this->handleForm($phone, $session, $text);
        }

        $current = $session['item_fix_queue'][$session['item_fix_index']];
        $candidates = $current['candidates'];
        $lower = strtolower(trim($text));

        $chosen = null;
        if (count($candidates) === 1 && in_array($lower, ['ya', 'y', 'ok', 'oke', 'benar', 'betul', 'iya'], true)) {
            $chosen = $candidates[0];
        } elseif (preg_match('/^\d{1,2}$/', trim($text)) === 1) {
            $n = (int) trim($text);
            $chosen = $candidates[$n - 1] ?? null;

            if ($chosen === null) {
                return "Nomor tidak valid. Balas angka *1*–*".count($candidates)."* sesuai daftar di atas, atau *kirim ulang form* kalau ternyata beda.";
            }
        }

        if ($chosen === null) {
            return $this->askItemFix($session);
        }

        $match = $this->matchItem($current['raw'].' '.$chosen);

        if ($match === null) {
            // Seharusnya tak pernah terjadi (nama persis dari katalog + kata
            // asli customer) - jaga-jaga saja supaya tak error ke customer.
            $session['step'] = 'await_form';
            $this->put($phone, $session);

            return "Maaf, ada kendala mencocokkan produknya. Tolong *kirim ulang form* dengan nama produk yang benar ya.";
        }

        if ($match['qty'] > $match['stock']) {
            $session['step'] = 'await_form';
            $this->put($phone, $session);

            return "⚠️ {$match['name']} ({$match['variant_label']}): stok tinggal {$match['stock']}, kurang dari yang dipesan.\n\n"
                ."Perbaiki bagian *Pesanan*-nya lalu kirim ulang formnya ya. 🙏";
        }

        $matched = $session['item_fix_matched'];
        $matched[] = $match;
        $session['item_fix_matched'] = $matched;

        $nextIndex = $session['item_fix_index'] + 1;

        if (isset($session['item_fix_queue'][$nextIndex])) {
            $session['item_fix_index'] = $nextIndex;
            $this->put($phone, $session);

            return "✅ Oke, *{$match['name']}* dipakai.\n\n".$this->askItemFix($session);
        }

        unset($session['item_fix_queue'], $session['item_fix_index'], $session['item_fix_matched']);

        return $this->finalizeItems($phone, $session, $session['form'], $matched);
    }

    /**
     * Ekor proses setelah semua item di form beres (cocok & stok cukup):
     * simpan sesi, cari wilayah tujuan, lalu lanjut ke hitung ongkir &
     * ringkasan konfirmasi. Dipakai baik dari alur form normal maupun
     * setelah item fixable selesai dikonfirmasi (handleItemFix()).
     */
    protected function finalizeItems(string $phone, array $session, array $form, array $items): string
    {
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

        // Estimasi WAJIB ditampilkan (bukan cuma nama kurir) - opsi termurah
        // yang otomatis kepilih bisa saja cargo, yang jauh lebih lama dari
        // reguler; customer harus tahu sebelum ketik YA, bukan kaget belakangan.
        $estimateText = ($shipping['estimate'] ?? '') !== ''
            ? ', estimasi '.str_ireplace(['days', 'day'], 'hari', $shipping['estimate'])
            : '';

        // COD hanya ditawarkan kalau diaktifkan admin DAN kurir yang kepilih
        // (termurah) memang mendukungnya. Totalnya sengaja TANPA kode unik
        // (kurir menagih tunai, harus angka bulat) — beda dari total transfer
        // di atas kalau kode unik aktif.
        $codLine = '';
        $codTotal = $itemsTotal + $shippingTotal;
        if (\App\Models\Setting::paymentCodEnabled() && ($shipping['is_cod'] ?? false) && $codTotal <= \App\Models\Setting::codMaxAmount()) {
            $codLine = "• Bayar di tempat? Ketik *COD* — kurir menagih tunai ".$this->money($codTotal)." saat barang diterima (tanpa kode unik).\n";
        }

        return "📋 *Konfirmasi Pesanan*\n\n"
            ."Nama: {$form['name']}\n"
            ."HP: {$form['phone']}\n"
            ."Alamat: {$form['address']}\n"
            ."Tujuan: {$dest['label']}\n\n"
            ."{$lines}\n\n"
            ."Subtotal: ".$this->money($itemsTotal)."\n"
            ."Ongkir ({$shipping['service']}{$estimateText}): ".$this->money($shippingTotal)."\n"
            .($uniqueCode > 0 ? "Kode unik: ".$this->money($uniqueCode)."\n" : '')
            ."*Total: ".$this->money($grandTotal)."*\n\n"
            ."⚠️ *Pastikan TUJUAN di atas sudah benar* — ini yang menentukan ongkir & area pengiriman.\n\n"
            ."• Balas *YA* untuk konfirmasi (Transfer/QRIS).\n"
            ."{$codLine}"
            ."• Mau ganti kurir (mis. J&T/SAP selain {$shipping['code']})? Ketik *ganti kurir*.\n"
            ."• Tujuan belum pas? Ketik *ganti wilayah* untuk pilih ulang.\n"
            ."• Atau perbaiki alamat lalu *kirim ulang form* (ongkir dihitung ulang otomatis).\n"
            ."• Ketik *batal* untuk membatalkan.";
    }

    protected function handleConfirm(string $phone, array $session, string $text): string
    {
        $lower = strtolower(trim($text));

        // Pesan RINGKASAN KONFIRMASI milik bot sendiri juga memuat baris
        // "Alamat: ...". Jadi kalau pelanggan menyalin/mengutip balik pesan itu
        // (sering terjadi — biasanya sambil bertanya "ini gimana kak?"), tanpa
        // penjaga ini bot salah mengira itu form baru lalu mem-parse ulang
        // isinya. Begitu dikenali sebagai gema pesan sendiri, biarkan jatuh ke
        // cabang di bawah yang menampilkan ulang ringkasan + pilihan.
        $isEchoRingkasan = str_contains($text, 'Konfirmasi Pesanan')
            || str_contains($text, 'Pastikan TUJUAN di atas sudah benar');

        // Pelanggan KIRIM ULANG FORM (mis. perbaiki alamat) -> proses ulang dari awal:
        // re-parse, cocokkan item, resolve wilayah, lalu HITUNG ULANG ONGKIR.
        if (! $isEchoRingkasan && preg_match('/(alamat|pesanan)\s*:/i', $text) === 1) {
            return $this->handleForm($phone, $session, $text);
        }

        // Ganti wilayah tujuan kalau hasil auto-pilih kurang pas.
        if (in_array($lower, ['ganti wilayah', 'ubah wilayah', 'ganti tujuan', 'ubah tujuan', 'ganti alamat', 'wilayah', 'tujuan'], true)) {
            $session['step'] = 'await_destination';
            unset($session['dest_options']);
            $this->put($phone, $session);

            return "Baik 👍 Ketik *kelurahan/desa, kecamatan, kota* tujuan yang benar.\nContoh: *Pagentan, Singosari, Malang*";
        }

        // Ganti kurir kalau opsi termurah yang otomatis kepilih kurang pas
        // (mis. customer mau J&T/SAP walau lebih mahal dari Lion cargo).
        if (in_array($lower, ['ganti kurir', 'ubah kurir', 'pilih kurir', 'ganti ekspedisi', 'ubah ekspedisi', 'kurir'], true)) {
            return $this->offerCourierChoices($phone, $session);
        }

        $isCod = in_array($lower, ['cod', 'bayar ditempat', 'bayar di tempat'], true);

        if (! $isCod && ! in_array($lower, ['ya', 'y', 'ok', 'oke', 'betul', 'benar', 'setuju', 'iya', 'lanjut'], true)) {
            // Selain YA/COD/batal/ganti wilayah -> tampilkan ulang ringkasan (halaman proses order).
            return $this->buildConfirmation($session);
        }

        if ($isCod) {
            // State bisa berubah sejak quote dibuat (admin matikan COD, dll) —
            // cek ulang sebelum benar-benar dipakai.
            $codItemsTotal = (int) collect($session['items'] ?? [])->sum(fn (array $i): int => $i['price'] * $i['qty']);
            $codShippingTotal = (int) ($session['shipping']['price_value'] ?? 0);

            if (! \App\Models\Setting::paymentCodEnabled() || ! ($session['shipping']['is_cod'] ?? false)) {
                return "Maaf, COD tidak/belum tersedia untuk pesanan ini. Balas *YA* untuk lanjut dengan Transfer/QRIS, atau *batal* untuk membatalkan.";
            }

            if (($codItemsTotal + $codShippingTotal) > \App\Models\Setting::codMaxAmount()) {
                return "Maaf, COD cuma tersedia untuk total belanja sampai dengan ".$this->money(\App\Models\Setting::codMaxAmount())
                    .". Balas *YA* untuk lanjut dengan Transfer/QRIS, atau *batal* untuk membatalkan.";
            }

            // COD: tanpa kode unik — kurir menagih tunai, harus angka bulat.
            $session['unique_code'] = 0;
            $session['payment_method'] = 'COD';
        }

        // Toko ditutup sementara (toggle admin, mis. hari libur) — jangan sampai
        // order kebentuk sama sekali, walau customer sudah sampai tahap konfirmasi.
        if (! \App\Models\Setting::storeOpen()) {
            return "🙏 Maaf, toko sedang *tutup sementara* dan belum bisa menerima pesanan baru saat ini. Silakan coba lagi nanti ya.";
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
                    $qrisConfirmation = $this->orderConfirmationQris($order, (int) $res['final_amount'], $transferOn, $imageUrl);
                    $this->wablas->sendMessage($phone, $qrisConfirmation);
                    $this->log($phone, $qrisConfirmation);

                    // Bonus: kirim gambar QR (muncul inline di gateway yang mendukung).
                    // Kalau gagal/di-drop, link sudah ada di pesan teks di atas.
                    $qrisImageCaption = '📷 Scan QR untuk bayar *'.$amountText.'*.';
                    $this->wablas->sendImage($phone, $imageUrl, $qrisImageCaption);
                    $this->log($phone, $qrisImageCaption);

                    return '';
                }
            }
        }

        // QRIS nonaktif/gagal → transfer manual (info rekening).
        return $this->orderConfirmation($order, $session);
    }

    /**
     * Ambil ulang daftar opsi ongkir (bukan cuma yang termurah) & tampilkan
     * bernomor supaya customer bisa pilih kurir lain selain hasil auto-pilih
     * (mis. paket berat otomatis kepilih Lion cargo, tapi customer mau J&T).
     */
    protected function offerCourierChoices(string $phone, array $session): string
    {
        $itemsValue = (int) collect($session['items'])->sum(fn (array $i): int => (int) $i['price'] * (int) $i['qty']);
        $options = $this->shippingOptions($session['destination']['id'], $this->totalWeight($session['items']), $itemsValue);

        if ($options === []) {
            return $this->buildConfirmation($session);
        }

        $session['courier_options'] = $options;
        $session['step'] = 'await_courier';
        $this->put($phone, $session);

        $list = collect($options)
            ->map(function (array $o, int $i): string {
                $est = ($o['estimate'] ?? '') !== '' ? ', estimasi '.$o['estimate'] : '';

                return '*'.($i + 1).'*. '.$o['service'].' — '.$o['price'].$est;
            })
            ->implode("\n");

        return "🚚 Pilih kurir (balas *nomor*):\n\n{$list}\n\nKetik *batal* untuk membatalkan pesanan.";
    }

    protected function handleCourier(string $phone, array $session, string $text): string
    {
        $trimmed = trim($text);

        if (preg_match('/^\d{1,2}$/', $trimmed) === 1 && ! empty($session['courier_options'])) {
            $opts = $session['courier_options'];
            $idx = (int) $trimmed - 1;

            if (! isset($opts[$idx])) {
                return "Nomor tidak valid. Balas angka *1*–".count($opts)." sesuai daftar di atas.";
            }

            $session['shipping'] = $opts[$idx];
            unset($session['courier_options']);
            $session['step'] = 'await_confirm';
            $this->put($phone, $session);

            return $this->buildConfirmation($session);
        }

        return "Balas dengan *angka* sesuai daftar kurir di atas, atau ketik *batal* untuk membatalkan.";
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
        // Batas akhir sebuah field. DULU isinya cuma daftar label form
        // (kelurahan|desa|kecamatan|kota|kabupaten|pesanan), dan itu bocor
        // parah: kalau pelanggan MENYALIN BALIK pesan konfirmasi bot - yang
        // juga memuat baris "Alamat: ..." tapi diikuti "Tujuan:", rincian
        // item, "Subtotal:", instruksi, dst - tidak ada satu pun label
        // penghenti yang cocok, jadi "Alamat" MENELAN SELURUH SISA PESAN.
        // Alamat jadi ratusan karakter dan booking ke ekspedisi ditolak
        // ("Create order failed: receiver address must not exceed 250
        // characters"). Sekarang field berhenti di baris kosong, baris
        // berlabel APA PUN ("Tujuan:", "Ongkir (JNT - EZ):", "Subtotal:"),
        // atau baris yang diawali bullet - alamat multi-baris biasa
        // (mis. "Jl. Mawar 5" + "RT 02 RW 04") tetap tertangkap utuh.
        $stopLine = '(?:'
            .'[ \t]*\n'            // baris kosong
            .'|[ \t]*[^\n:]{1,40}:' // baris berlabel apa pun
            .'|[ \t]*[•▪*]'        // baris bullet
            .')';

        $address = '';
        if (preg_match('/alamat[ \t]*:[ \t]*(.*?)(?:\n'.$stopLine.'|\z)/is', $text, $m)) {
            $address = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        $kelurahan = '';
        if (preg_match('/\b(?:kelurahan|desa)\b[^\n:]*:[ \t]*(.*?)(?:\n'.$stopLine.'|\z)/is', $text, $m)) {
            $kelurahan = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        $kecamatan = '';
        if (preg_match('/\bkecamatan\b[^\n:]*:[ \t]*(.*?)(?:\n'.$stopLine.'|\z)/is', $text, $m)) {
            $kecamatan = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        $kota = '';
        if (preg_match('/\b(?:kota|kabupaten)\b[^\n:]*:[ \t]*(.*?)(?:\n'.$stopLine.'|\z)/is', $text, $m)) {
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
     * Bersihkan satu baris pesanan jadi {text, qty}: buang harga di akhir baris
     * (": 140.000" / "- 140.000") lalu tangkap+buang penanda jumlah ("x2", "2x",
     * "2 pcs/botol/pack/sachet"). Dipakai bersama oleh matchItem() (pencocokan
     * ketat) dan suggestProductNames() (saran "mungkin maksudmu" saat gagal cocok)
     * supaya keduanya membaca baris yang sama persis.
     *
     * @return array{text:string, qty:int}
     */
    protected function extractItemText(string $raw): array
    {
        $text = (string) preg_replace('/[:\-]\s*(?:rp)?\s*[\d.,]+\s*$/i', '', $raw);

        $qty = 1;
        if (preg_match('/\bx\s*(\d{1,3})\b/i', $text, $m)
            || preg_match('/\b(\d{1,3})\s*x\b/i', $text, $m)
            || preg_match('/\b(\d{1,3})\s*(?:pcs|pc|botol|pack|pak|sachet|sct|buah|biji)\b/i', $text, $m)) {
            $qty = max(1, (int) $m[1]);
            $text = (string) preg_replace('/\bx\s*\d{1,3}\b|\b\d{1,3}\s*x\b|\b\d{1,3}\s*(?:pcs|pc|botol|pack|pak|sachet|sct|buah|biji)\b/i', ' ', $text);
        }

        return ['text' => $text, 'qty' => $qty];
    }

    /**
     * Cocokkan satu baris pesanan ke produk+varian di katalog. null kalau tak cocok.
     */
    protected function matchItem(string $raw): ?array
    {
        ['text' => $text, 'qty' => $qty] = $this->extractItemText($raw);

        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY),
            fn ($w): bool => strlen($w) >= 2 || is_numeric($w),
        ));

        if ($words === []) {
            return null;
        }

        // SENGAJA muat SEMUA produk aktif langsung (bukan pra-filter SQL
        // whereSearchTerms()+limit()) - katalog toko ini kecil (puluhan
        // produk), jadi biayanya murah, dan ini membuang KELAS BUG yang
        // berulang kali ketahuan dari laporan nyata: kata/angka generik apa
        // pun ("dan", angka 1 digit polos, "ml") bisa meloloskan hampir
        // seluruh katalog jadi "kandidat" SQL lalu produk yang benar kepotong
        // oleh limit SEBELUM sempat dinilai skornya - dan kata generik mana
        // yang bakal jadi masalah berikutnya tak mungkin ditebak semua lewat
        // daftar stopword. Dengan katalog sekecil ini, skor semua produk
        // sekalian jauh lebih murah & tahan terhadap kata generik apa pun.
        $candidates = Product::query()->where('public_status', 'active')->with('variants')->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        // Bobot per panjang kata (bukan hitung kata polos) - kata umum yang dipakai
        // banyak produk sejenis (mis. "zpt", "1000ppm") jangan sampai cukup buat
        // "menang" sendirian kalau kata paling spesifik (nama bahan aktif, mis.
        // "triacontanol") justru TIDAK match produk manapun. Ketahuan dari kasus
        // nyata: order "zpt triacontanol 1000ppm" ke-match ke "ZPT CPPU 1000ppm"
        // (produk lain) padahal "triacontanol" tidak ada di produk manapun.
        $totalWeight = array_sum(array_map('strlen', $words));

        foreach ($candidates as $p) {
            $variants = $p->variants->isNotEmpty() ? $p->variants->all() : [null];

            foreach ($variants as $v) {
                // Hanya nama produk + label varian (SKU dilewati supaya angka di
                // kode SKU tidak menimbulkan kecocokan palsu, mis. "1 kg" vs "5 kg").
                // Spasi dibuang biar "100 ml" di database cocok dgn "100ml" dari customer.
                $hay = str_replace(' ', '', strtolower($p->name.' '.($v->label ?? '')));
                $score = 0;

                foreach ($words as $w) {
                    if (str_contains($hay, $w)) {
                        $score += strlen($w);
                    }
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [$p, $v];
                }
            }
        }

        if ($best === null || $bestScore < max(2, (int) ceil($totalWeight / 2))) {
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
     * Saran "mungkin maksudmu ...?" saat matchItem() gagal cocok - dipakai HANYA
     * untuk pesan error/konfirmasi (tidak pernah dipakai buat auto-pilih/proses
     * order tanpa persetujuan customer, lihat handleItemFix()). Pakai similar_text
     * (persentase kemiripan ejaan) dibanding matchItem yang berbasis substring
     * per-kata - sengaja lebih longgar karena cuma dipakai memandu pelanggan,
     * bukan menentukan produk yang dibeli secara sepihak.
     *
     * Bisa balikin lebih dari satu kandidat kalau beberapa produk sama-sama
     * mirip (supaya customer diminta pilih nomor, bukan ditebak sepihak) -
     * tapi kandidat lain hanya diikutkan kalau skornya dekat dengan yang
     * terbaik (selisih <=10 poin), supaya tidak menampilkan daftar panjang
     * berisi produk yang jelas tidak relevan.
     *
     * @return list<string>
     */
    protected function suggestProductNames(string $raw, int $limit = 3): array
    {
        ['text' => $text] = $this->extractItemText($raw);
        $text = trim((string) preg_replace('/[^a-z0-9 ]+/i', ' ', strtolower($text)));

        if ($text === '') {
            return [];
        }

        $scored = [];
        foreach (Product::query()->where('public_status', 'active')->pluck('name') as $name) {
            similar_text($text, strtolower((string) $name), $percent);

            // Ambang 45% - cukup longgar buat typo/salah ketik wajar, tapi tetap
            // menyaring input yang memang sama sekali bukan nama produk di katalog.
            if ($percent >= 45.0) {
                $scored[] = ['name' => $name, 'percent' => $percent];
            }
        }

        if ($scored === []) {
            return [];
        }

        usort($scored, fn (array $a, array $b): int => $b['percent'] <=> $a['percent']);

        $top = $scored[0]['percent'];
        $scored = array_filter($scored, fn (array $s): bool => $s['percent'] >= $top - 10);

        return array_slice(array_column($scored, 'name'), 0, $limit);
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

        // Berat varian wajib ada - dipakai buat hitung ongkir & booking ekspedisi.
        // Sama seperti guard di checkout web (OrderController::store()).
        $missingWeightItems = collect($items)->filter(fn (array $i): bool => (int) ($i['weight'] ?? 0) <= 0);

        if ($missingWeightItems->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'items' => 'Berat produk berikut belum diatur, hubungi admin: '.$missingWeightItems->pluck('name')->unique()->implode(', ').'.',
            ]);
        }

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
                'channel' => 'whatsapp',
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

            app(\App\Support\ResellerCommissionService::class)->createPendingForOrder($order);

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

        $deadline = $order->created_at->copy()->addHours(Order::PAYMENT_EXPIRE_HOURS)->translatedFormat('d M Y, H:i');

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
            ."Admin akan memvalidasi pembayaranmu.\n\n"
            ."⏰ *Selesaikan pembayaran sebelum {$deadline} WIB* — order otomatis dibatalkan & stok dilepas kalau lewat batas waktu ini. 🙏";
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

        $deadline = $order->created_at->copy()->addHours(Order::PAYMENT_EXPIRE_HOURS)->translatedFormat('d M Y, H:i');

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
            .$transferText
            ."\n\n⏰ *Selesaikan pembayaran sebelum {$deadline} WIB* — kalau QR kedaluwarsa duluan, minta QR baru sebelum batas waktu ini; order otomatis dibatalkan kalau lewat.";
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

    /**
     * Catat pesan keluar yang dikirim LANGSUNG dari sini (bukan lewat return
     * string ke WaBotService::handle(), yang punya pencatatan sendiri) — tanpa
     * ini, transkrip form order & konfirmasi QRIS tidak pernah masuk
     * wa_messages, sehingga under-count di laporan penggunaan WA & order QRIS
     * via WA tidak kedeteksi (kode order tidak pernah tersimpan di mana pun).
     */
    protected function log(string $phone, string $message): void
    {
        try {
            WaMessage::create(['phone' => $phone, 'direction' => 'out', 'message' => $message]);
        } catch (\Throwable) {
            // Jangan ganggu pengiriman pesan kalau pencatatan gagal.
        }
    }
}
