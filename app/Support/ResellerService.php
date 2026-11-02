<?php

namespace App\Support;

use App\Models\Reseller;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pendaftaran reseller & pengikatan kode referral ke pembeli. Pengikatan
 * bersifat PERMANEN sekali terjadi (first-touch wins) - sesuai desain: begitu
 * seorang pembeli tertaut ke satu reseller, semua transaksi pembeli itu
 * selanjutnya tetap menghasilkan komisi untuk reseller yang sama.
 */
class ResellerService
{
    public function applyForReseller(User $user): Reseller
    {
        if ($user->resellerProfile()->exists()) {
            throw ValidationException::withMessages([
                'reseller' => 'Kamu sudah pernah mendaftar sebagai reseller.',
            ]);
        }

        return Reseller::query()->create([
            'user_id' => $user->id,
            'referral_code' => $this->generateReferralCode($user),
            'commission_type' => Setting::resellerDefaultCommissionType(),
            'commission_value' => Setting::resellerDefaultCommissionValue(),
            'status' => 'pending',
        ]);
    }

    public function generateReferralCode(User $user): string
    {
        $base = strtoupper(Str::slug($user->username ?: $user->name, ''));
        $base = $base !== '' ? substr($base, 0, 10) : 'RESELLER';

        for ($i = 0; $i < 20; $i++) {
            $code = $i === 0 ? $base : $base.random_int(10, 999);

            if (! Reseller::query()->where('referral_code', $code)->exists()) {
                return $code;
            }
        }

        return $base.Str::upper(Str::random(6));
    }

    /** Ikat pembeli ke reseller via kode referral - sekali saja, permanen (first-touch wins). */
    public function bindReferral(User $user, string $referralCode): bool
    {
        if ($user->reseller_id !== null) {
            return false;
        }

        $reseller = Reseller::query()
            ->where('referral_code', strtoupper(trim($referralCode)))
            ->where('status', 'active')
            ->first();

        if ($reseller === null || $reseller->user_id === $user->id) {
            return false;
        }

        $user->update(['reseller_id' => $reseller->id]);

        return true;
    }
}
