<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WaMessage;
use App\Support\Contracts\WhatsappGateway;
use Illuminate\Support\Str;

/**
 * Processor pesan WhatsApp masuk.
 *
 * Perintah: /pesan (order), /katalog (daftar produk), /cari <nama-produk>,
 *   /cek-{id} (detail produk), /cek-ongkir <wilayah>, /tanya-admin (ke admin).
 */
class WaBotService
{
    public function __construct(
        private readonly WhatsappGateway $wablas,
    ) {
    }

    /**
     * Proses pesan masuk lalu kirim balasannya via WhatsApp.
     */
    public function handle(string $phone, string $message): void
    {
        // Admin memvalidasi pembayaran via WA: balas "ORD-055 PAID".
        if ($this->tryAdminPaymentValidation($phone, $message)) {
            return;
        }

        $order = app(WaOrderService::class);

        if ($this->isInfoCommand($message)) {
            // Perintah info eksplisit selalu dilayani, walau sedang dalam sesi
            // order (sesi tidak diganggu, jadi pelanggan bisa lanjut memesan).
            $reply = $this->buildReply($message, $phone);

            // Ingatkan kalau masih ada pesanan yang belum selesai.
            if ($reply !== null && $reply !== '' && $order->hasSession($phone)) {
                $hint = $order->continueHint($phone);

                if ($hint !== null) {
                    $reply .= "\n\n".$hint;
                }
            }
        } elseif ($order->hasSession($phone) || $order->isTrigger($message)) {
            // Sesi order aktif atau perintah /pesan -> masuk alur pemesanan.
            $reply = $order->handle($phone, $message);
        } else {
            $reply = $this->buildReply($message, $phone);
        }

        if ($reply !== null && $reply !== '') {
            $this->wablas->sendMessage($phone, $reply);
        }

        // Rekam transkrip (masuk + keluar) untuk konteks AI & riwayat.
        // Dilakukan setelah balasan dihitung, jadi riwayat /tanya = giliran sebelumnya.
        $this->record($phone, 'in', $message);
        if ($reply !== null && $reply !== '') {
            $this->record($phone, 'out', $reply);
        }
    }

    protected function record(string $phone, string $direction, string $message): void
    {
        try {
            WaMessage::create([
                'phone' => $phone,
                'direction' => $direction,
                'message' => $message,
            ]);
        } catch (\Throwable) {
            // Jangan ganggu balasan kalau pencatatan gagal.
        }
    }

    /**
     * Perintah info stateless yang boleh dijalankan kapan saja (termasuk saat
     * sedang dalam sesi order): /cek-produk, cek-{id}, /cari ..., /tanya ...
     */
    protected function isInfoCommand(string $message): bool
    {
        $text = strtolower(trim($message));

        return in_array($text, ['/katalog', 'katalog'], true)
            || preg_match('/^\/?cek-\d+$/', $text) === 1
            || preg_match('/^\/?cari[\s\-]+/', $text) === 1
            || preg_match('/^\/?tanya-admin\b/', $text) === 1
            || preg_match('/^\/?cek-ongkir[\s\-]+/', $text) === 1;
    }

    /**
     * Bangun teks balasan dari pesan masuk (murni, tanpa kirim — mudah dites).
     */
    public function buildReply(string $message, ?string $phone = null): ?string
    {
        $text = strtolower(trim($message));

        // Daftar produk: /katalog.
        if (in_array($text, ['/katalog', 'katalog'], true)) {
            return $this->listProducts();
        }

        if (preg_match('/^\/?cek-(\d+)$/', $text, $matches)) {
            return $this->productDetail((int) $matches[1]);
        }

        // Pencarian: "/cari nama-produk".
        if (preg_match('/^\/?cari[\s\-]+(.+)$/i', trim($message), $matches)) {
            return $this->searchProducts(trim($matches[1]));
        }

        // Tanya admin: "/tanya-admin ..." -> diarahkan ke admin (bukan AI).
        if (preg_match('/^\/?tanya-admin\b/i', trim($message))) {
            return $this->askAdmin();
        }

        // Cek ongkir: "/cek-ongkir <wilayah>".
        if (preg_match('/^\/?cek-ongkir[\s\-]+(.+)$/i', trim($message), $matches)) {
            return $this->shippingEstimate(trim($matches[1]));
        }

        // Konfirmasi pembayaran: "sudah bayar", "udah transfer", "sdh tf", "bukti transfer", "lunas".
        if ($phone !== null
            && (preg_match('/\b(sudah|udah|sdh|telah|barusan|baru\s*saja)\s*(bayar|transfer|tf|trf|lunas|membayar|mentransfer|byr)\b/i', $text) === 1
                || preg_match('/\bbukti\s*(transfer|tf|bayar|pembayaran|byr)\b/i', $text) === 1
                || preg_match('/^\s*(lunas|paid|sudah\s*ditransfer)\b/i', $text) === 1)) {
            return $this->paymentConfirmation($phone);
        }

        // Sapaan / minta bantuan -> tampilkan menu. Cocok juga untuk link wa.me
        // berisi teks "halo Sobat Akar Tani Kimia!" (match jika DIAWALI salah satu kata ini).
        if (preg_match('/^\s*\/?(halo|hai|hi|help|menu|bantuan|mulai|assalamualaikum|selamat|pagi|siang|sore|malam|met|p)\b/i', $text)) {
            return $this->help();
        }

        // Pesan lain di luar format: DIDIAMKAN (bot tidak membalas),
        // supaya tidak mengganggu obrolan biasa / chat dengan admin.
        return null;
    }

