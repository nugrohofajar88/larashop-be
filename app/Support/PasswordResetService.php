<?php

namespace App\Support;

use App\Models\User;
use App\Support\Contracts\WhatsappGateway;
use Illuminate\Support\Facades\Cache;

/**
 * Reset password customer via kode OTP WhatsApp — bukan email, karena
 * mayoritas akun customer (dibuat otomatis dari order WA) tidak isi email
 * sama sekali, sementara nomor HP WAJIB diisi baik dari WA maupun daftar
 * manual di web (lihat AuthController::register()), jadi satu mekanisme
 * ini cukup untuk semua customer terlepas dari asal pendaftarannya.
 *
 * State OTP di Cache per user_id (bukan tabel password_reset_tokens bawaan
 * Laravel — itu diindeks per email, sedangkan identitas utama kita phone).
 */
class PasswordResetService
{
    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    private const COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly WhatsappGateway $wablas,
    ) {
    }

    /**
     * Cari akun customer dari username/HP/email, kirim OTP ke nomor HP yang
     * TERDAFTAR (bukan yang diketik ulang di form ini) — supaya tidak bisa
     * dipakai membajak akun orang lain dengan memasukkan nomor HP sendiri.
     * Tidak pernah melempar error kalau akun tak ditemukan (cegah user
     * enumeration) — pemanggil selalu balas pesan generik ke customer.
     */
    public function requestOtp(string $login): void
    {
        $user = $this->findUser($login);

        if ($user === null) {
            return;
        }

        $phone = $this->normalizePhone((string) $user->phone);

        if ($phone === '') {
            return;
        }

        // Cegah spam kirim OTP berulang ke nomor yang sama.
        $cooldownKey = $this->cooldownKey($user->id);
        if (Cache::has($cooldownKey)) {
            return;
        }
        Cache::put($cooldownKey, true, now()->addSeconds(self::COOLDOWN_SECONDS));

        $otp = (string) random_int(100000, 999999);

        Cache::put($this->otpKey($user->id), [
            'otp' => $otp,
            'attempts' => 0,
        ], now()->addMinutes(self::TTL_MINUTES));

        $this->wablas->sendMessage(
            $phone,
            "🔑 *Kode Reset Password Sobat Akar Tani Kimia*\n\n"
            ."Kode OTP kamu: *{$otp}*\n\n"
            ."Berlaku ".self::TTL_MINUTES." menit. Jangan berikan kode ini ke siapa pun, "
            ."termasuk yang mengaku dari admin toko. Abaikan pesan ini kalau bukan kamu yang meminta."
        );
    }

    /**
     * @return array{ok:bool,message?:string}
     */
    public function resetPassword(string $login, string $otp, string $password): array
    {
        $user = $this->findUser($login);
        $invalidResult = ['ok' => false, 'message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'];

        if ($user === null) {
            return $invalidResult;
        }

        $session = Cache::get($this->otpKey($user->id));

        if ($session === null) {
            return $invalidResult;
        }

        if ((int) $session['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($this->otpKey($user->id));

            return ['ok' => false, 'message' => 'Terlalu banyak percobaan salah. Minta kode OTP baru.'];
        }

        if (! hash_equals($session['otp'], trim($otp))) {
            $session['attempts']++;
            Cache::put($this->otpKey($user->id), $session, now()->addMinutes(self::TTL_MINUTES));

            return ['ok' => false, 'message' => 'Kode OTP salah.'];
        }

        // Password di-hash otomatis lewat cast 'hashed' di model User.
        $user->forceFill(['password' => $password])->save();
        Cache::forget($this->otpKey($user->id));

        return ['ok' => true];
    }

    protected function findUser(string $login): ?User
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        return User::query()
            ->where('role', 'customer')
            ->where(function ($query) use ($login): void {
                $query->where('username', $login)
                    ->orWhere('phone', $login)
                    ->orWhere('email', $login);
            })
            ->first();
    }

    /** Normalisasi nomor ke format internasional: "08xx" -> "628xx". */
    protected function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if ($digits !== '' && str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }

        return $digits;
    }

    protected function otpKey(int $userId): string
    {
        return "password_reset_otp:{$userId}";
    }

    protected function cooldownKey(int $userId): string
    {
        return "password_reset_cooldown:{$userId}";
    }
}
