<?php

namespace App\Support;

/**
 * Hitung berat yang ditagih kurir (chargeable weight) = nilai terbesar antara
 * berat asli dan berat volumetrik. RajaOngkir hanya menerima `weight`, jadi
 * kalkulasi volumetrik dilakukan di sisi kita lalu dikirim sebagai berat efektif.
 *
 * volumetrik (gram) = (p × l × t cm) ÷ divisor × 1000   (divisor domestik = 6000)
 */
class ShippingWeight
{
    public static function divisor(): int
    {
        $divisor = (int) config('services.shipping.volumetric_divisor', 6000);

        return $divisor > 0 ? $divisor : 6000;
    }

    /**
     * Berat volumetrik (gram) untuk 1 unit dari dimensi (cm). 0 jika dimensi kosong.
     */
    public static function unitVolumetricGrams(float|int|null $length, float|int|null $width, float|int|null $height): int
    {
        $cm3 = (float) $length * (float) $width * (float) $height;

        return $cm3 > 0 ? (int) round($cm3 / self::divisor() * 1000) : 0;
    }

    /**
     * Berat ditagih (gram) untuk satu pengiriman. Membandingkan total berat asli
     * vs total volumetrik, ambil yang terbesar, minimal 1000 gram (1 kg).
     *
     * @param  array<int, array{weight_grams?: int, length_cm?: float|int|null, width_cm?: float|int|null, height_cm?: float|int|null, qty?: int}>  $lines
     */
    public static function chargeableGrams(array $lines): int
    {
        $actual = 0;
        $volumetric = 0;

        foreach ($lines as $line) {
            $qty = max(1, (int) ($line['qty'] ?? 1));
            $actual += max(0, (int) ($line['weight_grams'] ?? 0)) * $qty;
            $volumetric += self::unitVolumetricGrams(
                $line['length_cm'] ?? 0,
                $line['width_cm'] ?? 0,
                $line['height_cm'] ?? 0,
            ) * $qty;
        }

        return max($actual, $volumetric, 1000);
    }
}