    /**
     * Tangani pesan bergambar. Kalau pelanggan punya pesanan menunggu bayar,
     * anggap itu bukti transfer -> teruskan ke admin + balas pelanggan.
     */
    public function handleImage(string $phone, string $imageUrl, string $caption = ''): void
    {
        $this->record($phone, 'in', '[gambar] '.($caption !== '' ? $caption : 'tanpa caption'));

        $order = $this->pendingOrder($phone);

        if ($order === null) {
            // Tak ada pesanan pending -> kalau ada caption, proses sebagai teks biasa.
            if (trim($caption) !== '') {
                $reply = $this->buildReply($caption, $phone);

                if ($reply !== null && $reply !== '') {
                    $this->wablas->sendMessage($phone, $reply);
                    $this->record($phone, 'out', $reply);
                }
            }

            return;
        }

        $this->notifyAdminPayment($order, $phone, $imageUrl);

        $reply = "🙏 Bukti transfer untuk pesanan *{$order->code}* sudah kami terima. "
            ."Admin akan segera *memverifikasi* lalu pesananmu diproses. Terima kasih! 😊";
        $this->wablas->sendMessage($phone, $reply);
        $this->record($phone, 'out', $reply);
    }

    protected function pendingOrder(string $phone): ?Order
    {
        return Order::query()
            ->with('user')
            ->whereHas('user', fn ($q) => $q->where('phone', $phone))
            ->where('status', 'pending_payment')
            ->latest('id')
            ->first();
    }

    /**
     * Beri tahu admin bahwa pelanggan konfirmasi/kirim bukti bayar.
     * Nomor admin diambil dari tabel users (role=admin) — sumber kebenaran.
     */
    protected function notifyAdminPayment(Order $order, string $phone, ?string $imageUrl): void
    {
        $customer = $this->normalizePhone($phone);

        $admins = User::query()
            ->where('role', 'admin')
            ->whereNotNull('phone')
            ->pluck('phone')
            ->map(fn ($p): string => $this->normalizePhone((string) $p))
            ->filter()
            ->reject(fn (string $p): bool => $p === $customer) // jangan kirim ke pelanggan itu sendiri
            ->unique()
            ->values();

        if ($admins->isEmpty()) {
            return;
        }

        $name = $order->user?->name ?? $phone;
        $caption = "💸 *Konfirmasi pembayaran*\n"
            ."Pelanggan: {$name} (wa.me/{$customer})\n"
            ."Pesanan: *{$order->code}* — ".$this->money((int) $order->grand_total)."\n\n"
            .(($imageUrl !== null && $imageUrl !== '') ? "Bukti transfer:\n".$imageUrl."\n\n" : '')
            ."Cek rekening, lalu *validasi*:\n"
            ."• lewat panel admin, atau\n"
            ."• balas pesan ini: *{$order->code} PAID*";

        foreach ($admins as $adminPhone) {
            if ($imageUrl !== null && $imageUrl !== '') {
                $this->wablas->sendImage($adminPhone, $imageUrl, $caption);
            } else {
                $this->wablas->sendMessage($adminPhone, $caption);
            }
        }
    }

