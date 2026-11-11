<?php

namespace Tests\Unit;

use App\Support\WaOrderService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * parseForm() itu murni (tak menyentuh DB/jaringan), jadi diuji langsung lewat
 * refleksi tanpa membangun container Laravel.
 */
class WaOrderFormParsingTest extends TestCase
{
    /** @return array{name:string,phone:string,address:string,kelurahan:string,kecamatan:string,kota:string,items:array<int,string>} */
    private function parse(string $text): array
    {
        $service = (new ReflectionClass(WaOrderService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(WaOrderService::class, 'parseForm');
        $method->setAccessible(true);

        return $method->invoke($service, $text);
    }

    /**
     * REGRESI: pelanggan menyalin balik pesan konfirmasi bot (yang juga memuat
     * baris "Alamat: ..."). Dulu field Alamat menelan SELURUH sisa pesan -
     * 779 karakter - sehingga booking ke Komerce ditolak dengan
     * "receiver address must not exceed 250 characters".
     */
    public function test_alamat_tidak_menelan_sisa_pesan_saat_ringkasan_bot_disalin_balik(): void
    {
        $text = "📋 *Konfirmasi Pesanan*\n\n"
            ."Nama: Sulis\n"
            ."HP: 6281234567890\n"
            ."Alamat: tawang 02/04 ,(rumah utara sendiri)\n"
            ."Tujuan: NGANDONG, EROMOKO, WONOGIRI, 57663\n\n"
            ."• 1x Triacontanol 1000ppm (100 ml) = Rp32.000\n"
            ."• 1x Brashinolide 100ppm EC (60 ml) = Rp59.000\n\n"
            ."Subtotal: Rp91.000\n"
            ."Ongkir (JNT - EZ): Rp18.000\n"
            ."Kode unik: Rp858\n"
            ."*Total: Rp109.858*\n\n"
            ."⚠️ *Pastikan TUJUAN di atas sudah benar* — ini yang menentukan ongkir & area pengiriman.\n\n"
            ."• Balas *YA* untuk konfirmasi (Transfer/QRIS).\n"
            ."• Ketik *batal* untuk membatalkan.\n"
            ."<~ Ini gimana kakk";

        $form = $this->parse($text);

        $this->assertSame('tawang 02/04 ,(rumah utara sendiri)', $form['address']);
        $this->assertLessThan(250, mb_strlen($form['address']));
    }

    public function test_form_normal_terbaca_utuh(): void
    {
        $form = $this->parse(
            "Nama: Budi Santoso\n"
            ."No HP: 081234567890\n"
            ."Alamat: Jl. Mawar No. 5 RT 02/RW 04\n"
            ."Kelurahan/Desa: Ngandong\n"
            ."Kecamatan: Eromoko\n"
            ."Kota/Kabupaten: Wonogiri\n"
            ."Pesanan:\n- Pupuk NPK 5 kg x2\n- Pestisida Organik 1 liter"
        );

        $this->assertSame('Budi Santoso', $form['name']);
        $this->assertSame('081234567890', $form['phone']);
        $this->assertSame('Jl. Mawar No. 5 RT 02/RW 04', $form['address']);
        $this->assertSame('Ngandong', $form['kelurahan']);
        $this->assertSame('Eromoko', $form['kecamatan']);
        $this->assertSame('Wonogiri', $form['kota']);
        $this->assertSame(['Pupuk NPK 5 kg x2', 'Pestisida Organik 1 liter'], $form['items']);
    }

    /** Alamat yang sengaja ditulis beberapa baris harus tetap tertangkap utuh. */
    public function test_alamat_multi_baris_tetap_utuh(): void
    {
        $form = $this->parse(
            "Nama: Siti\n"
            ."No HP: 08123\n"
            ."Alamat: Jl. Mawar No. 5\nRT 02 RW 04\ndepan masjid\n"
            ."Kelurahan/Desa: Ngandong\n"
            ."Kecamatan: Eromoko\n"
            ."Kota/Kabupaten: Wonogiri\n"
            ."Pesanan:\n- Pupuk NPK 5 kg"
        );

        $this->assertSame('Jl. Mawar No. 5 RT 02 RW 04 depan masjid', $form['address']);
        $this->assertSame('Ngandong', $form['kelurahan']);
    }

    /** REGRESI lama: form pre-filled yang masih kosong tak boleh bocor antarfield. */
    public function test_form_kosong_tidak_bocor_antarfield(): void
    {
        $form = $this->parse(
            "Nama: \nNo HP: \nAlamat: \nKelurahan/Desa: \nKecamatan: \nKota/Kabupaten: \nPesanan:\n- "
        );

        $this->assertSame('', $form['name']);
        $this->assertSame('', $form['address']);
        $this->assertSame('', $form['kelurahan']);
        $this->assertSame('', $form['kecamatan']);
        $this->assertSame('', $form['kota']);
        $this->assertSame([], $form['items']);
    }
}
