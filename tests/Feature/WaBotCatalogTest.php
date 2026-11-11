<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Contracts\WhatsappGateway;
use App\Support\WaBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * /katalog dulu memakai limit(30) + orderBy('id'), jadi begitu produk aktif
 * lewat 30, produk yang BARU DITAMBAHKAN (id terbesar) tidak pernah muncul -
 * tanpa tanda apa pun bahwa daftarnya terpotong. Katalog sekarang dikirim
 * UTUH dalam satu pesan (gateway unofficial memuat 65.536 karakter), dan
 * splitReply() cuma jaring pengaman kalau suatu hari benar-benar kelewat.
 */
class WaBotCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Harus sama dengan WaBotService::MAX_MESSAGE_LENGTH. */
    private const BATAS_PESAN = 60000;

    private function bot(): WaBotService
    {
        return app(WaBotService::class);
    }

    private function tambahProduk(string $nama, int $stok = 10): string
    {
        $product = Product::query()->create([
            'category_id' => 1,
            'sku' => 'UJI-'.strtoupper(bin2hex(random_bytes(4))),
            'slug' => Str::slug($nama).'-'.bin2hex(random_bytes(3)),
            'name' => $nama,
            'description' => 'Produk uji.',
            'public_status' => 'active',
            'catalog_status' => 'available',
            'published_at' => now(),
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => $product->sku.'-A',
            'label' => '100 ml',
            'price' => 50000,
            'stock' => $stok,
            'weight_grams' => 200,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return $nama;
    }

    public function test_produk_yang_baru_ditambahkan_tetap_muncul_di_katalog(): void
    {
        // Lewati ambang lama (30 produk) supaya bug aslinya benar-benar terpicu.
        $jumlahAwal = Product::query()->where('public_status', 'active')->available()->count();

        for ($i = $jumlahAwal; $i < 40; $i++) {
            $this->tambahProduk('Produk Pengisi '.$i);
        }

        $terbaru = $this->tambahProduk('Tebukonazole Paling Baru');

        $balasan = $this->bot()->buildReply('katalog');

        $this->assertNotNull($balasan);
        $this->assertStringContainsString($terbaru, $balasan);
        // Tidak ada halaman: produk pertama DAN terakhir ada di balasan yang sama.
        $this->assertStringContainsString('Produk Pengisi '.$jumlahAwal, $balasan);
        $this->assertStringNotContainsString('Halaman', $balasan);
    }

    public function test_katalog_dikirim_sebagai_satu_pesan_tanpa_halaman(): void
    {
        // Jauh lebih banyak dari katalog produksi sekarang (35 produk).
        for ($i = 0; $i < 80; $i++) {
            $this->tambahProduk('Produk Borongan Dengan Nama Panjang Sekali '.$i);
        }

        $terbaru = $this->tambahProduk('Produk Terakhir Yang Harus Terkirim');

        // Tangkap pesan yang BENAR-BENAR dikirim ke gateway.
        $terkirim = [];
        $this->instance(WhatsappGateway::class, new class($terkirim) implements WhatsappGateway
        {
            public function __construct(public array &$terkirim) {}

            public function sendMessage(string $phone, string $message): bool
            {
                $this->terkirim[] = $message;

                return true;
            }

            public function sendImage(string $phone, string $imageUrl, string $caption = ''): bool
            {
                return true;
            }
        });

        $this->bot()->handle('6281234567890', '/katalog');

        // Inilah keputusannya: katalog TIDAK dipecah dan TIDAK dihalaman.
        $this->assertCount(1, $terkirim, 'Katalog seharusnya cukup dalam satu pesan.');
        $this->assertLessThanOrEqual(self::BATAS_PESAN, mb_strlen($terkirim[0]));

        // Dan tidak ada yang hilang - produk terakhir ikut terkirim.
        $this->assertStringContainsString($terbaru, $terkirim[0]);
        $this->assertStringNotContainsString('Halaman', $terkirim[0]);
    }

    public function test_balasan_pendek_tidak_dipecah(): void
    {
        $split = new ReflectionMethod(WaBotService::class, 'splitReply');
        $split->setAccessible(true);

        $pendek = "Halo!\n\nIni balasan biasa.";

        $this->assertSame([$pendek], $split->invoke($this->bot(), $pendek));
    }

    public function test_pemecahan_tidak_membelah_blok_produk_di_tengah(): void
    {
        $split = new ReflectionMethod(WaBotService::class, 'splitReply');
        $split->setAccessible(true);

        // Kasus ekstrem yang tidak realistis, murni untuk menguji jaring
        // pengamannya: tiap blok utuh harus tetap utuh di salah satu pesan.
        $blok = [];
        for ($i = 1; $i <= 2000; $i++) {
            $blok[] = "{$i}. *Produk Uji {$i}*\n   • 100 ml — Rp50.000";
        }
        $teks = implode("\n\n", $blok);

        $parts = $split->invoke($this->bot(), $teks);

        $this->assertGreaterThan(1, count($parts));

        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(self::BATAS_PESAN, mb_strlen($part));
        }

        foreach ($blok as $b) {
            $cocok = array_filter($parts, fn (string $p): bool => str_contains($p, $b));
            $this->assertCount(1, $cocok, "Blok \"{$b}\" terbelah atau hilang saat dipecah.");
        }
    }

    public function test_produk_tanpa_stok_tidak_muncul_di_katalog(): void
    {
        Product::query()->update(['public_status' => 'draft']);

        $bestok = $this->tambahProduk('Produk Bestok');
        $habis = $this->tambahProduk('Produk Stok Habis', 0);

        $balasan = (string) $this->bot()->buildReply('katalog');

        $this->assertStringContainsString($bestok, $balasan);
        $this->assertStringNotContainsString($habis, $balasan);
    }

    public function test_katalog_kosong_dijawab_jelas(): void
    {
        Product::query()->update(['public_status' => 'draft']);

        $this->assertStringContainsString(
            'Belum ada produk',
            (string) $this->bot()->buildReply('katalog')
        );
    }
}