    /**
     * Normalisasi nomor ke format internasional tanpa "+": 08xx -> 628xx.
     */
    protected function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if ($digits !== '' && str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Validasi pembayaran via WA (khusus admin): balas "ORD-055 PAID".
     * Return true kalau pesan ini perintah validasi (sudah ditangani).
     */
    protected function tryAdminPaymentValidation(string $phone, string $message): bool
    {
        if (preg_match('/(ord-\d+)[^a-z0-9]*(paid|valid|lunas|acc|sah)\b/i', trim($message), $m) !== 1) {
            return false;
        }

        // Hanya admin (cocokkan nomor pengirim ke user role=admin).
        if ($this->adminByPhone($phone) === null) {
            $this->wablas->sendMessage($phone, "Validasi pembayaran hanya untuk admin. Kalau kamu sudah transfer, ketik *sudah bayar* atau kirim *bukti transfer* ya 🙏");

            return true;
        }

        $raw = strtoupper($m[1]);
        $num = (int) substr($raw, 4);
        $codes = array_values(array_unique([$raw, 'ORD-'.$num, 'ORD-'.str_pad((string) $num, 3, '0', STR_PAD_LEFT)]));
        $order = Order::query()->with('user')->whereIn('code', $codes)->first();

        if ($order === null) {
            $this->wablas->sendMessage($phone, "❌ Pesanan *{$raw}* tidak ditemukan.");

            return true;
        }

        if ($order->status !== 'pending_payment') {
            $this->wablas->sendMessage($phone, "ℹ️ Pesanan *{$order->code}* statusnya sudah *{$order->status}*, tak perlu divalidasi lagi.");

            return true;
        }

        $result = app(OrderPaymentService::class)->markPaid($order, 'admin');

        $this->wablas->sendMessage(
            $phone,
            "✅ Pembayaran *{$order->code}* berhasil divalidasi.\n".$result['message']
            .(($result['order_no'] ?? null) ? "\nResi: ".$result['order_no'] : '')
        );

        // Notifikasi ke pelanggan sudah ditangani OrderPaymentService::markPaid.

        return true;
    }

    /** Cari user admin berdasarkan nomor (cocokkan format 62/08). */
    protected function adminByPhone(string $phone): ?User
    {
        $norm = $this->normalizePhone($phone);
        $local = str_starts_with($norm, '62') ? '0'.substr($norm, 2) : $norm;
        $candidates = array_values(array_unique([$phone, $norm, $local]));

        return User::query()->where('role', 'admin')->whereIn('phone', $candidates)->first();
    }

    /**
     * Balas konfirmasi pembayaran (teks) dari pelanggan + rujuk pesanan pending
     * + beri tahu admin.
     */
    protected function paymentConfirmation(string $phone): string
    {
        $order = $this->pendingOrder($phone);

        if ($order === null) {
            return "🙏 Terima kasih infonya! Tapi kami belum menemukan pesanan yang menunggu pembayaran atas nomor ini.\n\n"
                ."Kalau kamu baru transfer untuk pesanan tertentu, balas dengan *kode pesanannya* (mis. ORD-001). "
                ."Atau ketik */pesan* untuk memesan.";
        }

        $this->notifyAdminPayment($order, $phone, null);

        return "🙏 Terima kasih! Info pembayaran untuk pesanan *{$order->code}* (total ".$this->money((int) $order->grand_total).") sudah kami terima.\n\n"
            ."Admin akan segera *memverifikasi* ke rekening, lalu pesananmu diproses. Mohon tunggu konfirmasinya ya 😊\n\n"
            ."Kalau ada *bukti transfer*, kirim saja gambarnya di sini.";
    }

    protected function listProducts(): string
    {
        $products = Product::query()
            ->with(['variants', 'category'])
            ->orderBy('id')
            ->limit(30)
            ->get();

        $blocks = [];

        foreach ($products as $p) {
            // Hanya varian aktif & ada stok.
            $inStock = $p->variants->filter(
                fn ($v): bool => $v->is_active && (int) $v->stock > 0
            )->values();

            if ($inStock->isEmpty()) {
                continue; // lewati produk yang semua variannya habis
            }

            $variantLines = $inStock->map(
                fn ($v): string => "   • {$v->label} — ".$this->money((int) $v->price)
            )->implode("\n");

            $blocks[] = "{$p->id}. *{$p->name}*\n{$variantLines}";
        }

        if ($blocks === []) {
            return "Belum ada produk yang tersedia saat ini.";
        }

        return "📦 *Daftar Produk Sobat Akar Tani Kimia*\n\n".implode("\n\n", $blocks)
            ."\n\nLihat detail ketik */cek-{id}*, atau */pesan* untuk memesan.";
    }

    protected function searchProducts(string $query): string
    {
        $terms = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($terms === []) {
            return "Ketik kata kunci setelah */cari*. Contoh: */cari pupuk kandang*";
        }

        // Coba AND (semua kata harus cocok); kalau kosong, longgarkan ke OR.
        $products = $this->runSearch($terms, false);

        if ($products->isEmpty()) {
            $products = $this->runSearch($terms, true);
        }

        if ($products->isEmpty()) {
            return "🔍 Tidak ada produk yang cocok dengan \"{$query}\".\n"
                ."Coba kata kunci lain, atau ketik */katalog* untuk lihat semua.";
        }

        $lines = $products->map(
            fn (Product $p): string => "{$p->id}. {$p->name} — ".$this->money((int) $p->price)." (stok {$p->stock})"
        )->implode("\n");

        $firstId = $products->first()->id;

        return "🔍 Hasil pencarian \"{$query}\":\n\n{$lines}\n\n"
            ."Untuk pesan ketik */pesan*, atau lihat detail */cek-{$firstId}*.";
    }

    /**
     * @param  array<int, string>  $terms
     * @return \Illuminate\Support\Collection<int, Product>
     */
    protected function runSearch(array $terms, bool $matchAny): \Illuminate\Support\Collection
    {
        return Product::query()
            ->whereSearchTerms($terms, $matchAny)
            ->orderBy('id')
            ->limit(20)
            ->get();
    }

    protected function money(int $value): string
    {
        return 'Rp'.number_format($value, 0, ',', '.');
    }

    /**
     * Asisten AI (Gemini) — jawab pertanyaan pelanggan berdasarkan katalog produk
     * (grounding) supaya tidak mengarang harga/produk.
     */
    protected function answerQuestion(string $question, ?string $phone = null): string
    {
        // Pertanyaan ongkir -> arahkan ke /ongkir (deterministik, hemat kuota AI).
        if (preg_match('/(ongkir|ongkos|biaya\s*kirim|biaya\s*pengiriman|tarif\s*kirim)/i', $question)) {
            return "Untuk cek ongkir, ketik */ongkir <nama wilayah>* 🚚\nContoh: */ongkir singosari*";
        }

        // Riwayat 6 pesan terakhir (konteks percakapan) supaya bisa jawab follow-up.
        $history = '';
        if ($phone !== null) {
            $recent = WaMessage::query()->where('phone', $phone)->latest('id')->limit(6)->get()->reverse();

            if ($recent->isNotEmpty()) {
                $history = "Riwayat percakapan terakhir:\n".$recent->map(
                    fn (WaMessage $m): string => ($m->direction === 'in' ? 'Pelanggan' : 'Bot').': '.Str::limit((string) $m->message, 180)
                )->implode("\n")."\n\n";
            }
        }

        $catalog = Product::query()->with(['category', 'variants'])->orderBy('id')->limit(40)->get()
            ->map(function (Product $p): string {
                $category = $p->category->name ?? '-';
                $desc = $p->short_description
                    ?: Str::limit(trim(strip_tags((string) $p->description)), 60);

                $variants = $p->variants
                    ->map(fn ($v): string => "{$v->label} ".$this->money((int) $v->price)." (stok {$v->stock})")
                    ->implode('; ');
                $variantText = $variants !== '' ? " | Varian: {$variants}" : " | Harga: ".$this->money((int) $p->price);

                return "- {$p->name} (kategori {$category}): {$desc}{$variantText}";
            })->implode("\n");

        $prompt = "Kamu asisten WhatsApp toko pertanian \"Sobat Akar Tani Kimia\". "
            ."Jawab ramah & singkat (maksimal 4 kalimat) dalam Bahasa Indonesia, "
            ."HANYA berdasarkan katalog di bawah + pengetahuan pertanian umum. "
            ."Jika produk yang ditanya tidak ada di katalog, katakan belum tersedia. "
            ."Jika relevan, sarankan ketik */pesan* untuk memesan atau */cari* untuk mencari produk. "
            ."Jika pertanyaan soal ongkir/biaya kirim/pengiriman, JANGAN sebut nominal — arahkan ketik */ongkir <wilayah>*. "
            ."Jika produk punya beberapa varian, sebutkan SEMUA varian beserta harganya. "
            ."Jangan mengarang harga atau produk.\n\n"
            ."Katalog produk:\n{$catalog}\n\n"
            .$history
            ."Pertanyaan pelanggan terbaru: \"{$question}\"\n\nJawaban:";

        $answer = app(GeminiService::class)->generateText($prompt, [
            'temperature' => 0.6,
            'max_tokens' => 500,
            'thinking_budget' => 0,
        ]);

        if ($answer === null || $answer === '') {
            return "Maaf, asisten sedang tidak tersedia. Coba lagi nanti, atau ketik */cek-produk* untuk lihat produk, "
                ."*/cari kata* untuk mencari, atau */pesan* untuk memesan. 🙏";
        }

        return $answer;
    }

    /**
     * Tanya admin — pertanyaan pelanggan diarahkan ke admin (bukan AI).
     */
    protected function askAdmin(): string
    {
        return "💬 *Tanya Admin*\n\n"
            ."Silakan tulis pertanyaanmu langsung di chat ini ya — admin kami akan membalas secepatnya. 🙏\n\n"
            ."Mau memesan? Ketik */pesan*. Lihat produk? Ketik */katalog*.";
    }

    /**
     * Cek estimasi ongkir mandiri (tanpa /pesan). Ambil wilayah pertama yang cocok,
     * hitung untuk berat default 1 kg.
     */
    protected function shippingEstimate(string $wilayah): string
    {
        $shipping = app(ShippingService::class);
        $destinations = $shipping->searchDestinations($wilayah, 1);

        if ($destinations === []) {
            return "🚚 Wilayah \"{$wilayah}\" tidak ditemukan. Coba nama kecamatan/kota yang lebih spesifik.";
        }

        $dest = $destinations[0];
        $options = $shipping->costOptions($dest['id'], ShippingService::DEFAULT_WEIGHT);

        if ($options === []) {
            return "🚚 Maaf, ongkir ke {$dest['label']} belum tersedia saat ini.";
        }

        $lines = collect($options)->map(
            fn (array $o): string => "• {$o['service']} — {$o['price']}".
                (($o['estimate'] ?? '') !== '' ? " (estimasi {$o['estimate']})" : '')
        )->implode("\n");

        return "🚚 *Estimasi ongkir* ke:\n{$dest['label']}\n_(perkiraan berat 1 kg)_\n\n{$lines}\n\n"
            ."Ongkir final mengikuti berat & produk saat */pesan*.";
    }

    protected function productDetail(int $id): string
    {
        $product = Product::query()->with(['variants', 'category'])->find($id);

        if ($product === null) {
            return "❌ Produk dengan ID {$id} tidak ditemukan.\nKetik */katalog* untuk lihat daftar produk.";
        }

        $price = 'Rp'.number_format((int) $product->price, 0, ',', '.');
        $detail = "🛒 *{$product->name}*\n"
            ."Kategori: ".($product->category->name ?? '-')."\n"
            ."Harga: {$price}\n"
            ."Stok: {$product->stock}\n"
            ."Terjual: {$product->sold_count}";

        $description = $product->short_description
            ?: Str::limit(trim(strip_tags((string) $product->description)), 200);

        if ($description !== '') {
            $detail .= "\n\n📝 {$description}";
        }

        if ($product->variants->isNotEmpty()) {
            $variants = $product->variants->map(function ($v): string {
                $vp = 'Rp'.number_format((int) $v->price, 0, ',', '.');

                return "• {$v->label} — {$vp} (stok {$v->stock})";
            })->implode("\n");

            $detail .= "\n\n*Varian:*\n{$variants}";
        }

        return $detail;
    }

    protected function help(): string
    {
        return "Halo! 👋 Selamat datang di *Sobat Akar Tani Kimia*.\n\n"
            ."Perintah yang tersedia:\n"
            ."• */pesan* — buat pesanan (isi form pesanan)\n"
            ."• */katalog* — lihat daftar produk\n"
            ."• */cari nama-produk* — cari produk (contoh: /cari pupuk)\n"
            ."• */cek-ongkir wilayah* — cek estimasi ongkir (contoh: /cek-ongkir nganjuk)\n"
            ."• */tanya-admin* — tanya langsung ke admin";
    }
}
