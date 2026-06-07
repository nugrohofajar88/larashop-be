<?php

namespace App\Support;

use App\Models\Product;
use App\Models\WaMessage;
use Illuminate\Support\Str;

/**
 * Processor pesan WhatsApp masuk (bot tes).
 *
 * Perintah:
 *   /cek-produk   -> daftar produk: {id}-{nama}-{varian}-{qty}
 *   cek-{id}      -> detail produk (mis. cek-1)
 */
class WaBotService
{
    public function __construct(
        private readonly WablasService $wablas,
    ) {
    }

    /**
     * Proses pesan masuk lalu kirim balasannya via WhatsApp.
     */
    public function handle(string $phone, string $message): void
    {
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

        return $text === '/cek-produk'
            || $text === 'cek-produk'
            || preg_match('/^cek-\d+$/', $text) === 1
            || preg_match('/^\/?cari[\s\-]+/', $text) === 1
            || preg_match('/^\/?tanya[\s\-]+/', $text) === 1
            || preg_match('/^\/?ongkir[\s\-]+/', $text) === 1;
    }

    /**
     * Bangun teks balasan dari pesan masuk (murni, tanpa kirim — mudah dites).
     */
    public function buildReply(string $message, ?string $phone = null): ?string
    {
        $text = strtolower(trim($message));

        if ($text === '/cek-produk' || $text === 'cek-produk') {
            return $this->listProducts();
        }

        if (preg_match('/^cek-(\d+)$/', $text, $matches)) {
            return $this->productDetail((int) $matches[1]);
        }

        // Pencarian: "/cari pupuk kandang" atau "/cari-pupuk kandang".
        if (preg_match('/^\/?cari[\s\-]+(.+)$/i', trim($message), $matches)) {
            return $this->searchProducts(trim($matches[1]));
        }

        // Tanya AI: "/tanya pupuk apa yang bagus untuk cabai?"
        if (preg_match('/^\/?tanya[\s\-]+(.+)$/i', trim($message), $matches)) {
            return $this->answerQuestion(trim($matches[1]), $phone);
        }

        // Cek ongkir: "/ongkir singosari" (estimasi berat 1 kg).
        if (preg_match('/^\/?ongkir[\s\-]+(.+)$/i', trim($message), $matches)) {
            return $this->shippingEstimate(trim($matches[1]));
        }

        // Sapaan / minta bantuan -> tampilkan menu. Cocok juga untuk link wa.me
        // berisi teks "halo SobatTani!" (match jika DIAWALI salah satu kata ini).
        if (preg_match('/^\s*\/?(halo|hai|hi|help|menu|bantuan|mulai|assalamualaikum|selamat)\b/i', $text)) {
            return $this->help();
        }

        // Pesan lain di luar format: DIDIAMKAN (bot tidak membalas),
        // supaya tidak mengganggu obrolan biasa / chat dengan admin.
        return null;
    }

    protected function listProducts(): string
    {
        $products = Product::query()
            ->with(['variants', 'category'])
            ->orderBy('id')
            ->limit(30)
            ->get();

        if ($products->isEmpty()) {
            return "Belum ada produk di katalog.";
        }

        $lines = $products->map(function (Product $p): string {
            $variant = $p->variants->firstWhere('is_default', true) ?? $p->variants->first();
            $variantLabel = $variant->label ?? '-';

            return "{$p->id}-{$p->name}-{$variantLabel}-{$p->stock}";
        })->implode("\n");

        $firstId = $products->first()->id;

        return "📦 *Daftar Produk SobatTani*\n(format: id-nama-varian-stok)\n\n{$lines}\n\nLihat detail ketik: *cek-{id}* (contoh: cek-{$firstId})";
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
                ."Coba kata kunci lain, atau ketik */cek-produk* untuk lihat semua.";
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

        $prompt = "Kamu asisten WhatsApp toko pertanian \"SobatTani\". "
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
            fn (array $o): string => "• {$o['service']} — {$o['price']} (estimasi {$o['estimate']})"
        )->implode("\n");

        return "🚚 *Estimasi ongkir* ke:\n{$dest['label']}\n_(perkiraan berat 1 kg)_\n\n{$lines}\n\n"
            ."Ongkir final mengikuti berat & produk saat */pesan*.";
    }

    protected function productDetail(int $id): string
    {
        $product = Product::query()->with(['variants', 'category'])->find($id);

        if ($product === null) {
            return "❌ Produk dengan ID {$id} tidak ditemukan.\nKetik */cek-produk* untuk lihat daftar.";
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
        return "Halo! 👋 Ini bot *SobatTani* (mode tes).\n\n"
            ."Perintah yang tersedia:\n"
            ."• */pesan* — buat pesanan (pilih produk → ongkir → alamat)\n"
            ."• */cek-produk* — lihat daftar produk\n"
            ."• */cari kata* — cari produk (contoh: /cari pupuk kandang)\n"
            ."• */tanya ...* — tanya asisten (contoh: /tanya pupuk untuk cabai apa?)\n"
            ."• */ongkir wilayah* — cek estimasi ongkir (contoh: /ongkir singosari)\n"
            ."• *cek-{id}* — lihat detail produk (contoh: cek-1)";
    }
}
